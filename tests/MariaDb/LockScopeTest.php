<?php

namespace Tests\MariaDb;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use OGame\Services\SettingsService;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Combat\EngagesAPersistentCombat;
use Tests\FleetDispatchTestCase;

/**
 * La portee des verrous que le combat tient, mesuree sur MariaDB avec une seconde connexion.
 *
 * ## Pourquoi une seconde connexion suffit ici
 *
 * On ne cherche pas l'issue d'une course, mais la **portee** d'un verrou : ce qu'une autre
 * connexion ne peut pas faire tant qu'il est tenu. La seconde connexion attend une seconde au
 * plus (`innodb_lock_wait_timeout`) : si elle est refusee, le verrou couvrait ce qu'elle tentait ;
 * si elle passe, il ne le couvrait pas. Un temoin inverse, sans verrou tenu, prouve que le refus
 * venait bien du verrou.
 *
 * ## Le fantome
 *
 * La porte des mouvements lit la barriere d'un corps sous `FOR UPDATE`, et peut ne rien trouver.
 * Ce que ce « rien » vaut depend du moteur : sous InnoDB en REPEATABLE READ, une lecture
 * verrouillante sur une valeur absente d'un index unique tient **l'intervalle** ou elle irait, et
 * une insertion dans cet intervalle attend. C'est l'hypothese que le code fait ; elle est ici
 * mesuree au lieu d'etre supposee. Le niveau d'isolation est lu et exige, parce que READ COMMITTED
 * ne tient aucun intervalle.
 */
#[Group('mariadb')]
final class LockScopeTest extends FleetDispatchTestCase
{
    use EngagesAPersistentCombat;
    use RunsInParallelProcesses;

    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

    private const int LOCK_WAIT_TIMEOUT = 1205;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requiresMariaDb();
    }

    protected function tearDown(): void
    {
        resolve(SettingsService::class)->set('persistent_combat_enabled', '0');
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::purge('mysql_temoin');
        parent::tearDown();
    }

    public function testTheInstanceRowHoldsASecondSettlementUntilTheFirstCommits(): void
    {
        $combat = $this->anEngagedCombat();
        $temoin = $this->secondConnection();

        DB::beginTransaction();
        DB::table('combat_instances')->where('id', $combat->id)->lockForUpdate()->first();

        $this->assertWaitsOnTheLock(
            static fn () => $temoin->table('combat_instances')->where('id', $combat->id)->lockForUpdate()->first(),
            'A second settlement read the instance while the first held it: both would apply the battle.'
        );

        DB::commit();
        $this->assertNotNull($temoin->table('combat_instances')->where('id', $combat->id)->lockForUpdate()->first(), 'Once the first settlement committed, the row stayed unreadable.');
    }

    public function testAnAbsentBarrierHoldsTheGapWhereItWouldBeInserted(): void
    {
        $this->assertSame('REPEATABLE-READ', $this->isolationLevel(), 'The gap around an absent row is only held under REPEATABLE READ: the engine assumption does not hold here.');
        $combat = $this->anEngagedCombat();
        $temoin = $this->secondConnection();
        $corps = (int)DB::table('celestial_body_combat_barriers')->max('target_body_id') + 1_000;
        $barriere = [
            'target_body_id' => $corps,
            'combat_instance_id' => $combat->id,
            'opened_at' => (int)$combat->started_at,
            'owned_through_effect_at' => (int)$combat->ends_at,
            'revision' => 0,
        ];

        DB::beginTransaction();
        $this->assertNull(DB::table('celestial_body_combat_barriers')->where('target_body_id', $corps)->lockForUpdate()->first(), 'The body already has a barrier: the scenario would prove nothing.');

        $this->assertWaitsOnTheLock(
            static fn () => $temoin->table('celestial_body_combat_barriers')->insert($barriere),
            'A barrier appeared under a gate that had just read "no barrier": the phantom the gate must never see.'
        );

        DB::commit();

        // Le temoin inverse : sans verrou tenu, la meme insertion passe, puis s'efface.
        $this->assertTrue($temoin->table('celestial_body_combat_barriers')->insert($barriere), 'Without a lock held, the insertion should go through: the refusal above was not the lock.');
        $temoin->table('celestial_body_combat_barriers')->where('target_body_id', $corps)->delete();
    }

    private function secondConnection(): Connection
    {
        config(['database.connections.mysql_temoin' => config('database.connections.mysql')]);
        $temoin = DB::connection('mysql_temoin');
        $temoin->statement('SET SESSION innodb_lock_wait_timeout = 1');

        return $temoin;
    }

    private function isolationLevel(): string
    {
        $ligne = DB::selectOne('SELECT @@transaction_isolation AS niveau');
        $this->assertNotNull($ligne);

        return (string)$ligne->niveau;
    }

    /**
     * @param callable(): mixed $tentative
     */
    private function assertWaitsOnTheLock(callable $tentative, string $sinon): void
    {
        try {
            $tentative();
        } catch (QueryException $refus) {
            $this->assertSame(self::LOCK_WAIT_TIMEOUT, (int)($refus->errorInfo[1] ?? 0), 'The attempt failed for another reason than the lock: ' . $refus->getMessage());

            return;
        }

        $this->fail($sinon);
    }
}
