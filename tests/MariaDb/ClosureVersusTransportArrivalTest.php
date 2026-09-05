<?php

namespace Tests\MariaDb;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Replay\BattleResultCodec;
use OGame\Combat\Services\FleetMovementGate;
use OGame\Combat\Services\RallyClosureService;
use OGame\Models\CombatInstance;
use OGame\Models\FleetMission;
use OGame\Services\FleetMissionService;
use OGame\Services\SettingsService;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Combat\OpensARallyWithAWindow;
use Tests\FleetDispatchTestCase;

/**
 * La fermeture d'un ralliement contre l'arrivee d'un transport admissible sur le meme corps, dans
 * deux processus : dans les deux ordres, puis en meme temps. Le resultat ne depend pas de l'ordre.
 *
 * ## L'invariant causal, et ce qu'il exige des deux processus
 *
 * Ce transport est parti avant l'ouverture et arrive dans la fenetre : par la regle du
 * reconciliateur, son effet appartient a la photographie — que le travailleur l'ait deja livre ou
 * non quand la fermeture prend ses verrous. La fermeture applique donc les effets admissibles
 * encore en attente avant de figer ; le travailleur passe par la porte, donc apres elle, et trouve
 * le transport traite. Dans les trois scenarios : le transport est livre **une fois**, le stock
 * vaut stock + cargaison, et le butin gele vaut la part du stock **avec** la cargaison, au taux que
 * le resultat gele porte.
 *
 * Le premier montage de cette epreuve acceptait « l'un des deux stocks » : c'etait une garantie
 * comptable, pas la photographie causale, et le bac a montre que la cargaison n'entrait dans le
 * butin que si le travailleur passait avant la fermeture. Codex l'a vu (revue 85).
 */
#[Group('mariadb')]
final class ClosureVersusTransportArrivalTest extends FleetDispatchTestCase
{
    use OpensARallyWithAWindow;
    use RunsInParallelProcesses;

    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

    private const int CARGAISON = 10_000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requiresMariaDb();
        $this->requiresProcesses();
    }

    protected function tearDown(): void
    {
        resolve(SettingsService::class)->set('persistent_combat_enabled', '0');
        parent::tearDown();
    }

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

    public function testTheClosureFirstDeliversTheEligibleTransportItself(): void
    {
        [$combat, $transport, $cible, $fermeture] = $this->anOpenRallyAndAnEligibleTransport();

        $issues = $this->inParallel(2, function (int $rang) use ($combat, $transport, $fermeture): string {
            if ($rang === 0) {
                return self::closeTheRally($combat, $fermeture);
            }
            $this->waitUntil(
                static fn (): bool => DB::table('combat_instances')->where('id', $combat->id)->value('status') === CombatState::Active->value,
                'The rally never closed: the worker could not pass after it.'
            );

            return self::passTheWorkerOver($transport);
        });

        $this->assertBothHappened($issues);
        $this->assertTheCausalOutcome($combat, $transport, $cible);
    }

    public function testTheWorkerFirstDeliversAndTheClosureFindsItDone(): void
    {
        [$combat, $transport, $cible, $fermeture] = $this->anOpenRallyAndAnEligibleTransport();

        $issues = $this->inParallel(2, function (int $rang) use ($combat, $transport, $fermeture): string {
            if ($rang === 1) {
                return self::passTheWorkerOver($transport);
            }
            $this->waitUntil(
                static fn (): bool => (int)DB::table('fleet_missions')->where('id', $transport->id)->value('processed') === 1,
                'The worker never delivered: the closure could not come after it.'
            );

            return self::closeTheRally($combat, $fermeture);
        });

        $this->assertBothHappened($issues);
        $this->assertTheCausalOutcome($combat, $transport, $cible);
    }

    public function testASimultaneousClosureAndWorkerStillAgreeOnTheSameOutcome(): void
    {
        [$combat, $transport, $cible, $fermeture] = $this->anOpenRallyAndAnEligibleTransport();

        $issues = $this->inParallel(2, static fn (int $rang): string => $rang === 0
            ? self::closeTheRally($combat, $fermeture)
            : self::passTheWorkerOver($transport));

        $this->assertBothHappened($issues);
        $this->assertTheCausalOutcome($combat, $transport, $cible);
    }

    private static function closeTheRally(CombatInstance $combat, int $fermeture): string
    {
        return (new RallyClosureService())->close($combat->id, $fermeture)->closed ? 'fermee' : 'non fermee';
    }

    /**
     * Le travailleur des pages, tel qu'il traite une arrivee gouvernee par le combat : par la porte.
     */
    private static function passTheWorkerOver(FleetMission $transport): string
    {
        $service = resolve(FleetMissionService::class);
        resolve(FleetMovementGate::class)->decideUnderLock(
            FleetMission::query()->findOrFail($transport->id),
            static function (FleetMission $tenue) use ($service): void {
                $service->updateMission($tenue);
            }
        );

        return 'passe';
    }

    /**
     * @param array<int, string> $issues
     */
    private function assertBothHappened(array $issues): void
    {
        $this->assertSame(['fermee', 'passe'], [$issues[0], $issues[1]], 'The closure or the worker did not run to the end.');
    }

    private function assertTheCausalOutcome(CombatInstance $combat, FleetMission $transport, int $cible): void
    {
        $transport->refresh();
        $this->assertSame(1, (int)$transport->processed, 'The transport was not processed.');
        $this->assertSame(1, FleetMission::query()->where('parent_id', $transport->id)->count(), 'The transport did not return exactly once.');
        $this->assertSame(self::RALLY_STOCK_METAL + self::CARGAISON, (int)DB::table('planets')->where('id', $cible)->value('metal'), 'The cargo was lost or delivered twice.');

        $combat->refresh();
        $this->assertSame(CombatState::Active, $combat->status, 'The rally did not end up closed.');
        $this->assertNotNull($combat->battle_result, 'The closure froze no battle result.');
        $resultat = BattleResultCodec::fromStorage($combat->battle_result);
        $attendu = intdiv((self::RALLY_STOCK_METAL + self::CARGAISON) * $resultat->lootRateInBasisPoints, 10_000);
        $this->assertSame($attendu, (int)$resultat->loot->metal->get(), 'The frozen loot does not include the eligible cargo: the photograph depended on which process came first.');
    }

    /**
     * @return array{0: CombatInstance, 1: FleetMission, 2: int, 3: int}
     */
    private function anOpenRallyAndAnEligibleTransport(): array
    {
        [$combat, $cible, $ouverture] = $this->anOpenRally();
        $transport = $this->aPendingTransportTowards($cible, $ouverture - 100, $ouverture + 10, self::CARGAISON);
        $fermeture = $ouverture + self::RALLY_WINDOW_SECONDS + 1;
        $this->travelTo(Date::createFromTimestamp($fermeture));

        return [$combat, $transport, $cible, $fermeture];
    }
}
