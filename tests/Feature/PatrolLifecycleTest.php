<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\FleetMission;
use OGame\Models\Patrol;
use OGame\Models\Resources;
use OGame\Patrol\Enums\PatrolState;
use OGame\Patrol\Exceptions\PatrolOrderRefused;
use OGame\Patrol\Geometry\SpatialPoint;
use OGame\Patrol\PatrolDestination;
use OGame\Patrol\PatrolOrders;
use OGame\Patrol\PatrolPricing;
use OGame\Patrol\PatrolUpkeep;
use OGame\Services\ObjectService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;

/**
 * La vie entiere d une patrouille : elle part, elle se pose, elle brule, elle rentre.
 *
 * ## Ce que ces temoins etablissent, et pourquoi ils passent par le travailleur du jeu
 *
 * Les trois arrivees d une patrouille sont declenchees par `PlayerService::updateFleetMissions()`,
 * le meme travailleur que toutes les autres missions. Les eprouver en appelant le service
 * directement prouverait le service ; les eprouver par le travailleur prouve que le raccordement
 * tient — que le segment est trouve, repris a la bonne heure, et pas une seconde fois.
 */
class PatrolLifecycleTest extends AccountTestCase
{
    protected function tearDown(): void
    {
        resolve(SettingsService::class)->set('patrols_enabled', 0);

        parent::tearDown();
    }

    private function arm(): void
    {
        resolve(SettingsService::class)->set('patrols_enabled', 1);
        $this->playerSetResearchLevel('computer_technology', 10);
    }

