<?php

namespace Tests\Feature\Combat;

use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Services\RallyClosureService;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;

/**
 * Le travail de reglement mis en file par la fermeture vit dans la transaction de la fermeture.
 *
 * ## Ce que le nom du pilote ne prouve pas
 *
 * `ClosureLockOrderTest` a vu la fermeture ecrire dans `jobs`, et le rapport a soutenu que ce travail
 * n'existe pour personne tant que la fermeture n'a pas valide, parce que le pilote est `database` et
 * `after_commit` vaut faux. La revue 89 a repondu, a juste titre, que le nom du pilote ne garantit
 * pas que la file ecrive **sur la meme connexion** : `DB_QUEUE_CONNECTION` peut designer une autre
 * base, et une insertion faite sur une autre connexion serait visible et durable avant le commit —
 * un travailleur reglerait alors un combat dont la fermeture a ete annulee.
 *
 * ## Ce que cet essai etablit
 *
 * D'abord l'identite : la file `database` resout **la meme instance de connexion** que celle des
 * modeles, pas une connexion du meme nom. Ensuite le comportement, qui est la seule chose qui compte :
 * une fermeture jouee dans une transaction que l'on annule ne laisse **aucun** travail derriere elle.
 */
final class SettlementJobSharesTheClosureConnectionTest extends FleetDispatchTestCase
{
    use OpensARallyWithAWindow;

    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

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

    public function testTheDatabaseQueueWritesThroughTheVeryConnectionTheModelsUse(): void
    {
        $this->assertSame('database', config('queue.default'), 'The test environment does not queue on the database driver: this proof would not be the one production needs.');
        $this->assertFalse((bool)config('queue.connections.database.after_commit'), 'after_commit is on: the job would be pushed after the closure commits, and this proof no longer describes the code.');
        $this->assertNull(config('queue.connections.database.connection'), 'The queue names a connection of its own: it may not be the one the closure writes on.');

        $file = app('queue')->connection('database');
        $this->assertInstanceOf(DatabaseQueue::class, $file);
        $this->assertSame(DB::connection(), $file->getDatabase(), 'The queue resolved a different connection instance than the models: a job pushed inside the closure would live outside its transaction.');
    }

    public function testARolledBackClosureLeavesNoSettlementJobBehind(): void
    {
        [$combat, $cible, $ouverture] = $this->anOpenRally();
        $fermeture = $ouverture + self::RALLY_WINDOW_SECONDS + 1;
        $this->travelTo(Date::createFromTimestamp($fermeture));

        $avant = DB::table('jobs')->count();

        DB::beginTransaction();
        $this->assertTrue((new RallyClosureService())->close($combat->id, $fermeture)->closed, 'The rally did not close.');
        $pendant = DB::table('jobs')->count();
        DB::rollBack();

        $this->assertSame($avant + 1, $pendant, 'The closure did not queue exactly one settlement job inside its transaction.');
        $this->assertSame($avant, DB::table('jobs')->count(), 'A settlement job survived the rollback of its closure: a worker could settle a combat that was never closed.');
        $this->assertNull(DB::table('combat_instances')->where('id', $combat->id)->value('battle_result'), 'The battle result survived the rollback although the job did not: the two do not share a transaction.');
    }
}
