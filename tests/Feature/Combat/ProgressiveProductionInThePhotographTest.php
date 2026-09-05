<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Replay\BattleResultCodec;
use OGame\Combat\Services\OpeningStateRecorder;
use OGame\Combat\Services\RallyClosureService;
use OGame\Factories\PlanetServiceFactory;
use OGame\Models\CombatInstance;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;

/**
 * Une file d'unites produit unite par unite, et la photographie doit le savoir.
 *
 * ## Les trois defauts que la revue 92 a lus dans le code
 *
 * `PlanetService::updateUnitQueue()` materialise les unites d'un lot au fil du temps : une unite
 * toutes les `(fin − debut) / quantite` secondes, et le reste a la fin. La photographie comptait un
 * lot comme un bloc, a sa fin :
 *
 * - un lot **acheve et applique avant l'ouverture** etait relu et ajoute une seconde fois : ses
 *   unites sont deja dans l'effectif d'ouverture ;
 * - l'**avancement deja materialise** a l'ouverture (40 unites d'un lot de 100 posees sur le corps)
 *   etait ajoute de nouveau avec le lot entier ;
 * - un lot qui **finit apres la fermeture** n'etait pas lu du tout, alors que ses unites terminees
 *   avant la fermeture sont sur le corps quand la bataille se joue.
 *
 * ## La regle
 *
 * L'apport d'un lot decide avant l'ouverture = les unites terminees **strictement avant** la
 * fermeture, moins celles deja materialisees a l'ouverture (relevees dans l'etat d'ouverture). Une
 * unite qui finit exactement a la fermeture est « apres », comme toute egalite avec une barriere :
 * le monde peut la materialiser au passage de la fermeture, elle reste hors photographie, intacte.
 */
final class ProgressiveProductionInThePhotographTest extends FleetDispatchTestCase
{
    use OpensARallyWithAWindow;

    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

    private const int GARRISON = 200;

    protected function basicSetup(): void
    {
        $this->basicSetupForARally();
    }

    protected function messageCheckMissionArrival(): void
    {
    }

    protected function messageCheckMissionReturn(): void
    {
    }

    protected function tearDown(): void
    {
        resolve(SettingsService::class)->set('persistent_combat_enabled', '0');
        parent::tearDown();
    }

    public function testABatchFinishedAndAppliedBeforeTheOpeningIsNotAddedAgain(): void
    {
        [$combat, $cible, $ouverture] = $this->anOpenRally(self::GARRISON);

        // Fini et applique hier : ses 100 unites font deja partie des 200 de l'ouverture.
        $this->aUnitQueue($cible, 'rocket_launcher', 100, $ouverture - 300, $ouverture - 20, progress: 100, processed: true);
        (new OpeningStateRecorder())->capture($combat, $cible, $ouverture);

        $this->closeAt($combat, $ouverture);

        $this->assertSame(self::GARRISON, $this->defenderStartOf($combat, 'rocket_launcher'), 'A batch finished and applied before the opening was added to the photograph a second time.');
        $this->assertSame(self::GARRISON, $this->garrisonOf($cible, 'rocket_launcher'), 'The body changed although nothing was due.');
    }

    public function testTheProgressAlreadyMaterialisedAtTheOpeningIsNotAddedAgain(): void
    {
        // 40 des 100 unites sont deja sur le corps a l'ouverture : la garnison d'ouverture vaut 240.
        [$combat, $cible, $ouverture] = $this->anOpenRally(self::GARRISON + 40);
        $this->aUnitQueue($cible, 'rocket_launcher', 100, $ouverture - 100, $ouverture + 5, progress: 40);
        (new OpeningStateRecorder())->capture($combat, $cible, $ouverture);

        $this->closeAt($combat, $ouverture);

        $this->assertSame(self::GARRISON + 100, $this->defenderStartOf($combat, 'rocket_launcher'), 'The 40 units already materialised at the opening were added a second time with the whole batch.');
        $this->assertSame(self::GARRISON + 100, $this->garrisonOf($cible, 'rocket_launcher'), 'The world does not carry the whole batch once.');
    }

    public function testUnitsFinishedBeforeTheClosureOfABatchThatEndsAfterItEnterThePhotograph(): void
    {
        [$combat, $cible, $ouverture] = $this->anOpenRally(self::GARRISON);

        // 12 unites, une toutes les 5 s, de −10 a +50 : a la fermeture (+19), cinq sont terminees
        // (−5, 0, +5, +10, +15) ; la sixieme finit a +20.
        $this->aUnitQueue($cible, 'rocket_launcher', 12, $ouverture - 10, $ouverture + 50);
        (new OpeningStateRecorder())->capture($combat, $cible, $ouverture);

        $this->closeAt($combat, $ouverture);

        $this->assertSame(self::GARRISON + 5, $this->defenderStartOf($combat, 'rocket_launcher'), 'The units finished before the closure by a batch that ends after it did not enter the photograph.');
        $this->assertSame(self::GARRISON + 5, $this->garrisonOf($cible, 'rocket_launcher'), 'The world did not materialise exactly the units finished by the closure.');
    }

