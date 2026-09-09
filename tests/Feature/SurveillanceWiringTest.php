<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Models\SurveillanceContact;
use OGame\Patrol\Enums\PatrolState;
use OGame\Patrol\Enums\SurveillanceTier;
use OGame\Patrol\Geometry\SpatialPoint;
use OGame\Patrol\PatrolDestination;
use OGame\Patrol\PatrolOrders;
use OGame\Patrol\PatrolPricing;
use OGame\Services\ObjectService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;

/**
 * La veille est-elle reellement branchee sur le cycle de vie d une patrouille ?
 *
 * ## Pourquoi un temoin de plus
 *
 * `SurveillanceWatchTest` eprouve le service en l appelant lui-meme : il prouve que la regle est
 * juste, jamais qu elle est **atteinte**. Un service parfait que personne n appelle laisse le jeu
 * exactement ou il etait, et aucune mutation du service ne le revelerait. Celui-ci ne touche donc
 * jamais `SurveillanceWatch` : il lance une patrouille, laisse le travailleur du jeu la poser, et
 * regarde ce que la base contient.
 */
class SurveillanceWiringTest extends AccountTestCase
{
    /**
     * Les corps poses par cet essai, retires au demontage.
     *
     * @var array<int, int>
     */
    private array $corpsPoses = [];

    protected function tearDown(): void
    {
        if ($this->corpsPoses !== []) {
            SurveillanceContact::query()->whereIn('observer_planet_id', $this->corpsPoses)->delete();
            Planet::query()->whereIn('id', $this->corpsPoses)->delete();
            $this->corpsPoses = [];
        }

        resolve(SettingsService::class)->set('patrols_enabled', 0);

        parent::tearDown();
    }

