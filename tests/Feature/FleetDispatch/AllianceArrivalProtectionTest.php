<?php

namespace Tests\Feature\FleetDispatch;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Alliance;
use OGame\Models\AllianceMember;
use OGame\Models\FleetMission;
use OGame\Models\Message;
use OGame\Models\Resources;
use OGame\Services\AllianceService;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;
use Tests\Support\DetachesFromAnyAlliance;

/**
 * Une attaque lancee avant l alliance, arrivee apres : elle fait demi-tour sans combattre.
 *
 * ## Le cas que le plan nomme, et qu aucun controle de lancement ne peut couvrir
 *
 * « Contrôles au lancement **et à l'arrivée** [...] notamment si l'appartenance à une alliance change
 * pendant le trajet. » Au depart la cible etait attaquable ; a l arrivee elle ne l est plus. Le
 * lancement a donc dit oui, et c est l arrivee qui doit dire non.
 *
 * ## Les quatre garanties eprouvees ici
 *
 * Aucun tir, aucun degat, aucun butin, aucun debris — la cible est intacte. **Un seul retour**, meme
 * si le travailleur repasse. Vaisseaux et cargaison rentrent tels quels. Et un avis explique le
 * demi-tour, sans quoi le joueur croirait a un defaut.
 */
class AllianceArrivalProtectionTest extends FleetDispatchTestCase
{
    use DetachesFromAnyAlliance;

    protected int $missionType = 1;

    protected string $missionName = 'Attack';

    private int|null $alliance = null;

    /**
     * Ce que la planete doit porter pour que le scenario existe : cinq chasseurs et de quoi les
     * envoyer. Les vitesses sont fixees pour que l arrivee soit atteignable en horloge simulee.
     */
    protected function basicSetup(): void
    {
        $this->planetAddUnit('light_fighter', 5);
        $this->playerSetResearchLevel('computer_technology', object_level: 1);

        $reglages = resolve(SettingsService::class);
        $reglages->set('economy_speed', 8);
        $reglages->set('fleet_speed_war', 1);
        $reglages->set('fleet_speed_holding', 1);
        $reglages->set('fleet_speed_peaceful', 1);
        $reglages->set('attack_block_until', 0);

        // De quoi payer le trajet : sans deuterium l envoi echoue et le scenario n existe pas.
        $this->planetAddResources(new Resources(0, 0, 1000000, 0));
    }

    protected function tearDown(): void
    {
        if ($this->alliance !== null) {
            DB::table('users')->where('alliance_id', $this->alliance)->update(['alliance_id' => null, 'alliance_left_at' => null]);
            AllianceMember::query()->where('alliance_id', $this->alliance)->delete();
            Alliance::query()->whereKey($this->alliance)->delete();
            $this->alliance = null;
        }

        resolve(SettingsService::class)->set('alliance_offensive_protection_enabled', 0);

        parent::tearDown();
    }

    /**
     * Les deux joueurs entrent dans une meme alliance, par le vrai chemin.
     */
    private function uneAllianceCommune(int $autre): void
    {
        // Le proprietaire de la planete propre est partage par les classes du processus : une voisine
        // peut l avoir laisse dans son alliance, et la fondation echouerait pour son etat a elle.
        $this->detachFromAnyAlliance($this->currentUserId, $autre);

        $service = resolve(AllianceService::class);

        $alliance = $service->createAlliance(
            $this->currentUserId,
            'A' . substr((string)$this->currentUserId, -3) . substr((string)$autre, -3),
            'Arrivee ' . $this->currentUserId . '-' . $autre
        );

        $this->alliance = (int)$alliance->id;

        $candidature = $service->applyToAlliance($autre, $this->alliance);
        $service->acceptApplication((int)$candidature->id, $this->currentUserId);

        $this->assertTrue(
            $service->arePlayersInSameAlliance($this->currentUserId, $autre),
            'The two players are not in the same alliance: nothing would be proved.'
        );
    }

    private function uneFlotte(): UnitCollection
    {
        $flotte = new UnitCollection();
        $flotte->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 5);

