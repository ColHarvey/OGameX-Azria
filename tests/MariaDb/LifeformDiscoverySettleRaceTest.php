<?php

namespace Tests\MariaDb;

use Illuminate\Support\Facades\DB;
use OGame\Factories\PlayerServiceFactory;
use OGame\Lifeforms\Discovery\LifeformDiscoveryOutcome;
use OGame\Lifeforms\Discovery\LifeformDiscoveryRules;
use OGame\Lifeforms\Services\LifeformDiscoveryService;
use OGame\Lifeforms\Services\LifeformInstallationService;
use OGame\Lifeforms\Species;
use OGame\Models\Planet;
use OGame\Services\SettingsService;
use PHPUnit\Framework\Attributes\Group;
use Tests\AccountTestCase;
use Throwable;

/**
 * Deux reglements reellement simultanes du meme vol de decouverte echu : **un seul credit, un seul
 * rapport**. `LifeformDiscoveryService::settleDue()` verrouille la ligne du vol et la relit
 * `running` ; sans ce verrou les deux passages liraient la meme issue et crediteraient deux fois.
 */
#[Group('mariadb')]
final class LifeformDiscoverySettleRaceTest extends AccountTestCase
{
    use RunsInParallelProcesses;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requiresMariaDb();
        $this->requiresProcesses();
        resolve(SettingsService::class)->set('lifeforms_enabled', '1');
        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Humans, (int)now()->timestamp);
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        resolve(SettingsService::class)->set('lifeforms_enabled', '0');
        $planetes = Planet::query()->where('user_id', $this->currentUserId)->pluck('id');
        DB::table('lifeform_discoveries')->where('user_id', $this->currentUserId)->delete();
        DB::table('messages')->where('user_id', $this->currentUserId)->where('key', 'lifeform_discovery_report')->delete();
        DB::table('lifeform_planets')->whereIn('planet_id', $planetes)->delete();
        DB::table('lifeform_species_progress')->where('user_id', $this->currentUserId)->delete();
        DB::table('lifeform_accounts')->where('user_id', $this->currentUserId)->delete();
        parent::tearDown();
    }

    public function testTwoSimultaneousSettlementsCreditOnceAndReportOnce(): void
    {
        $userId = $this->currentUserId;
        $maintenant = (int)now()->timestamp;
        DB::table('lifeform_discoveries')->insert([
            'user_id' => $userId,
            'planet_id' => $this->currentPlanetId,
            'galaxy' => 1,
            'system' => 1,
            'position' => 7,
            'started_at' => $maintenant - 3600,
            'ends_at' => $maintenant - 1,
            'outcome' => json_encode((new LifeformDiscoveryOutcome(LifeformDiscoveryOutcome::ARTIFACTS, null, 25, 0))->toStorage()),
            'status' => 'running',
            'rules_version' => LifeformDiscoveryRules::VERSION,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $issues = $this->inParallel(2, static function (int $rang) use ($userId, $maintenant): string {
            $joueur = resolve(PlayerServiceFactory::class)->make($userId, true);
            try {
                return 'regles:' . resolve(LifeformDiscoveryService::class)->settleDue($joueur, $maintenant);
            } catch (Throwable $e) {
                return 'erreur:' . $e->getMessage();
            }
        });

        sort($issues);
        $this->assertSame(['regles:0', 'regles:1'], $issues, implode(' | ', $issues));
        $this->assertSame(25, (int)DB::table('lifeform_accounts')->where('user_id', $userId)->value('artifacts'), 'Un seul credit.');
        $this->assertSame(1, DB::table('messages')->where('user_id', $userId)->where('key', 'lifeform_discovery_report')->count(), 'Un seul rapport.');
        $this->assertSame('settled', DB::table('lifeform_discoveries')->where('user_id', $userId)->value('status'));
    }
}
