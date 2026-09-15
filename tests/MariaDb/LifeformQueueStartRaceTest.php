<?php

namespace Tests\MariaDb;

use Illuminate\Support\Facades\DB;
use OGame\Factories\PlanetServiceFactory;
use OGame\Lifeforms\LifeformRefused;
use OGame\Lifeforms\Services\LifeformInstallationService;
use OGame\Lifeforms\Services\LifeformQueueService;
use OGame\Lifeforms\Species;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Services\SettingsService;
use PHPUnit\Framework\Attributes\Group;
use Tests\AccountTestCase;
use Throwable;

/**
 * Deux demandes reellement simultanees du meme batiment de forme de vie : **un seul travail
 * demarre, un seul prix paye**, le second attend derriere avec le niveau suivant.
 *
 * `LifeformQueueService::add()` tient la ligne de la planete ; sans ce verrou les deux demandes
 * liraient le meme niveau courant, viseraient toutes deux le niveau 1, et debiteraient deux fois.
 */
#[Group('mariadb')]
final class LifeformQueueStartRaceTest extends AccountTestCase
{
    use RunsInParallelProcesses;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requiresMariaDb();
        $this->requiresProcesses();
        resolve(SettingsService::class)->set('lifeforms_enabled', '1');
        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Humans, (int)now()->timestamp);
        $this->planetAddResources(new Resources(10000, 10000, 10000, 0));
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        resolve(SettingsService::class)->set('lifeforms_enabled', '0');
        $planetes = Planet::query()->where('user_id', $this->currentUserId)->pluck('id');
        DB::table('lifeform_queues')->whereIn('planet_id', $planetes)->delete();
        DB::table('lifeform_building_levels')->whereIn('planet_id', $planetes)->delete();
        DB::table('lifeform_planets')->whereIn('planet_id', $planetes)->delete();
        DB::table('lifeform_species_progress')->where('user_id', $this->currentUserId)->delete();
        DB::table('lifeform_accounts')->where('user_id', $this->currentUserId)->delete();
        parent::tearDown();
    }

    public function testTwoSimultaneousRequestsStartOneWorkAndPayOnce(): void
    {
        $planetId = $this->planetService->getPlanetId();
        $metalAvant = (int)DB::table('planets')->where('id', $planetId)->value('metal');
        $maintenant = (int)now()->timestamp;

        $issues = $this->inParallel(2, static function (int $rang) use ($planetId, $maintenant): string {
            $planete = resolve(PlanetServiceFactory::class)->make($planetId, true);
            if ($planete === null) {
                return 'erreur:planete introuvable';
            }
            try {
                $element = resolve(LifeformQueueService::class)->add($planete, 11101, $maintenant);

                return $element->status . ':' . $element->target_level;
            } catch (LifeformRefused $refus) {
                return 'refuse:' . $refus->reason;
            } catch (Throwable $e) {
                return 'erreur:' . $e->getMessage();
            }
        });

        sort($issues);
        $this->assertSame(['running:1', 'waiting:2'], $issues, implode(' | ', $issues));

        $lignes = DB::table('lifeform_queues')->where('planet_id', $planetId)->orderBy('id')->get();
        $this->assertCount(2, $lignes);
        $this->assertSame(['running', 'waiting'], $lignes->pluck('status')->all());
        $this->assertSame([1, 2], $lignes->pluck('target_level')->map(fn ($v) => (int)$v)->all());
        $this->assertSame($metalAvant - 7, (int)DB::table('planets')->where('id', $planetId)->value('metal'), 'Un seul prix : celui du niveau 1.');
    }
}
