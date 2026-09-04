<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Services\CombatOpeningService;
use OGame\Combat\Services\PersistentCombatAdvancer;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Jobs\SettlePersistentCombat;
use OGame\Models\BattleReport;
use OGame\Models\CombatInstance;
use OGame\Models\FleetMission;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;

/**
 * Le reglement est programme a l'echeance, et la minute ne sert plus qu'a rattraper.
 *
 * ## Ce que ces essais protegent
 *
 * Le planificateur de Laravel ne descend pas sous la minute. Une bataille de cinq secondes gardait
 * donc son corps verrouille pres d'une minute de plus que sa duree — l'attente exacte que la fenetre
 * dynamique existe pour supprimer. La cloture programme desormais un travail **date de l'echeance**,
 * dans sa propre transaction.
 *
 * Le passage minute reste, et c'est voulu : une file perd un message, un travailleur s'arrete, une
 * base est restauree. Les deux chemins passent par la meme frontiere, qui refuse un combat deja
 * regle — un combat ne se regle jamais deux fois, quel que soit celui qui arrive le premier.
 */
class ScheduledSettlementTest extends FleetDispatchTestCase
{
    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('fleet_missions')->whereNotNull('combat_instance_id')->update(['combat_instance_id' => null]);

        foreach ([
            'combat_snapshot_inclusions',
            'combat_outbox',
            'combat_participants',
            'combat_effect_receipts',
            'combat_loot_reservations',
            'celestial_body_combat_barriers',
            'combat_instances',
        ] as $table) {
            DB::table($table)->delete();
        }
    }

    protected function basicSetup(): void
    {
        $this->planetAddUnit('small_cargo', 200);
        $this->planetAddUnit('light_fighter', 900);
        $this->playerSetResearchLevel('computer_technology', object_level: 2);

        $settingsService = resolve(SettingsService::class);
        $settingsService->set('economy_speed', 8);
        $settingsService->set('fleet_speed_war', 1);
        $settingsService->set('fleet_speed_holding', 1);
        $settingsService->set('fleet_speed_peaceful', 1);
        $settingsService->set('attack_block_until', 0);

        $this->planetAddResources(new Resources(0, 0, 1_000_000, 0));
    }

    protected function messageCheckMissionArrival(): void
    {
    }

    protected function messageCheckMissionReturn(): void
    {
    }

    /**
     * La cloture programme un reglement date de l'echeance, et de rien d'autre.
     */
    public function testTheClosureSchedulesASettlementAtTheDeadline(): void
    {
        DB::table('jobs')->delete();

        [$combat] = $this->anEngagedCombat();

        // **La ligne est lue en base, pas sur un faux.** C est aussi ce qui prouve que le travail
        // vit dans la transaction de cloture : une cloture annulee l emporterait avec elle.
        $travaux = DB::table('jobs')->get()->all();
        $this->assertCount(1, $travaux, 'The closure did not schedule exactly one settlement.');

        $charge = json_decode((string)$travaux[0]->payload, true);
        $this->assertIsArray($charge);
        $this->assertSame(SettlePersistentCombat::class, $charge['displayName'] ?? null);
        $this->assertSame((int)$combat->ends_at, (int)$travaux[0]->available_at, 'The settlement is not scheduled at the deadline.');

        $travail = unserialize((string)($charge['data']['command'] ?? ''));
        $this->assertInstanceOf(SettlePersistentCombat::class, $travail);
        $this->assertSame($combat->id, $travail->combatInstanceId, 'The scheduled work names another combat.');
    }

    /**
     * Le travail programme regle le combat : le rapport existe, la flotte rentre, le corps se libere.
     */
    public function testTheScheduledWorkSettlesTheCombat(): void
    {
        [$combat, $mission] = $this->anEngagedCombat();

        $this->travelTo(Date::createFromTimestamp((int)$combat->ends_at));
        (new SettlePersistentCombat($combat->id))->handle();

        $combat->refresh();
        $this->assertSame(CombatState::Resolved, $combat->status, 'The scheduled work did not settle the combat.');
        $this->assertNotNull($combat->battle_report_id);
        $this->assertSame(1, FleetMission::query()->where('parent_id', $mission->id)->count(), 'The fleet did not come home.');
    }

    /**
     * Le passage minute et le travail programme ne reglent jamais deux fois.
     *
     * Celui qui arrive le second trouve un combat deja regle : ni second rapport, ni second retour.
     */
    public function testTheSweepAndTheScheduledWorkNeverSettleTwice(): void
    {
        [$combat, $mission] = $this->anEngagedCombat();

        $this->travelTo(Date::createFromTimestamp((int)$combat->ends_at));
        $this->assertSame(1, (new PersistentCombatAdvancer())->advance((int)$combat->ends_at)->settled, 'The sweep did not settle the combat.');

        $rapports = BattleReport::query()->count();

        (new SettlePersistentCombat($combat->id))->handle();

        $this->assertSame($rapports, BattleReport::query()->count(), 'The scheduled work wrote a second report.');
        $this->assertSame(1, FleetMission::query()->where('parent_id', $mission->id)->count(), 'The scheduled work created a second return.');
    }

    /**
     * Un travail programme qui echoue se tait et laisse le passage reprendre.
     *
     * Le compteur d essais et la mise de cote vivent dans le passage planifie. Si la file reessayait
     * de son cote, un meme incident aurait deux mecanismes de reprise, deux comptages, et deux
     * facons de finir en quarantaine — ou de n y jamais finir.
     */
    public function testAScheduledWorkThatFailsStaysQuietAndLeavesTheSweepToRetry(): void
    {
        [$combat] = $this->anEngagedCombat();

        // Le resultat fige ne se relit plus : le reglement leve, quel que soit celui qui l appelle.
        DB::table('combat_instances')->where('id', $combat->id)->update(['battle_result' => '{"schema":99}']);

        $this->travelTo(Date::createFromTimestamp((int)$combat->ends_at));
        (new SettlePersistentCombat($combat->id))->handle();

        $combat->refresh();
        $this->assertSame(CombatState::Active, $combat->status, 'A failing scheduled work left the combat half settled.');
        $this->assertSame(0, (int)$combat->advance_attempts, 'The scheduled work counted a failure: the sweep is the only counter.');

        // Et le passage, lui, compte et reprendra.
        $avance = (new PersistentCombatAdvancer())->advance((int)$combat->ends_at);
        $this->assertArrayHasKey($combat->id, $avance->failures, 'The sweep did not pick the failing combat up.');
        $this->assertSame(1, (int)$combat->refresh()->advance_attempts);
    }

    /**
     * Un travail programme dont le combat a disparu ne fait rien de bruyant.
     *
     * Une base restauree, une administration qui efface : le travail reste en file et arrive apres.
     */
    public function testAScheduledWorkForAVanishedCombatDoesNothing(): void
    {
        (new SettlePersistentCombat(999_999))->handle();

        $this->assertSame(0, CombatInstance::query()->count());
    }

    /**
     * Un combat ouvert, clos et engage : la fenetre est nulle, personne n'est attendu.
     *
     * @return array{0: CombatInstance, 1: FleetMission, 2: PlanetService}
     */
    private function anEngagedCombat(): array
    {
        for ($i = 0; $i < 6; $i++) {
            $this->createAndLoginUser();
        }

        $this->basicSetup();

        $units = new UnitCollection();
        $units->addUnit(ObjectService::getUnitObjectByMachineName('small_cargo'), 50);
        $units->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 350);

        $cible = $this->sendMissionToOtherPlayerPlanet($units, new Resources(0, 0, 0, 0));

        $mission = FleetMission::query()
            ->where('processed', 0)
            ->where('user_id', $this->currentUserId)
            ->orderByDesc('id')
            ->first();

        if ($mission === null) {
            $this->fail('No fleet mission was dispatched.');
        }

        DB::table('planets')->where('id', $cible->getPlanetId())->update([
            'metal' => 500_000,
            'crystal' => 300_000,
            'deuterium' => 100_000,
            'rocket_launcher' => 20,
            'time_last_update' => (int)now()->timestamp + 86_400,
        ]);
        $cible->reloadPlanet();

        $combat = (new CombatOpeningService())->openOrJoin($mission, $cible->getPlanetId(), (int)$mission->time_arrival);
        $combat->refresh();

        $this->assertSame(CombatState::Active, $combat->status, 'A lone attacker did not close its own rally.');
        $this->assertNotNull($combat->ends_at);

        return [$combat, $mission, $cible];
    }
}