    private function player(): PlayerService
    {
        return resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);
    }

    private function orders(): PatrolOrders
    {
        return resolve(PatrolOrders::class);
    }

    private function fleet(array $composition): UnitCollection
    {
        $units = new UnitCollection();

        foreach ($composition as $nom => $nombre) {
            $units->addUnit(ObjectService::getUnitObjectByMachineName($nom), $nombre);
        }

        return $units;
    }

    /**
     * Un point libre du systeme de la planete, a l ecart de son orbite.
     */
    private function aFreePoint(): PatrolDestination
    {
        $coords = $this->planetService->getPlanetCoordinates();
        $geometrie = resolve(PatrolPricing::class)->geometry();

        return PatrolDestination::spatialPoint($geometrie, $coords->galaxy, $coords->system, new SpatialPoint(600, 600));
    }

    /**
     * Le travailleur du jeu, celui qui traite toutes les missions.
     */
    private function runTheWorker(): void
    {
        $this->player()->updateFleetMissions();
    }

    /**
     * Lancer une patrouille retire tout, une fois, et rend une ligne coherente.
     */
    public function testLaunchingAPatrolTakesEverythingOnceAndOccupiesASlot(): void
    {
        $this->arm();
        $this->planetAddResources(new Resources(0, 0, 60000, 0));
        $this->planetAddUnit('cruiser', 20);

        $avantDeuterium = $this->planetService->deuterium()->get();
        $avantCroiseurs = $this->planetService->getObjectAmount('cruiser');
        $creneauxAvant = $this->player()->getFleetSlotsInUse();

        $patrouille = $this->orders()->launch(
            $this->planetService,
            $this->fleet(['cruiser' => 20]),
            new Resources(0, 0, 0, 0),
            10000,
            $this->aFreePoint(),
            10,
            (int)Date::now()->timestamp
        );

        $this->planetService->reloadPlanet();

        $this->assertSame(PatrolState::EnRoute, $patrouille->state);
        $this->assertSame($avantCroiseurs - 20, $this->planetService->getObjectAmount('cruiser'), 'The ships were not taken exactly once.');

        $segment = FleetMission::query()->findOrFail($patrouille->current_mission_id);
        $this->assertSame(11, (int)$segment->mission_type);
        $this->assertSame(0, (int)$segment->processed, 'A patrol segment must stay unprocessed: it holds the fleet slot.');
        $this->assertSame(20, (int)$segment->cruiser, 'The ships do not live on the segment.');
        $this->assertSame((int)$this->planetService->getPlanetId(), (int)$segment->planet_id_from, 'The segment is not attached to its home planet: the worker would never find it.');
        $this->assertSame(0.0, (float)$segment->deuterium_consumption, 'The segment claims a planet refund it never took.');

        // La reserve vit sur la patrouille, jamais sur le segment.
        $this->assertGreaterThan(0, (float)$patrouille->fuel_reserve);
        $this->assertSame(0, (int)$segment->deuterium, 'The reserve leaked into the cargo column.');

        // Le deuterium retire vaut la reserve embarquee, et rien de plus : le cout du segment est
        // paye **sur** la reserve, pas une seconde fois sur la planete.
        $this->assertSame($avantDeuterium - 10000, $this->planetService->deuterium()->get(), 'The launch charged the planet twice.');
        // **Le cout se compare a un devis calcule a part.** Comparer « la reserve » a « 10 000 moins
        // la reserve » etait une tautologie : la valeur juste et la valeur fausse y coincidaient, et
        // la mutation qui cesse de payer le segment sur la reserve y survivait.
        $devis = resolve(PatrolPricing::class)->quote(
            $this->player(),
            $this->fleet(['cruiser' => 20]),
            10000.0,
            $this->planetService->getPlanetCoordinates()->galaxy,
            $this->planetService->getPlanetCoordinates()->system,
            resolve(PatrolPricing::class)->geometry()->bodyPoint($this->planetService->getPlanetCoordinates()->position),
            $this->aFreePoint(),
            10,
            1,
            $this->planetService->getPlanetCoordinates()
        );

        $this->assertGreaterThan(0, $devis->fuelCost, 'The leg was free: the witness would prove nothing.');
        $this->assertEqualsWithDelta(
            10000.0 - $devis->fuelCost,
            (float)$patrouille->fuel_reserve,
            0.001,
            'The leg was not paid out of the reserve.'
        );

        // Et le creneau est bien pris.
        $this->assertSame($creneauxAvant + 1, $this->player()->getFleetSlotsInUse(), 'A patrol does not occupy a fleet slot.');
    }

    /**
     * A l arrivee, la patrouille se pose et son prochain rendez-vous est arme.
     */
    public function testOnArrivalThePatrolParksAndItsNextAppointmentIsArmed(): void
    {
        $this->arm();
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
        $arrivee = (int)$segment->time_arrival;

        Date::setTestNow(Date::createFromTimestamp($arrivee + 1));
        $this->runTheWorker();

        $patrouille->refresh();
        $segment->refresh();

        $this->assertSame(PatrolState::Stationed, $patrouille->state, 'The patrol did not park on arrival.');
        $this->assertSame(600, (int)$patrouille->x);
        $this->assertSame(600, (int)$patrouille->y);
        // La facturation demarre a l arrivee **physique**, pas quand le travailleur est passe.
        $this->assertSame($arrivee, (int)$patrouille->upkeep_paid_at, 'A late worker would have offered free stationing.');
        $this->assertSame($arrivee, (int)$patrouille->entered_system_at);

        // Le segment reste non traite, avec le delai jusqu au retour de securite.
        $this->assertSame(0, (int)$segment->processed, 'The parked segment was processed: its fleet slot is gone.');
        $this->assertNotNull($segment->time_holding);
        $this->assertGreaterThan(0, (int)$segment->time_holding);

        // Et un second passage du travailleur ne la repose pas.
        $avant = (int)$segment->time_holding;
        $this->runTheWorker();
        $segment->refresh();
        $this->assertSame($avant, (int)$segment->time_holding, 'A second worker pass parked the patrol again.');
        $this->assertSame(PatrolState::Stationed, $patrouille->refresh()->state);

        Date::setTestNow();
    }

    /**
     * L echeance venue, le retour de securite part avec de quoi rentrer.
     */
    public function testWhenTheReserveRunsLowTheSafetyReturnLeavesWithEnoughToGetHome(): void
    {
        $this->arm();
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
        $this->runTheWorker();

        $patrouille->refresh();
        $segment->refresh();
        $echeance = (int)$segment->time_arrival + (int)$segment->time_holding;
        $reserveAuStationnement = (float)$patrouille->fuel_reserve;

        // L echeance venue, le travailleur reprend le segment.
        Date::setTestNow(Date::createFromTimestamp($echeance + 1));
        $this->runTheWorker();

        $patrouille->refresh();
        $segment->refresh();

        $this->assertSame(PatrolState::Returning, $patrouille->state, 'The safety return did not leave at its appointment.');
        $this->assertSame(1, (int)$segment->processed, 'The parked segment was left unprocessed after the return left.');

        $retour = FleetMission::query()->findOrFail($patrouille->current_mission_id);
        $this->assertNotSame((int)$segment->id, (int)$retour->id, 'The return reused the parked segment.');
        $this->assertSame(20, (int)$retour->cruiser, 'The ships did not move to the return leg.');
        // **L invariant est « un seul segment NON TRAITE les porte »**, pas « une seule ligne les
        // porte » : une mission traitee garde ses colonnes comme trace, exactement comme un aller
        // garde les siennes apres avoir cree son retour. Ce que le jeu lit, ce sont les non traitees.
        $enVol = FleetMission::query()->where('patrol_id', $patrouille->id)->where('processed', 0)->sum('cruiser');
        $this->assertSame(20, (int)$enVol, 'The ships are carried by more than one live segment at once.');
        $this->assertSame((int)$this->planetService->getPlanetId(), (int)$retour->planet_id_to, 'The return does not aim at the home planet.');

        // **Le curseur dit ce que le stationnement a coute**, et lui seul. La reserve baisse de toute
        // facon quand le retour part : mesurer sa seule baisse ne distinguait pas les deux, et la
        // mutation qui saute la facturation y survivait. Le curseur, lui, n avance que si l on facture.
        $this->assertSame(
            $echeance + 1,
            (int)$patrouille->upkeep_paid_at,
            'The stationing was not billed up to the moment the safety return left.'
        );
        $this->assertLessThan($reserveAuStationnement, (float)$patrouille->fuel_reserve, 'Stationing was free.');

        Date::setTestNow();
    }

    /**
     * Rentree chez elle, la patrouille rend tout et cesse d exister.
     */
    public function testComingHomeGivesBackTheShipsTheCargoAndWhatIsLeftOfTheReserve(): void
    {
        $this->arm();
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
        $this->runTheWorker();

        $segment->refresh();
        Date::setTestNow(Date::createFromTimestamp((int)$segment->time_arrival + (int)$segment->time_holding + 1));
        $this->runTheWorker();

        $patrouille->refresh();
        $retour = FleetMission::query()->findOrFail($patrouille->current_mission_id);
        $reserveRestante = (float)$patrouille->fuel_reserve;

        $this->planetService->reloadPlanet();
        $croiseursAvant = $this->planetService->getObjectAmount('cruiser');
        $deuteriumAvant = $this->planetService->deuterium()->get();

        Date::setTestNow(Date::createFromTimestamp((int)$retour->time_arrival + 1));
        $this->runTheWorker();

        $patrouille->refresh();
        $this->planetService->reloadPlanet();

        $this->assertSame(PatrolState::Finished, $patrouille->state, 'The patrol did not finish when it landed.');
        $this->assertNull($patrouille->current_mission_id);
        $this->assertSame('came_home', $patrouille->finish_reason);
        $this->assertSame($croiseursAvant + 20, $this->planetService->getObjectAmount('cruiser'), 'The ships did not come back.');
        $this->assertSame(
            $deuteriumAvant + (int)floor($reserveRestante),
            $this->planetService->deuterium()->get(),
            'What was left of the reserve did not come back with the fleet.'
        );

        Date::setTestNow();
    }

    /**
     * Ce qui reste de la reserve rentre avec la flotte.
     *
     * ## Pourquoi ce temoin est separe du cycle complet
     *
     * A la fin d un retour de securite la reserve vaut a peu pres zero : il part exactement quand
     * elle atteint le cout du retour, et ce retour la consomme. « Rendue » et « perdue » y donnent
     * donc le meme nombre, et la mutation qui ne rend rien y survivait. Ici la patrouille atterrit
     * avec une reserve franche, et l ecart devient visible.
     */
    public function testWhatIsLeftOfTheReserveComesBackWithTheFleet(): void
    {
        $this->arm();
        $this->planetAddUnit('cruiser', 20);
        $this->planetService->reloadPlanet();

        $croiseursAvant = $this->planetService->getObjectAmount('cruiser');
        $deuteriumAvant = $this->planetService->deuterium()->get();

        $patrouille = Patrol::forceCreate([
            'user_id' => $this->currentUserId,
            'home_planet_id' => $this->planetService->getPlanetId(),
            'state' => PatrolState::Returning,
            'galaxy' => $this->planetService->getPlanetCoordinates()->galaxy,
            'system' => $this->planetService->getPlanetCoordinates()->system,
            'fuel_reserve' => 4321.0,
            'order_version' => 1,
        ]);

        $segment = new FleetMission();
        $segment->user_id = $this->currentUserId;
        $segment->patrol_id = (int)$patrouille->id;
        $segment->mission_type = 11;
        $segment->planet_id_from = $this->planetService->getPlanetId();
        $segment->planet_id_to = $this->planetService->getPlanetId();
        $segment->type_from = 1;
        $segment->type_to = 1;
        $segment->galaxy_from = $this->planetService->getPlanetCoordinates()->galaxy;
        $segment->system_from = $this->planetService->getPlanetCoordinates()->system;
        $segment->position_from = $this->planetService->getPlanetCoordinates()->position;
        $segment->galaxy_to = $this->planetService->getPlanetCoordinates()->galaxy;
        $segment->system_to = $this->planetService->getPlanetCoordinates()->system;
        $segment->position_to = $this->planetService->getPlanetCoordinates()->position;
        $segment->time_departure = 1_700_000_000;
        $segment->time_arrival = 1_700_000_600;
        $segment->cruiser = 20;
        $segment->metal = 700;
        $segment->crystal = 0;
        $segment->deuterium = 300;
        $segment->save();

        $this->orders()->land($patrouille, $segment, 1_700_000_601, $this->planetService);

        $this->planetService->reloadPlanet();
        $patrouille->refresh();

        $this->assertSame($croiseursAvant + 20, $this->planetService->getObjectAmount('cruiser'));
        // La cargaison **et** la reserve : 300 de cargaison plus 4 321 de reserve.
        $this->assertSame($deuteriumAvant + 300 + 4321, $this->planetService->deuterium()->get(), 'The leftover reserve did not come back.');
        $this->assertSame(PatrolState::Finished, $patrouille->state);
        $this->assertSame(0.0, (float)$patrouille->fuel_reserve, 'The reserve was returned and kept at once.');
    }

    /**
     * Le stationnement se facture au prorata, et deux facturations ne comptent pas deux fois.
     */
    public function testStationingIsBilledOnceAndProRata(): void
    {
        $this->arm();
        $upkeep = resolve(PatrolUpkeep::class);
        $flotte = $this->fleet(['cruiser' => 20]);

        $patrouille = Patrol::forceCreate([
            'user_id' => $this->currentUserId,
            'home_planet_id' => $this->planetService->getPlanetId(),
            'state' => PatrolState::Stationed,
            'galaxy' => 1,
            'system' => 1,
            'x' => 600,
            'y' => 600,
            'fuel_reserve' => 10000.0,
            'upkeep_paid_at' => 1_700_000_000,
            'order_version' => 1,
        ]);

        // Une heure : trois cents, le taux du jeu divise par vingt.
        $preleve = $this->orders()->bill($patrouille, $flotte, 1_700_003_600);
        $this->assertEqualsWithDelta(300.0, $preleve, 0.001);
        $this->assertEqualsWithDelta(9700.0, (float)$patrouille->fuel_reserve, 0.001);
        $this->assertSame(1_700_003_600, (int)$patrouille->upkeep_paid_at);

        // Le meme instant, une seconde fois : rien de plus.
        $this->assertSame(0.0, $this->orders()->bill($patrouille, $flotte, 1_700_003_600));
        $this->assertEqualsWithDelta(9700.0, (float)$patrouille->fuel_reserve, 0.001);

        // Un instant anterieur ne rend rien et ne recule pas le curseur.
        $this->assertSame(0.0, $this->orders()->bill($patrouille, $flotte, 1_700_000_500));
        $this->assertSame(1_700_003_600, (int)$patrouille->upkeep_paid_at);

        // Et le prelevement ne descend jamais sous zero.
        $this->orders()->bill($patrouille, $flotte, 1_700_003_600 + 3600 * 1000);
        $this->assertSame(0.0, (float)$patrouille->fuel_reserve);

        unset($upkeep);
    }

    /**
     * L interrupteur ferme, aucun ordre ne passe.
     */
    public function testNothingLaunchesWhileTheSwitchIsOff(): void
    {
        resolve(SettingsService::class)->set('patrols_enabled', 0);
        $this->planetAddResources(new Resources(0, 0, 60000, 0));
        $this->planetAddUnit('cruiser', 20);
        $patrouillesAvant = Patrol::query()->count();

        try {
            $this->orders()->launch(
                $this->planetService,
                $this->fleet(['cruiser' => 20]),
                new Resources(0, 0, 0, 0),
                10000,
                $this->aFreePoint(),
                10,
                (int)Date::now()->timestamp
            );
            $this->fail('A patrol launched while the switch is off.');
        } catch (PatrolOrderRefused $refus) {
            $this->assertSame('disabled', $refus->reason);
        }

        // **En ecart, jamais a zero** : la base garde les patrouilles des essais voisins du meme
        // processus, et un comptage absolu prouverait leur absence plutot que la notre.
        $this->assertSame($patrouillesAvant, Patrol::query()->count(), 'A refused launch left a patrol behind.');
    }

    /**
     * Un ordre sans de quoi revenir est refuse, et ne retire rien.
     */
    public function testAnOrderWithoutEnoughFuelToComeBackTakesNothing(): void
    {
        $this->arm();
        $this->planetAddResources(new Resources(0, 0, 60000, 0));
        $this->planetAddUnit('cruiser', 20);
        $this->planetService->reloadPlanet();

        $croiseursAvant = $this->planetService->getObjectAmount('cruiser');
        $deuteriumAvant = $this->planetService->deuterium()->get();
        $patrouillesAvant = Patrol::query()->count();

        try {
            $this->orders()->launch(
                $this->planetService,
                $this->fleet(['cruiser' => 20]),
                new Resources(0, 0, 0, 0),
                10,
                $this->aFreePoint(),
                10,
                (int)Date::now()->timestamp
            );
            $this->fail('A patrol launched without the fuel to come back.');
        } catch (PatrolOrderRefused $refus) {
            $this->assertContains($refus->reason, ['not_enough_fuel', 'no_return_reserve']);
        }

        $this->planetService->reloadPlanet();
        $this->assertSame($croiseursAvant, $this->planetService->getObjectAmount('cruiser'), 'A refused order took the ships anyway.');
        $this->assertSame($deuteriumAvant, $this->planetService->deuterium()->get(), 'A refused order took the fuel anyway.');
        $this->assertSame($patrouillesAvant, Patrol::query()->count(), 'A refused order left a patrol behind.');
    }
}
