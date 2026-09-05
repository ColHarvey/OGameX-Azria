<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Replay\BattleResultCodec;
use OGame\Combat\Services\OpeningStateRecorder;
use OGame\Combat\Services\RallyClosureService;
use OGame\Models\CombatInstance;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;

/**
 * Un missile qui frappe pendant le ralliement, et la file que son gestionnaire draine au passage.
 *
 * ## Le defaut que cet essai etablit
 *
 * `MissileMission::processArrival()` appelle `$corps->update()` avant de calculer sa destruction, et
 * `update()` **draine la file echue**. La fermeture mesure le delta d'un effet en lisant l'effectif du
 * corps avant et apres l'appel du gestionnaire : si la file est drainee **a l'interieur** de cette
 * fenetre, ses unites entrent dans le delta.
 *
 * Les consequences sont deux, et les deux sont fausses :
 *
 * - l'apport de la file **admissible** est compte deux fois — une par le delta, une par la
 *   photographie qui l'ajoute de son cote ;
 * - l'apport de la file **inadmissible** entre dans la photographie par le delta, alors que tout le
 *   contrat causal existe pour l'en tenir dehors.
 *
 * D'ou l'ordre que `ClosureReconciliation::drainTheQueuesFirst()` impose : les files sont drainees
 * **avant** toute mesure, et ne tombent donc dans la fenetre d'aucun gestionnaire.
 *
 * ## Ce que le missile ne prouve pas
 *
 * Il reste **non lineaire** : il detruit dans un tas qui contient aussi les unites inadmissibles. Le
 * delta mesure est la perte reelle du monde, pas la perte qu'aurait subie la seule photographie. Cet
 * essai dimensionne son scenario pour que la difference ne se voie pas — assez de defenses pour que
 * rien ne soit rogne — et **ne conclut donc rien** sur le cas ou le missile detruirait plus que la
 * photographie ne porte. Ce cas-la reste ouvert, et il est nomme au journal.
 */
final class PhotographedMissileTest extends FleetDispatchTestCase
{
    use OpensARallyWithAWindow;

    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

    /**
     * Assez de defenses pour que le missile ne rogne jamais la photographie : la non-linearite du
     * gestionnaire resterait invisible ici, et cet essai ne pretend pas la couvrir.
     */
    private const int GARRISON = 200;

    private const int ADMISSIBLE = 40;

    private const int INADMISSIBLE = 25;

    /** Un missile : 12 000 de puissance, 200 d'armure par lance-missiles sans technologie. */
    private const int DESTROYED_BY_ONE_MISSILE = 60;

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

    /**
     * La file admissible est comptee **une fois**, et le missile retire ce qu'il a vraiment retire.
     */
    public function testTheDrainedQueueIsNotCountedTwiceThroughTheMissileDelta(): void
    {
        [$combat, $cible, $ouverture] = $this->aRallyStruckByAMissile(withIneligibleQueue: false);

        $this->assertSame(
            self::GARRISON + self::ADMISSIBLE - self::DESTROYED_BY_ONE_MISSILE,
            $this->defenderStartOf($combat, 'rocket_launcher'),
            'The battle was fought against a garrison that counted the drained queue twice.'
        );

        // Sans file inadmissible, photographie et monde disent la meme chose : la fermeture a bien
        // applique ce qu'elle a compte, et n'a compte que ce qu'elle a applique.
        $this->assertSame(
            $this->garrisonOf($cible, 'rocket_launcher'),
            $this->defenderStartOf($combat, 'rocket_launcher'),
            'The photograph and the body disagree although every effect of this closure was eligible.'
        );
    }

    /**
     * La file **inadmissible**, drainee par le meme gestionnaire, n'entre pas dans la bataille.
     *
     * C'est le temoin qui separe les deux ordres : draine apres la mesure, elle entrerait dans le
     * delta du missile, et donc dans la photographie, sans qu'aucune barriere ne l'ait admise.
     */
    public function testTheIneligibleQueueDoesNotEnterTheBattleThroughTheMissileDelta(): void
    {
        [$combat, $cible, $ouverture] = $this->aRallyStruckByAMissile(withIneligibleQueue: true);

        $this->assertSame(
            self::GARRISON + self::ADMISSIBLE - self::DESTROYED_BY_ONE_MISSILE,
            $this->defenderStartOf($combat, 'rocket_launcher'),
            'Units decided after the opening fought in this battle: the missile handler carried them in.'
        );

        // Le monde, lui, porte les deux files moins ce que le missile a detruit. **Photographie et
        // monde different ici** : sans cet ecart, l'essai passerait aussi bien sans photographie.
        $this->assertSame(
            self::GARRISON + self::ADMISSIBLE + self::INADMISSIBLE - self::DESTROYED_BY_ONE_MISSILE,
            $this->garrisonOf($cible, 'rocket_launcher'),
            'The body does not carry both drained queues minus what the missile destroyed.'
        );
        $this->assertNotSame(
            $this->garrisonOf($cible, 'rocket_launcher'),
            $this->defenderStartOf($combat, 'rocket_launcher'),
            'The photograph and the living body agree here: this test would pass without the photograph.'
        );
    }