    private function player(): PlayerService
    {
        return resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);
    }

    private function orders(): PatrolOrders
    {
        return resolve(PatrolOrders::class);
    }

    /**
     * @param array<string, int> $composition
     */
    private function fleet(array $composition): UnitCollection
    {
        $units = new UnitCollection();

        foreach ($composition as $nom => $nombre) {
            $units->addUnit(ObjectService::getUnitObjectByMachineName($nom), $nombre);
        }

        return $units;
    }

    private function aFreePoint(): PatrolDestination
    {
        $coords = $this->planetService->getPlanetCoordinates();

        return PatrolDestination::spatialPoint(
            resolve(PatrolPricing::class)->geometry(),
            $coords->galaxy,
            $coords->system,
            new SpatialPoint(600, 600)
        );
    }

    /**
     * Pose un corps d un tiers dans le systeme du joueur, avec ce niveau de reseau.
     */
    private function unTiersDansMonSysteme(int $niveau): int
    {
        $coords = $this->planetService->getPlanetCoordinates();

        $etrangere = $this->getNearbyForeignPlanet();
        $proprietaire = $etrangere->getPlayer();
        $this->assertNotNull($proprietaire);
        $this->assertNotSame($this->currentUserId, $proprietaire->getId());

        $prises = DB::table('planets')
            ->where('galaxy', $coords->galaxy)
            ->where('system', $coords->system)
            ->pluck('planet')
            ->map(static fn ($position): int => (int)$position)
            ->all();

        $libre = null;

        for ($position = 1; $position <= 15; $position++) {
            if (!in_array($position, $prises, true)) {
                $libre = $position;
                break;
            }
        }

        $this->assertNotNull($libre, 'The system is full: this test cannot place the body it needs.');

        $planete = Planet::factory()->create([
            'user_id' => $proprietaire->getId(),
            'galaxy' => $coords->galaxy,
            'system' => $coords->system,
            'planet' => $libre,
            'surveillance_network' => $niveau,
        ]);

        $this->corpsPoses[] = (int)$planete->id;

        return (int)$planete->id;
    }

    /**
     * Lance une patrouille et la laisse se poser par le travailleur du jeu.
     */
    private function unePatrouillePosee(): \OGame\Models\Patrol
    {
        resolve(SettingsService::class)->set('patrols_enabled', 1);
        $this->playerSetResearchLevel('computer_technology', 10);
        $this->planetAddResources(new Resources(0, 0, 60000, 0));
        $this->planetAddUnit('cruiser', 20);

        $patrouille = $this->orders()->launch(
            $this->planetService,
            $this->fleet(['cruiser' => 20]),
            new Resources(0, 0, 0, 0),
            10000,
            $this->aFreePoint(),
            10,
            (int)Date::now()->timestamp
        );

        $segment = FleetMission::query()->findOrFail($patrouille->current_mission_id);
        Date::setTestNow(Date::createFromTimestamp((int)$segment->time_arrival + 1));
        $this->player()->updateFleetMissions();

        $patrouille->refresh();
        $this->assertSame(PatrolState::Stationed, $patrouille->state, 'The patrol never parked: the scenario would prove nothing.');

        return $patrouille;
    }

    /**
     * Poser une patrouille ouvre le contact du corps equipe qui partage son systeme.
     */
    public function testParkingOpensTheContactOfAnEquippedNeighbour(): void
    {
        $observateur = $this->unTiersDansMonSysteme(SurveillanceTier::Heading->value);
        $patrouille = $this->unePatrouillePosee();

        $contact = SurveillanceContact::query()
            ->where('observer_planet_id', $observateur)
            ->where('patrol_id', $patrouille->id)
            ->first();

        $this->assertNotNull($contact, 'The worker parked the patrol and no contact was opened: the watch is not wired.');
        $this->assertNull($contact->revoked_at);
        $this->assertSame((int)$patrouille->entered_system_at, (int)$contact->entered_system_at, 'The contact does not start at the entry the patrol recorded.');
        $this->assertSame(
            (int)$patrouille->entered_system_at + SurveillanceTier::Heading->acquisitionSeconds(),
            (int)$contact->visible_from,
            'The deadline does not come from the tier of the observing body.'
        );
    }

    /**
     * Un voisin sans reseau n apprend rien du passage.
     */
    public function testANeighbourWithoutANetworkLearnsNothingFromThePassage(): void
    {
        $sansReseau = $this->unTiersDansMonSysteme(0);
        $patrouille = $this->unePatrouillePosee();

        $this->assertSame(
            0,
            SurveillanceContact::query()->where('observer_planet_id', $sansReseau)->where('patrol_id', $patrouille->id)->count(),
            'A neighbour with no network was told about the patrol: the leak is server-side, not a display matter.'
        );
    }

    /**
     * Construire le reseau par le vrai ecrivain ouvre les contacts des patrouilles deja posees.
     *
     * `PlanetService::setObjectLevel()` est le seul endroit ou un niveau de batiment s ecrit : la
     * file terminee, la demolition et l administration y passent toutes. L eprouver ici, plutot que
     * d appeler la veille, est ce qui distingue une regle juste d une regle atteinte.
     */
    public function testBuildingTheNetworkOpensContactsForPatrolsAlreadyThere(): void
    {
        $observateur = $this->unTiersDansMonSysteme(0);
        $patrouille = $this->unePatrouillePosee();

        $this->assertSame(
            0,
            SurveillanceContact::query()->where('observer_planet_id', $observateur)->count(),
            'Un corps sans reseau observait deja : la premisse est fausse.'
        );

        $corps = resolve(PlanetServiceFactory::class)->make($observateur, true);
        $this->assertNotNull($corps);

        $maintenant = (int)Date::now()->timestamp;
        $corps->setObjectLevel(ObjectService::getObjectByMachineName('surveillance_network')->id, SurveillanceTier::Contact->value);

        $contact = SurveillanceContact::query()
            ->where('observer_planet_id', $observateur)
            ->where('patrol_id', $patrouille->id)
            ->first();

        $this->assertNotNull($contact, 'Construire le reseau n a ouvert aucun contact : le raccordement manque.');
        $this->assertSame($maintenant, (int)$contact->acquisition_from, 'L acquisition ne part pas de la mise en service.');
    }

    /**
     * Demolir le reseau par le vrai ecrivain revoque ce que ce corps observait.
     */
    public function testDemolishingTheNetworkRevokesWhatItWatched(): void
    {
        $observateur = $this->unTiersDansMonSysteme(SurveillanceTier::Identity->value);
        $patrouille = $this->unePatrouillePosee();

        $this->assertSame(
            1,
            SurveillanceContact::query()->where('observer_planet_id', $observateur)->whereNull('revoked_at')->count(),
            'La premisse manque : aucun contact ouvert a revoquer.'
        );

        $corps = resolve(PlanetServiceFactory::class)->make($observateur, true);
        $this->assertNotNull($corps);
        $corps->setObjectLevel(ObjectService::getObjectByMachineName('surveillance_network')->id, 0);

        $contact = SurveillanceContact::query()
            ->where('observer_planet_id', $observateur)
            ->where('patrol_id', $patrouille->id)
            ->firstOrFail();

        $this->assertNotNull($contact->revoked_at, 'Demolir le reseau n a rien revoque : ce que la perte doit retirer est reste.');
    }

    /**
     * Changer de systeme ferme ce que l ancien voyait, et n emporte pas l acquisition ailleurs.
     *
     * ## Ce que cette epreuve etablit, et que la precedente ne dit pas
     *
     * `revokeAllFor()` est appele a deux endroits — au depart vers un autre systeme, et a
     * l atterrissage. Un temoin qui ne couvre que l atterrissage laisse le premier appel sans
     * preuve : la mutation qui le retire survit, et un voisin continue d observer une patrouille
     * partie depuis longtemps. Mesure faite : elle a survecu.
     */
    public function testChangingSystemClosesWhatTheOldOneSaw(): void
    {
        $observateur = $this->unTiersDansMonSysteme(SurveillanceTier::Identity->value);
        $patrouille = $this->unePatrouillePosee();

        $this->assertSame(
            1,
            SurveillanceContact::query()->where('observer_planet_id', $observateur)->whereNull('revoked_at')->count(),
            'The premise is missing: no open contact to close.'
        );

        // **Un point d un autre systeme.** La reserve est large : ce que cette epreuve mesure est la
        // fermeture, pas la facture, et un depart refuse faute de carburant serait un vert vide.
        $coords = $this->planetService->getPlanetCoordinates();
        DB::table('patrols')->where('id', $patrouille->id)->update(['fuel_reserve' => 5_000_000]);
        $patrouille->refresh();

        $ailleurs = PatrolDestination::spatialPoint(
            resolve(PatrolPricing::class)->geometry(),
            $coords->galaxy,
            $coords->system === 1 ? 2 : $coords->system - 1,
            new SpatialPoint(600, 600)
        );

        $maintenant = (int)Date::now()->timestamp;
        $refus = $this->orders()->whyMoveIsRefused($patrouille, $maintenant);
        $this->assertNull($refus, 'The move is refused (' . (string)$refus . '): the scenario would prove nothing.');

        $segment = $this->orders()->orderMove($patrouille, $ailleurs, 10, (int)$patrouille->order_version, $maintenant);

        Date::setTestNow(Date::createFromTimestamp((int)$segment->time_arrival + 1));
        $this->player()->updateFleetMissions();

        $patrouille->refresh();
        $this->assertSame(PatrolState::Stationed, $patrouille->state, 'The patrol never parked in the new system.');
        $this->assertNotSame($coords->system, (int)$patrouille->system, 'The patrol did not change system: this test would prove nothing.');

        $contact = SurveillanceContact::query()
            ->where('observer_planet_id', $observateur)
            ->where('patrol_id', $patrouille->id)
            ->firstOrFail();

        $this->assertNotNull(
            $contact->revoked_at,
            'The patrol left the system and the neighbour still watches it: what it saw outlived what it could see.'
        );
    }

    /**
     * La patrouille rentree cesse d etre vue.
     */
    public function testAPatrolThatCameHomeStopsBeingSeen(): void
    {
        $observateur = $this->unTiersDansMonSysteme(SurveillanceTier::Identity->value);
        $patrouille = $this->unePatrouillePosee();

        $this->assertSame(
            1,
            SurveillanceContact::query()->where('observer_planet_id', $observateur)->whereNull('revoked_at')->count(),
            'The premise is missing: no open contact to revoke.'
        );

        // Le rendez-vous vient : le retour de securite part, puis arrive.
        $segment = FleetMission::query()->findOrFail($patrouille->current_mission_id);
        Date::setTestNow(Date::createFromTimestamp((int)$segment->time_arrival + (int)$segment->time_holding + 1));
        $this->player()->updateFleetMissions();

        $patrouille->refresh();
        $this->assertSame(PatrolState::Returning, $patrouille->state, 'The safety return never left.');

        $retour = FleetMission::query()->findOrFail($patrouille->current_mission_id);
        Date::setTestNow(Date::createFromTimestamp((int)$retour->time_arrival + 1));
        $this->player()->updateFleetMissions();

        $patrouille->refresh();
        $this->assertSame(PatrolState::Finished, $patrouille->state, 'The patrol did not land: the revocation would not be the one under test.');

        $contact = SurveillanceContact::query()->where('observer_planet_id', $observateur)->where('patrol_id', $patrouille->id)->firstOrFail();

        $this->assertNotNull($contact->revoked_at, 'The patrol came home and its contact stayed open: the neighbour still watches a fleet that no longer exists.');
    }
}
