<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\SnapshotContribution;
use OGame\Combat\Replay\BattleResultCodec;
use OGame\Combat\Services\RallyClosureService;
use OGame\Combat\Support\CombatEventIdentity;
use OGame\Combat\Support\SnapshotContributionSet;
use OGame\Models\CombatInstance;
use OGame\Models\CombatSnapshotInclusion;
use OGame\Models\FleetMission;
use OGame\Services\FleetMissionService;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;

/**
 * La fermeture applique les effets admissibles encore en attente avant de figer la bataille.
 *
 * ## La regle, et ce qu'elle exige de la fermeture
 *
 * `CausalOrderReconciler` la fixe : un effet appartient a la photographie si sa **decision precede
 * strictement l'ouverture**, si son **effet precede strictement la fermeture**, et s'il n'est pas deja
 * dans l'etat protege. Un transport parti avant l'ouverture et arrivant dans la fenetre y appartient
 * donc — que le travailleur des pages l'ait deja livre ou non. Avant ce raccordement, la fermeture
 * lisait le stock vivant sans rien appliquer, et le butin gele dependait de l'ordre des processus : le
 * bac MariaDB l'a montre.
 *
 * ## Les temoins
 *
 * Le transport admissible est livre **par la fermeture** (traite, un retour, stock credite), son effet
 * entre dans le butin gele, son inclusion est ecrite avec sa provenance, et le travailleur qui passe
 * ensuite ne le livre pas une seconde fois. Les deux barrieres sont strictes : decide **a** l'ouverture
 * ou arrive **a** la fermeture, il reste au travailleur.
 *
 * ## Ce que cet essai laisse ouvert, et le dit
 *
 * Un effet **inadmissible** deja livre par le travailleur avant la fermeture reste dans le stock que la
 * bataille lit ; l'en exclure demanderait la photographie de l'ouverture, qui n'existe pas encore.
 */
final class ClosureAppliesPendingEffectsTest extends FleetDispatchTestCase
{
    use OpensARallyWithAWindow;

    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

    private const int CARGAISON = 10_000;

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

    public function testAnEligiblePendingTransportIsDeliveredByTheClosureAndEntersTheFrozenLoot(): void
    {
        [$combat, $cible, $ouverture] = $this->anOpenRally();
        $transport = $this->aPendingTransportTowards($cible, $ouverture - 100, $ouverture + 10, self::CARGAISON);
        $fermeture = $ouverture + self::RALLY_WINDOW_SECONDS + 1;
        $this->travelTo(Date::createFromTimestamp($fermeture));

        $this->assertTrue((new RallyClosureService())->close($combat->id, $fermeture)->closed, 'The rally did not close.');

        $transport->refresh();
        $this->assertSame(1, (int)$transport->processed, 'The closure left an eligible transport to the worker.');
        $this->assertSame(1, FleetMission::query()->where('parent_id', $transport->id)->count(), 'The delivered transport did not return exactly once.');
        $this->assertSame(self::RALLY_STOCK_METAL + self::CARGAISON, $this->metalOf($cible), 'The cargo was not credited exactly once.');
        $this->assertSame(self::shareOf($combat, self::RALLY_STOCK_METAL + self::CARGAISON), self::frozenMetalOf($combat), 'The frozen loot does not include the eligible cargo.');

        $inclusion = CombatSnapshotInclusion::query()
            ->where('combat_instance_id', $combat->id)
            ->where('event_identity', CombatEventIdentity::forFleetArrival($transport->id))
            ->first();
        $this->assertNotNull($inclusion, 'The delivered transport has no inclusion: its provenance is lost.');
        $this->assertTrue(SnapshotContributionSet::fromStorage($inclusion->contributions)->equals(SnapshotContributionSet::ofOne(SnapshotContribution::DeliveredCargo)), 'The inclusion does not say what the transport brought.');
    }

    public function testTheWorkerPassingAfterTheClosureDeliversNothingTwice(): void
    {
        [$combat, $cible, $ouverture] = $this->anOpenRally();
        $transport = $this->aPendingTransportTowards($cible, $ouverture - 100, $ouverture + 10, self::CARGAISON);
        $fermeture = $ouverture + self::RALLY_WINDOW_SECONDS + 1;
        $this->travelTo(Date::createFromTimestamp($fermeture));
        $this->assertTrue((new RallyClosureService())->close($combat->id, $fermeture)->closed);

        resolve(FleetMissionService::class)->updateMission(FleetMission::query()->findOrFail($transport->id));

        $this->assertSame(self::RALLY_STOCK_METAL + self::CARGAISON, $this->metalOf($cible), 'The worker delivered the cargo a second time.');
        $this->assertSame(1, FleetMission::query()->where('parent_id', $transport->id)->count(), 'The worker created a second return.');
    }

    public function testATransportDecidedAtTheOpeningIsLeftToTheWorker(): void
    {
        [$combat, $cible, $ouverture] = $this->anOpenRally();
        $transport = $this->aPendingTransportTowards($cible, $ouverture, $ouverture + 10, self::CARGAISON);
        $fermeture = $ouverture + self::RALLY_WINDOW_SECONDS + 1;
        $this->travelTo(Date::createFromTimestamp($fermeture));

        $this->assertTrue((new RallyClosureService())->close($combat->id, $fermeture)->closed);

        $transport->refresh();
        $this->assertSame(0, (int)$transport->processed, 'A transport decided at the opening — not strictly before — was applied by the closure.');
        $this->assertSame(self::RALLY_STOCK_METAL, $this->metalOf($cible));
        $this->assertSame(self::shareOf($combat, self::RALLY_STOCK_METAL), self::frozenMetalOf($combat), 'The frozen loot includes a cargo that was not eligible.');
    }

    public function testATransportArrivingAtTheClosureIsLeftToTheWorker(): void
    {
        [$combat, $cible, $ouverture] = $this->anOpenRally();
        $fermeture = $ouverture + self::RALLY_WINDOW_SECONDS + 1;
        $transport = $this->aPendingTransportTowards($cible, $ouverture - 100, $fermeture, self::CARGAISON);
        $this->travelTo(Date::createFromTimestamp($fermeture));

        $this->assertTrue((new RallyClosureService())->close($combat->id, $fermeture)->closed);

        $transport->refresh();
        $this->assertSame(0, (int)$transport->processed, 'A transport arriving on the closure boundary — not strictly before — was applied by the closure.');
        $this->assertSame(self::shareOf($combat, self::RALLY_STOCK_METAL), self::frozenMetalOf($combat));
    }

    private function metalOf(int $planetId): int
    {
        return (int)DB::table('planets')->where('id', $planetId)->value('metal');
    }

    private static function frozenMetalOf(CombatInstance $combat): int
    {
        $combat->refresh();

        return (int)BattleResultCodec::fromStorage($combat->battle_result)->loot->metal->get();
    }

    private static function shareOf(CombatInstance $combat, int $stock): int
    {
        $combat->refresh();

        return intdiv($stock * BattleResultCodec::fromStorage($combat->battle_result)->lootRateInBasisPoints, 10_000);
    }
}