    /**
     * Le second ordre : le monde applique le missile **pendant le ralliement**, avant la fermeture.
     *
     * C'est le cas que la revue 89 nomme : un gestionnaire idempotent rejoue a vide, et une mesure
     * avant/apres autour de lui donne zero, pas le delta historique. La photographie doit pourtant
     * porter la destruction — les deux ordres donnent la meme bataille, ou le contrat est faux.
     */
    public function testAMissileTheWorldAppliedDuringTheRallyIsReflectedInThePhotograph(): void
    {
        [$combat, $cible, $ouverture] = $this->anOpenRally(self::GARRISON);
        $missile = $this->aPendingMissileTowards($cible, $ouverture - 50, $ouverture + 8);

        // Le monde passe : le proprietaire du missile charge une page a l'arrivee, et le travailleur
        // livre la frappe par la porte, comme en production.
        $this->travelTo(Date::createFromTimestamp($ouverture + 8));
        $this->get('/overview')->assertStatus(200);
        $this->assertSame(1, (int)DB::table('fleet_missions')->where('id', $missile->id)->value('processed'), 'The world did not apply the missile at its arrival: the scenario is not the one this test names.');
        $this->assertSame(self::GARRISON - self::DESTROYED_BY_ONE_MISSILE, $this->garrisonOf($cible, 'rocket_launcher'), 'The missile did not destroy what this test computes.');

        $fermeture = $ouverture + self::RALLY_WINDOW_SECONDS + 1;
        $this->travelTo(Date::createFromTimestamp($fermeture));
        $this->assertTrue((new RallyClosureService())->close($combat->id, $fermeture)->closed, 'The rally did not close.');

        $this->assertSame(
            self::GARRISON - self::DESTROYED_BY_ONE_MISSILE,
            $this->defenderStartOf($combat, 'rocket_launcher'),
            'The battle was fought against defences a missile destroyed before the closure: the effect the world applied was replayed empty and measured as zero.'
        );
        $this->assertSame($this->garrisonOf($cible, 'rocket_launcher'), $this->defenderStartOf($combat, 'rocket_launcher'), 'Photograph and body disagree although every effect of this closure was eligible.');
    }

    /**
     * Un missile traite **avant l'ouverture** n'a pas de ligne au registre, et n'en a pas besoin :
     * l'etat d'ouverture reflete deja sa destruction. La fermeture ne la retire pas une seconde fois.
     */
    public function testAMissileAppliedBeforeTheOpeningIsReflectedOnceAndNotSubtractedAgain(): void
    {
        [$combat, $cible, $ouverture] = $this->anOpenRally(self::GARRISON);

        // Frappe et traite dix secondes avant l'ouverture : le corps portait deja 140 quand le combat
        // s'est ouvert, et l'etat d'ouverture est recapture sur ce corps-la.
        $missile = $this->aPendingMissileTowards($cible, $ouverture - 50, $ouverture - 10);
        DB::table('fleet_missions')->where('id', $missile->id)->update(['processed' => 1]);
        DB::table('planets')->where('id', $cible)->update(['rocket_launcher' => self::GARRISON - self::DESTROYED_BY_ONE_MISSILE]);
        (new OpeningStateRecorder())->capture($combat, $cible, $ouverture);
        $this->assertSame(0, DB::table('combat_effect_ledger')->where('combat_instance_id', $combat->id)->count(), 'The ledger holds a line for an effect applied before the opening.');

        $fermeture = $ouverture + self::RALLY_WINDOW_SECONDS + 1;
        $this->travelTo(Date::createFromTimestamp($fermeture));
        $this->assertTrue((new RallyClosureService())->close($combat->id, $fermeture)->closed, 'The rally did not close.');

        $this->assertSame(
            self::GARRISON - self::DESTROYED_BY_ONE_MISSILE,
            $this->defenderStartOf($combat, 'rocket_launcher'),
            'A missile applied before the opening was subtracted a second time, or its destruction was lost.'
        );
    }

    /**
     * Un ralliement ouvert, une garnison large, une file admissible, un missile admissible — et, au
     * choix, une file decidee apres l'ouverture que le gestionnaire du missile drainera aussi.
     *
     * @return array{0: CombatInstance, 1: int, 2: int}
     */
    private function aRallyStruckByAMissile(bool $withIneligibleQueue): array
    {
        [$combat, $cible, $ouverture] = $this->anOpenRally(self::GARRISON);

        $this->aUnitQueue($cible, 'rocket_launcher', self::ADMISSIBLE, $ouverture - 100, $ouverture + 5);
        if ($withIneligibleQueue) {
            $this->aUnitQueue($cible, 'rocket_launcher', self::INADMISSIBLE, $ouverture + 1, $ouverture + 6);
        }
        $this->aPendingMissileTowards($cible, $ouverture - 50, $ouverture + 8);

        $fermeture = $ouverture + self::RALLY_WINDOW_SECONDS + 1;
        $this->travelTo(Date::createFromTimestamp($fermeture));
        $this->assertTrue((new RallyClosureService())->close($combat->id, $fermeture)->closed, 'The rally did not close.');

        return [$combat, $cible, $ouverture];
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
