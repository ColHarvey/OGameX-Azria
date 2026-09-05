<?php

namespace Tests\MariaDb;

use Illuminate\Support\Facades\Date;
use OGame\Combat\Enums\CombatReasonCode;
use OGame\Combat\Enums\FleetDispositionKind;
use OGame\Combat\Services\FleetDispositionRegistry;
use OGame\Models\CombatFleetDisposition;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Combat\EngagesAPersistentCombat;
use Tests\FleetDispatchTestCase;

/**
 * Une disposition de flotte consommee par deux travailleurs a la fois : un seul fait l'effet.
 *
 * Le registre verrouille la ligne, relit `consumed_at` sous le verrou, la pose, puis fait l'effet
 * dans la meme transaction. Le second travailleur attend le premier, relit une ligne consommee et
 * ne fait rien. C'est la promesse « un retour, jamais deux » d'une flotte refusee ; SQLite ne la
 * jouait pas.
 */
#[Group('mariadb')]
final class DispositionConsumptionRaceTest extends FleetDispatchTestCase
{
    use EngagesAPersistentCombat;
    use RunsInParallelProcesses;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requiresMariaDb();
        $this->requiresProcesses();
    }

    public function testADispositionIsConsumedByExactlyOneOfTwoWorkers(): void
    {
        $combat = $this->anEngagedCombat();
        $registre = resolve(FleetDispositionRegistry::class);
        $registre->record($combat, (int)$combat->mission_id, CombatReasonCode::RallyClosed, (int)$combat->ends_at, FleetDispositionKind::ReturnToOrigin);
        $disposition = CombatFleetDisposition::query()->where('fleet_mission_id', $combat->mission_id)->firstOrFail();
        $this->assertTrue($disposition->isPending());

        $marques = sys_get_temp_dir() . '/ogamex-consommation-' . bin2hex(random_bytes(6));
        mkdir($marques, 0700, true);

        $issues = $this->inParallel(2, static function (int $rang) use ($disposition, $marques): string {
            $faite = resolve(FleetDispositionRegistry::class)->consume(
                $disposition,
                (int)Date::now()->timestamp,
                static function () use ($marques, $rang): void {
                    file_put_contents($marques . '/' . $rang, '1');
                }
            );

            return $faite ? 'consommee' : 'deja faite';
        });

        sort($issues);
        $this->assertSame(['consommee', 'deja faite'], $issues, 'Both workers consumed the disposition, or neither did.');
        $marquesEcrites = glob($marques . '/*');
        $this->assertIsArray($marquesEcrites);
        $this->assertCount(1, $marquesEcrites, 'The effect ran twice, or never.');
        $disposition->refresh();
        $this->assertFalse($disposition->isPending(), 'The disposition is still pending after being consumed.');
    }
}