    /**
     * Le monde a materialise une partie du lot avant la fermeture : rien ne change.
     */
    public function testTheWorldMaterialisingPartOfTheBatchBeforeTheClosureChangesNothing(): void
    {
        [$combat, $cible, $ouverture] = $this->anOpenRally(self::GARRISON);
        $this->aUnitQueue($cible, 'rocket_launcher', 12, $ouverture - 10, $ouverture + 50);
        (new OpeningStateRecorder())->capture($combat, $cible, $ouverture);

        // A +8, le proprietaire passe : trois unites (−5, 0, +5) se posent.
        $this->travelTo(Date::createFromTimestamp($ouverture + 8));
        $corps = resolve(PlanetServiceFactory::class)->make($cible, true);
        $this->assertNotNull($corps);
        $corps->updateUnitQueue();
        $this->assertSame(self::GARRISON + 3, $this->garrisonOf($cible, 'rocket_launcher'), 'The world did not materialise the three units finished by +8.');

        $this->closeAt($combat, $ouverture);

        $this->assertSame(self::GARRISON + 5, $this->defenderStartOf($combat, 'rocket_launcher'), 'A batch partly materialised by the world before the closure gave a different photograph.');
        $this->assertSame(self::GARRISON + 5, $this->garrisonOf($cible, 'rocket_launcher'), 'The world does not carry exactly the units finished by the closure.');
    }

    /**
     * Une unite qui finit **exactement** a la fermeture est apres : hors photographie, intacte.
     */
    public function testAUnitFinishingExactlyAtTheClosureStaysOutOfThePhotograph(): void
    {
        [$combat, $cible, $ouverture] = $this->anOpenRally(self::GARRISON);
        $fermeture = $ouverture + self::RALLY_WINDOW_SECONDS + 1;

        // Deux unites, une toutes les 20 s, de −1 : la premiere finit a +19, la fermeture.
        $this->aUnitQueue($cible, 'rocket_launcher', 2, $ouverture - 1, $ouverture + 39);
        (new OpeningStateRecorder())->capture($combat, $cible, $ouverture);
        $this->assertSame($fermeture, $ouverture - 1 + 20, 'The scenario does not put the first unit exactly at the closure.');

        $this->closeAt($combat, $ouverture);

        $this->assertSame(self::GARRISON, $this->defenderStartOf($combat, 'rocket_launcher'), 'A unit finishing exactly at the closure entered the photograph: an equality with a barrier counts as after.');
        $this->assertGreaterThanOrEqual(self::GARRISON, $this->garrisonOf($cible, 'rocket_launcher'), 'The body lost a unit.');
    }

    private function closeAt(CombatInstance $combat, int $ouverture): void
    {
        $fermeture = $ouverture + self::RALLY_WINDOW_SECONDS + 1;
        $this->travelTo(Date::createFromTimestamp($fermeture));
        $this->assertTrue((new RallyClosureService())->close($combat->id, $fermeture)->closed, 'The rally did not close.');
    }

    private function garrisonOf(int $planetId, string $machineName): int
    {
        return (int)DB::table('planets')->where('id', $planetId)->value($machineName);
    }

    private function defenderStartOf(CombatInstance $combat, string $machineName): int
    {
        $combat->refresh();

        return BattleResultCodec::fromStorage($combat->battle_result)->defenderUnitsStart->getAmountByMachineName($machineName);
    }

    private function aUnitQueue(int $planetId, string $machineName, int $amount, int $start, int $end, int $progress = 0, bool $processed = false): int
    {
        $parUnite = ($end - $start) / $amount;

        return (int)DB::table('unit_queues')->insertGetId([
            'planet_id' => $planetId,
            'object_id' => ObjectService::getUnitObjectByMachineName($machineName)->id,
            'object_amount' => $amount,
            'time_duration' => max(1, $end - $start),
            'time_start' => $start,
            'time_end' => $end,
            'time_progress' => $progress > 0 ? (int)($start + $parUnite * $progress) : 0,
            'object_amount_progress' => $progress,
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
            'processed' => $processed ? 1 : 0,
        ]);
    }
}
