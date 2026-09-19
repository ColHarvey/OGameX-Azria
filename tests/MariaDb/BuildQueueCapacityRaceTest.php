<?php

namespace Tests\MariaDb;

use Illuminate\Support\Facades\DB;
use OGame\Factories\PlanetServiceFactory;
use OGame\Models\Resources;
use OGame\Queues\QueueCapacity;
use OGame\Services\BuildingQueueService;
use OGame\Services\ObjectService;
use PHPUnit\Framework\Attributes\Group;
use Tests\AccountTestCase;
use Throwable;

/**
 * **Deux clics simultanes ne depassent pas la limite de la file** (precaution demandee par Keven, 19 septembre 2026,
 * journal §171).
 *
 * La file de construction accepte un travail en cours et quatre en attente. Avec quatre deja en attente, deux demandes
 * reellement simultanees doivent etre **toutes deux refusees** : sans le verrou de la planete, chacune lirait une file
 * non pleine et inserait son travail — la limite serait franchie d un ou deux crans, sans qu aucun essai sous SQLite
 * ne le voie (`lockForUpdate()` n y compile a rien).
 *
 * L essai mesure ce que la base porte **apres** la course, pas ce que le code croit avoir fait.
 */
#[Group('mariadb')]
final class BuildQueueCapacityRaceTest extends AccountTestCase
{
    use RunsInParallelProcesses;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requiresMariaDb();
        $this->requiresProcesses();
        $this->planetAddResources(new Resources(50000000, 50000000, 50000000, 0));
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::table('building_queues')->where('planet_id', $this->planetService->getPlanetId())->delete();
        parent::tearDown();
    }

    public function testTwoSimultaneousRequestsNeverExceedTheQueueLimit(): void
    {
        $planetId = $this->planetService->getPlanetId();
        $mine = ObjectService::getObjectByMachineName('metal_mine')->id;
        $file = resolve(BuildingQueueService::class);

        // La premisse : un travail en cours et quatre en attente. La file est pleine, au bord exact.
        for ($i = 0; $i < 1 + QueueCapacity::WAITING_BASE; $i++) {
            $file->add($this->planetService, $mine);
        }
        $this->assertSame(QueueCapacity::WAITING_BASE, $file->retrieveQueue($this->planetService)->waitingCount(), 'Premisse : quatre travaux attendent.');

        $issues = $this->inParallel(2, static function (int $rang) use ($planetId, $mine): string {
            $planete = resolve(PlanetServiceFactory::class)->make($planetId, true);
            if ($planete === null) {
                return 'erreur:planete introuvable';
            }
            try {
                resolve(BuildingQueueService::class)->add($planete, $mine);

                return 'accepte';
            } catch (Throwable $e) {
                return 'refuse';
            }
        });

        $this->assertSame(['refuse', 'refuse'], $issues, 'Les deux demandes simultanees sont refusees : ' . implode(' | ', $issues));

        $enAttente = DB::table('building_queues')
            ->where('planet_id', $planetId)
            ->where('processed', 0)
            ->where('canceled', 0)
            ->where('building', 0)
            ->count();
        $this->assertSame(QueueCapacity::WAITING_BASE, $enAttente, 'La base porte toujours quatre travaux en attente, pas cinq ni six.');
    }
}
