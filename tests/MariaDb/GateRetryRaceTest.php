<?php

namespace Tests\MariaDb;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Services\FleetMovementGate;
use OGame\Models\CelestialBodyCombatBarrier;
use OGame\Models\CombatInstance;
use OGame\Models\FleetMission;
use OGame\Models\FleetUnion;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Models\User;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * La reprise de la porte des mouvements sous une vraie course : un lien change **pendant** que la
 * porte attend la barriere qu'un autre processus tient.
 *
 * ## Ce que l'essai SQLite ne pouvait pas jouer
 *
 * Sous SQLite, la reprise est provoquee par un objet perime passe a la porte : le lien avait change
 * avant l'entree. Ici, l'enfant lit une mission **a jour**, entre dans la porte, et bute sur la
 * barriere que le parent tient sur une connexion a part. Le parent attend de voir cette attente dans
 * `information_schema.INNODB_TRX`, lie alors la mission a une union neuve, et relache la barriere.
 * L'enfant relit la mission sous verrou, trouve un lien qu'il ne tient pas, recommence depuis la
 * barriere, et decide en tenant l'union. La barriere a ete demandee deux fois : c'est la reprise.
 *
 * Le temoin inverse : une mission relue apres le lien n'a rien a reprendre — une seule barriere.
 */
#[Group('mariadb')]
final class GateRetryRaceTest extends TestCase
{
    use RunsInParallelProcesses;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requiresMariaDb();
        $this->requiresProcesses();

        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
    }

    protected function tearDown(): void
    {
        DB::purge('mysql_temoin');
        parent::tearDown();
    }

    public function testALinkChangedWhileTheGateWaitedOnTheBarrierIsHeldOnTheRetry(): void
    {
        [$combat, $mission, $corps] = $this->aRallyingCombatAndAFleet();
        $this->aBarrierOver($corps, $combat);

        // Le parent tient la barriere sur une connexion a part, que la bifurcation ne ferme pas.
        config(['database.connections.mysql_temoin' => config('database.connections.mysql')]);
        $temoin = DB::connection('mysql_temoin');
        $temoin->beginTransaction();
        $this->assertNotNull($temoin->table('celestial_body_combat_barriers')->where('target_body_id', $corps->id)->lockForUpdate()->first());

        $union = null;
        $issues = $this->inParallel(
            1,
            static fn (): string => self::decideThroughTheGate((int)$mission->id),
            function () use ($mission, $temoin, &$union): void {
                // L'enfant a lu sa mission a jour et attend la barriere : maintenant le lien change.
                $this->waitUntilAProcessWaitsOnALock();
                $union = self::aUnionFor($mission);
                DB::table('fleet_missions')->where('id', $mission->id)->update(['union_id' => $union->id]);
                $temoin->commit();
            }
        );

        $this->assertNotNull($union);
        $this->assertSame('union:' . $union->id . ';barrieres:2', $issues[0], 'The gate did not retry from the barrier with the link that changed while it waited.');
    }

    public function testAMissionReadAfterTheLinkChangedNeedsNoRetry(): void
    {
        [$combat, $mission, $corps] = $this->aRallyingCombatAndAFleet();
        $this->aBarrierOver($corps, $combat);
        $union = self::aUnionFor($mission);
        DB::table('fleet_missions')->where('id', $mission->id)->update(['union_id' => $union->id]);

        $issues = $this->inParallel(1, static fn (): string => self::decideThroughTheGate((int)$mission->id));

        $this->assertSame('union:' . $union->id . ';barrieres:1', $issues[0], 'A mission read after its link changed should hold the union on the first pass.');
    }

    /**
     * La decision d'un enfant : la mission relue a jour, la porte, et le nombre de fois ou la
     * barriere a ete demandee sous verrou — une fois sans reprise, deux avec.
     */
    private static function decideThroughTheGate(int $missionId): string
    {
        $barrieres = 0;
        DB::listen(static function (QueryExecuted $requete) use (&$barrieres): void {
            $sql = str_replace('`', '"', $requete->sql);
            if (str_contains($sql, '"celestial_body_combat_barriers"') && str_contains($sql, 'for update')) {
                $barrieres++;
            }
        });

        $aJour = FleetMission::query()->findOrFail($missionId);
        $vu = (new FleetMovementGate())->decideUnderLock(
            $aJour,
            static fn (FleetMission $tenue): int|null => $tenue->union_id === null ? null : (int)$tenue->union_id
        );

        return 'union:' . ($vu ?? 'aucune') . ';barrieres:' . $barrieres;
    }

    /**
     * @return array{0: CombatInstance, 1: FleetMission, 2: Planet}
     */
    private function aRallyingCombatAndAFleet(): array
    {
        $joueur = User::factory()->create();
        $origine = $this->aBodyOf($joueur);
        $corps = $this->aBodyOf(User::factory()->create());

        $mission = FleetMission::forceCreate([
            'user_id' => $joueur->id,
            'planet_id_from' => $origine->id,
            'type_from' => 1,
            'planet_id_to' => $corps->id,
            'type_to' => 1,
            'galaxy_to' => $corps->galaxy,
            'system_to' => $corps->system,
            'position_to' => $corps->planet,
            'mission_type' => 5,
            'time_departure' => 1_700_000_000,
            'time_arrival' => 1_700_000_600,
            'time_holding' => 300,
            'light_fighter' => 10,
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
        ]);

        $combat = CombatInstance::create([
            'status' => CombatState::Rallying,
            'mission_id' => $mission->id,
            'target_planet_id' => $corps->id,
            'target_type' => 1,
            'galaxy' => $corps->galaxy,
            'system' => $corps->system,
            'position' => $corps->planet,
            'started_at' => 1_700_000_000,
        ]);

        return [$combat, $mission, $corps];
    }

    private function aBarrierOver(Planet $corps, CombatInstance $combat): void
    {
        CelestialBodyCombatBarrier::query()->create([
            'target_body_id' => $corps->id,
            'combat_instance_id' => $combat->id,
            'opened_at' => 1_700_000_000,
            'owned_through_effect_at' => 1_700_000_600,
        ]);
    }

    private function aBodyOf(User $owner): Planet
    {
        $coordonnees = $this->getSafeEmptyCoordinate(new Coordinate(9, random_int(20, 480), 1));

        return Planet::factory()->create([
            'user_id' => $owner->id,
            'galaxy' => $coordonnees->galaxy,
            'system' => $coordonnees->system,
            'planet' => $coordonnees->position,
        ]);
    }

    private static function aUnionFor(FleetMission $mission): FleetUnion
    {
        return FleetUnion::create([
            'user_id' => $mission->user_id,
            'name' => null,
            'galaxy_to' => $mission->galaxy_to,
            'system_to' => $mission->system_to,
            'position_to' => $mission->position_to,
            'planet_type_to' => $mission->type_to,
            'time_arrival' => $mission->time_arrival,
            'max_fleets' => 16,
            'max_players' => 5,
        ]);
    }
}
