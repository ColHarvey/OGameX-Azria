<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Replay\BattleResultCodec;
use OGame\Combat\Services\RallyClosureService;
use OGame\Combat\Support\CombatEventIdentity;
use OGame\Models\CombatInstance;
use OGame\Models\CombatSnapshotInclusion;
use OGame\Models\FleetMission;
use OGame\Services\FleetMissionService;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;

/**
 * Ce que le monde fait apres l'ouverture reste au monde : le combat ne le photographie pas.
 *
 * ## La regle, et le defaut qu'elle ferme
 *
 * Un effet n'entre dans la photographie que si sa decision precede **strictement** l'ouverture. Une
 * livraison decidee apres elle est donc inadmissible — meme si elle arrive avant la fermeture, meme
 * si le travailleur l'a deja encaissee. Tant que la fermeture lisait le stock vivant, ces ressources
 * entraient dans le butin : le pillage dependait de ce qui s'etait passe pendant le ralliement, et
 * d'un simple depot on augmentait ce qu'un attaquant pouvait prendre.
 *
 * ## Ce qui est exige, et ce qui ne l'est pas
 *
 * Le butin gele ignore ces ressources. Le **monde**, lui, les garde : la cargaison est bien livree,
 * la planete la porte, le transport rentre. Un combat ne retarde pas le jeu et ne l'ecrase pas ; il
 * ne fait que dire ce qu'il a vu au moment ou il s'est ouvert.
 */
final class ClosureIgnoresWhatTheWorldDidAfterTheOpeningTest extends FleetDispatchTestCase
{
    use OpensARallyWithAWindow;

    protected int $missionType = 1;

    protected string $missionName = 'Attaquer';

    private const int CARGAISON = 25_000;

    private const int DEPOT_LIBRE = 40_000;

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

    public function testACargoDecidedAfterTheOpeningAndAlreadyDeliveredStaysOutOfTheFrozenLoot(): void
    {
        [$combat, $cible, $ouverture] = $this->anOpenRally();
        // Decide **apres** l'ouverture, arrive dans la fenetre : inadmissible par la premiere barriere.
        $transport = $this->aPendingTransportTowards($cible, $ouverture + 1, $ouverture + 10, self::CARGAISON);

        // Le travailleur passe pendant le ralliement : le monde encaisse la cargaison.
        $this->travelTo(Date::createFromTimestamp($ouverture + 11));
        resolve(FleetMissionService::class)->updateMission(FleetMission::query()->findOrFail($transport->id));
        $this->assertSame(self::RALLY_STOCK_METAL + self::CARGAISON, $this->metalOf($cible), 'The world did not receive the cargo: the scenario would prove nothing.');

        $fermeture = $ouverture + self::RALLY_WINDOW_SECONDS + 1;
        $this->travelTo(Date::createFromTimestamp($fermeture));
        $this->assertTrue((new RallyClosureService())->close($combat->id, $fermeture)->closed, 'The rally did not close.');

        // **Le butin ignore ces ressources**, et le monde les garde.
        $this->assertSame(self::shareOf($combat, self::RALLY_STOCK_METAL), self::frozenMetalOf($combat), 'A cargo decided after the opening entered the frozen loot.');
        $this->assertSame(self::RALLY_STOCK_METAL + self::CARGAISON, $this->metalOf($cible), 'The closure took back a cargo the world had legitimately received.');
        $transport->refresh();
        $this->assertSame(1, (int)$transport->processed);
        $this->assertSame(1, FleetMission::query()->where('parent_id', $transport->id)->count(), 'The delivered transport lost its return.');
        $this->assertNull(
            CombatSnapshotInclusion::query()->where('combat_instance_id', $combat->id)->where('event_identity', CombatEventIdentity::forFleetArrival($transport->id))->first(),
            'An ineligible arrival was written into the snapshot.'
        );
    }

    public function testACargoDecidedAfterTheOpeningAndStillPendingIsNeitherPhotographedNorHeldBack(): void
    {
        [$combat, $cible, $ouverture] = $this->anOpenRally();
        $transport = $this->aPendingTransportTowards($cible, $ouverture + 1, $ouverture + 10, self::CARGAISON);

        $fermeture = $ouverture + self::RALLY_WINDOW_SECONDS + 1;
        $this->travelTo(Date::createFromTimestamp($fermeture));
        $this->assertTrue((new RallyClosureService())->close($combat->id, $fermeture)->closed);

        // La fermeture ne l'applique pas : il n'appartient pas a ce combat.
        $transport->refresh();
        $this->assertSame(0, (int)$transport->processed, 'The closure applied an arrival decided after the opening.');
        $this->assertSame(self::shareOf($combat, self::RALLY_STOCK_METAL), self::frozenMetalOf($combat));

        // **Et le combat ne le retient pas** : le travailleur le livre ensuite, comme si de rien n'etait.
        resolve(FleetMissionService::class)->updateMission(FleetMission::query()->findOrFail($transport->id));
        $this->assertSame(self::RALLY_STOCK_METAL + self::CARGAISON, $this->metalOf($cible), 'The world never received a cargo that the combat had simply ignored.');
    }

    /**
     * Ce que la production, un depot ou une vente ajoutent apres l'ouverture est libre : le combat
     * ne le voit pas. C'est la strategie `FreeAfterOpening` de l'inventaire de photographie.
     */
    public function testResourcesThatAppearAfterTheOpeningAreFreeAndUnphotographed(): void
    {
        [$combat, $cible, $ouverture] = $this->anOpenRally();

        DB::table('planets')->where('id', $cible)->update(['metal' => self::RALLY_STOCK_METAL + self::DEPOT_LIBRE]);

        $fermeture = $ouverture + self::RALLY_WINDOW_SECONDS + 1;
        $this->travelTo(Date::createFromTimestamp($fermeture));
        $this->assertTrue((new RallyClosureService())->close($combat->id, $fermeture)->closed);

        $this->assertSame(self::shareOf($combat, self::RALLY_STOCK_METAL), self::frozenMetalOf($combat), 'Resources that appeared after the opening entered the frozen loot.');
        $this->assertSame(self::RALLY_STOCK_METAL + self::DEPOT_LIBRE, $this->metalOf($cible), 'The closure changed a stock it only had to read.');
    }

    /**
     * Le temoin inverse de tout ce fichier : sans le raccordement causal, ces trois scenarios
     * donneraient le butin du stock vivant. Celui-ci etablit que la difference est reellement
     * observable — que « juste » et « faux » ne coincident pas.
     */
    public function testTheFrozenLootDiffersFromTheLivingStock(): void
    {
        [$combat, $cible, $ouverture] = $this->anOpenRally();
        DB::table('planets')->where('id', $cible)->update(['metal' => self::RALLY_STOCK_METAL + self::DEPOT_LIBRE]);
        $fermeture = $ouverture + self::RALLY_WINDOW_SECONDS + 1;
        $this->travelTo(Date::createFromTimestamp($fermeture));
        (new RallyClosureService())->close($combat->id, $fermeture);

        $this->assertNotSame(
            self::shareOf($combat, self::RALLY_STOCK_METAL + self::DEPOT_LIBRE),
            self::frozenMetalOf($combat),
            'The living stock and the protected reserve give the same loot here: these tests would pass without the reconciliation.'
        );
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