        return $flotte;
    }

    public function testAnAttackThatBecomesIntraAllianceInFlightTurnsBackWithoutFighting(): void
    {
        $this->basicSetup();

        // **L attaque part AVANT l alliance** : c est tout le scenario. Le lancement dit oui.
        $cible = $this->sendMissionToOtherPlayerCleanPlanet($this->uneFlotte(), new Resources(0, 0, 0, 0));

        $mission = FleetMission::query()->where('user_id', $this->currentUserId)->orderByDesc('id')->firstOrFail();
        $this->assertSame(0, (int)$mission->processed, 'The fleet was processed before the scenario began.');

        $proprietaire = $cible->getPlayer();
        $this->assertNotNull($proprietaire);

        // L alliance se forme pendant le vol, et la protection est armee.
        resolve(SettingsService::class)->set('alliance_offensive_protection_enabled', 1);
        $this->uneAllianceCommune($proprietaire->getId());

        $unitesAvant = $cible->getShipUnits()->getAmount() + $cible->getDefenseUnits()->getAmount();
        $metalAvant = $cible->metal()->get();
        $avisAvant = Message::query()->where('user_id', $this->currentUserId)->count();

        // L arrivee.
        Date::setTestNow(Date::createFromTimestamp((int)$mission->time_arrival + 1));
        resolve(PlayerServiceFactory::class)->make($this->currentUserId, true)->updateFleetMissions();

        $mission->refresh();
        $this->assertSame(1, (int)$mission->processed, 'The arrival was not processed at all.');

        // 1. Un seul retour, avec exactement ce qui etait parti.
        $retours = FleetMission::query()->where('parent_id', $mission->id)->get();
        $this->assertCount(1, $retours, 'The turnaround did not create exactly one return.');

        $retour = $retours->first();
        $this->assertNotNull($retour);
        $this->assertSame(5, (int)$retour->light_fighter, 'The returning fleet is not the one that left.');

        // 2. Aucun tir, aucun butin : la cible est intacte.
        $cible = resolve(\OGame\Factories\PlanetServiceFactory::class)->make($cible->getPlanetId(), true);
        $this->assertNotNull($cible);
        $this->assertSame(
            $unitesAvant,
            $cible->getShipUnits()->getAmount() + $cible->getDefenseUnits()->getAmount(),
            'The target lost or gained units: something fought.'
        );
        $this->assertSame($metalAvant, $cible->metal()->get(), 'The target lost resources: something looted.');

        // 3. Aucun debris a la position visee.
        $coordonnees = $cible->getPlanetCoordinates();
        $this->assertSame(
            0,
            DB::table('debris_fields')
                ->where('galaxy', $coordonnees->galaxy)
                ->where('system', $coordonnees->system)
                ->where('planet', $coordonnees->position)
                ->count(),
            'A debris field appeared although no battle took place.'
        );

        // 4. L avis explique le demi-tour.
        $this->assertSame(
            $avisAvant + 1,
            Message::query()->where('user_id', $this->currentUserId)->count(),
            'The player was not told why the fleet turned back.'
        );
        $this->assertSame(
            'attack_cancelled_by_alliance_protection',
            (string)Message::query()->where('user_id', $this->currentUserId)->orderByDesc('id')->value('key'),
            'The notice is not the one that explains the alliance turnaround.'
        );
    }

    /**
     * **Un second passage ne cree pas un second retour.** Le travailleur peut repasser : la mission
     * est marquee traitee avant que le retour ne parte.
     */
    public function testASecondPassCreatesNoSecondReturn(): void
    {
        $this->basicSetup();

        $cible = $this->sendMissionToOtherPlayerCleanPlanet($this->uneFlotte(), new Resources(0, 0, 0, 0));
        $mission = FleetMission::query()->where('user_id', $this->currentUserId)->orderByDesc('id')->firstOrFail();

        $proprietaire = $cible->getPlayer();
        $this->assertNotNull($proprietaire);

        resolve(SettingsService::class)->set('alliance_offensive_protection_enabled', 1);
        $this->uneAllianceCommune($proprietaire->getId());

        Date::setTestNow(Date::createFromTimestamp((int)$mission->time_arrival + 1));

        $joueur = resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);
        $joueur->updateFleetMissions();
        $joueur->updateFleetMissions();

        $this->assertCount(
            1,
            FleetMission::query()->where('parent_id', $mission->id)->get(),
            'A second pass created a second return: the turnaround is not idempotent.'
        );
    }
}
