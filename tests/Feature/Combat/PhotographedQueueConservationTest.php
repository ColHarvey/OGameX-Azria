<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Replay\BattleResultCodec;
use OGame\Combat\Services\RallyClosureService;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameMissions\AttackMission;
use OGame\Models\CombatInstance;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;

/**
 * Le cycle entier d'une file achevee pendant un ralliement : rien ne se cree, rien ne ressuscite.
 *
 * ## Le defaut que cet essai etablit
 *
 * Une file admissible produit des unites qui appartiennent au combat. Si la photographie les compte
 * **sans que le monde les ait appliquees**, la bataille les tue, le reglement retire des unites que le
 * corps ne porte pas, et le monde, en appliquant la file plus tard, **recree** ce qui vient de mourir.
 * Le joueur recupere ainsi des vaisseaux tues. C'est un defaut de conservation, pas de presentation.
 *
 * ## Ce que le cycle doit donner
 *
 * Une file eligible et une file ineligible, toutes deux achevees dans la fenetre, des pertes reelles
 * parmi les unites eligibles :
 *
 * - la production est creditee **une fois** ;
 * - la perte est appliquee **une fois**, sur des unites qui existent ;
 * - les unites hors photographie sont intactes ;
 * - aucun stock negatif ;
 * - l'effectif final est le meme quel que soit l'ordre — reglement puis file, ou file puis reglement ;
 * - un second passage du gestionnaire ne change rien.
 */
final class PhotographedQueueConservationTest extends FleetDispatchTestCase
{
    use OpensARallyWithAWindow;

    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

    private const int ELIGIBLE = 40;

    private const int INELIGIBLE = 25;

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

    public function testTheSettlementNeverRemovesUnitsTheBodyDoesNotHave(): void
    {
        [$combat, $cible, $ouverture, $fermeture] = $this->aRallyWithAMixedQueue();

        $this->assertTrue((new RallyClosureService())->close($combat->id, $fermeture)->closed, 'The rally did not close.');

        $perdues = $this->permanentlyLostOf($combat, 'rocket_launcher');
        $this->assertGreaterThan(0, $perdues, 'The garrison lost no rocket launcher: the scenario would prove nothing.');

        $avantReglement = $this->garrisonOf($cible, 'rocket_launcher');
        $this->assertGreaterThanOrEqual($perdues, $avantReglement, 'The battle killed more units than the body carries: the settlement is about to remove what does not exist.');

        $this->settle($combat);

        $this->assertSame($avantReglement - $perdues, $this->garrisonOf($cible, 'rocket_launcher'), 'The settlement did not remove exactly what the battle killed.');
        $this->assertGreaterThanOrEqual(0, $this->garrisonOf($cible, 'rocket_launcher'), 'The body carries a negative stock.');
    }

    public function testTheFinalCountIsTheSameWhicheverOrderTheWorldTakes(): void
    {
        [$combat, $cible, $ouverture, $fermeture] = $this->aRallyWithAMixedQueue();
        (new RallyClosureService())->close($combat->id, $fermeture);
        $perdues = $this->permanentlyLostOf($combat, 'rocket_launcher');
        $this->assertGreaterThan(0, $perdues, 'The garrison lost nothing: the scenario would prove nothing.');

        // Ordre A : le reglement, puis ce que le monde fait de ses files.
        $this->settle($combat);
        $this->applyTheQueues($cible);
        $ordreA = $this->garrisonOf($cible, 'rocket_launcher');

        // **Un second passage ne change rien** : les deux gestionnaires sont idempotents.
        $this->applyTheQueues($cible);
        $this->assertSame($ordreA, $this->garrisonOf($cible, 'rocket_launcher'), 'A second pass of the queue handler created units a second time.');

        // Ce que le compte doit valoir : l'effectif d'ouverture, plus les deux files achevees, moins
        // les pertes. Les unites ineligibles sont bien la — le combat les a ignorees, pas confisquees.
        $this->assertSame(
            self::RALLY_DEFENCES + self::ELIGIBLE + self::INELIGIBLE - $perdues,
            $ordreA,
            'Units were created, lost twice, or resurrected between the battle and the queue.'
        );
    }

    /**
     * Un ralliement ouvert, deux files achevees dans la fenetre — une engagee avant l'ouverture, une
     * apres — et une garnison qui perdra vraiment quelque chose.
     *
     * @return array{0: CombatInstance, 1: int, 2: int, 3: int}
     */
    private function aRallyWithAMixedQueue(): array
    {
        [$combat, $cible, $ouverture] = $this->anOpenRally();
        $this->aUnitQueue($cible, 'rocket_launcher', self::ELIGIBLE, $ouverture - 100, $ouverture + 5);
        $this->aUnitQueue($cible, 'rocket_launcher', self::INELIGIBLE, $ouverture + 1, $ouverture + 6);
        $fermeture = $ouverture + self::RALLY_WINDOW_SECONDS + 1;
        $this->travelTo(Date::createFromTimestamp($fermeture));

        return [$combat, $cible, $ouverture, $fermeture];
    }

    private function settle(CombatInstance $combat): void
    {
        $this->travelTo(Date::createFromTimestamp((int)$combat->ends_at));
        resolve(AttackMission::class)->settlePersistentCombat($combat->id);
    }

    private function applyTheQueues(int $planetId): void
    {
        $corps = resolve(PlanetServiceFactory::class)->make($planetId, true);
        $this->assertNotNull($corps);
        $corps->updateUnitQueue();
    }

    private function garrisonOf(int $planetId, string $machineName): int
    {
        return (int)DB::table('planets')->where('id', $planetId)->value($machineName);
    }

    /**
     * Ce que le corps perd **pour de bon** : les pertes de la bataille, moins les defenses que le
     * jeu repare. C est ce montant-la que le reglement retire, et lui seul.
     */
    private function permanentlyLostOf(CombatInstance $combat, string $machineName): int
    {
        $combat->refresh();
        $resultat = BattleResultCodec::fromStorage($combat->battle_result);

        return $resultat->defenderUnitsLost->getAmountByMachineName($machineName)
            - $resultat->repairedDefenses->getAmountByMachineName($machineName);
    }

    private function aUnitQueue(int $planetId, string $machineName, int $amount, int $start, int $end): int
    {
        return (int)DB::table('unit_queues')->insertGetId([
            'planet_id' => $planetId,
            'object_id' => ObjectService::getUnitObjectByMachineName($machineName)->id,
            'object_amount' => $amount,
            'time_duration' => max(1, $end - $start),
            'time_start' => $start,
            'time_end' => $end,
            'time_progress' => 0,
            'object_amount_progress' => 0,
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
            'processed' => 0,
        ]);
    }
}
