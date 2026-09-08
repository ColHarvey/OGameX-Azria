<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\CombatState;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\CombatInstance;
use OGame\Models\FleetMission;
use OGame\Models\Patrol;
use OGame\Models\Resources;
use OGame\Patrol\Enums\PatrolState;
use OGame\Patrol\Geometry\SpatialPoint;
use OGame\Patrol\PatrolDestination;
use OGame\Patrol\PatrolOrders;
use OGame\Patrol\PatrolPricing;
use OGame\Services\ObjectService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;

/**
 * Les ordres donnes depuis la carte, par leurs points d entree HTTP.
 *
 * ## Ce que ces temoins etablissent
 *
 * Que le devis vient du serveur avec sa version ; qu un point hors grille est **refuse**, jamais
 * arrondi ; qu un devis perime est refuse et ne debite rien ; qu une patrouille etrangere n existe
 * pas pour le demandeur ; et — les deux qui ont trouve un defaut — qu un **rappel atterrit** au lieu
 * de se poser a cote de la planete, et qu un retour de securite dont la base a disparu atterrit sur
 * le repli au lieu de viser une planete qui n existe plus.
 */
class PatrolEndpointsTest extends AccountTestCase
{
    private const int CRUISER = 206;

    private const int SOLAR_SATELLITE = 212;

    protected function tearDown(): void
    {
        resolve(SettingsService::class)->set('patrols_enabled', 0);
        Date::setTestNow();

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

    private function fleet(): UnitCollection
    {
        $units = new UnitCollection();
        $units->addUnit(ObjectService::getUnitObjectByMachineName('cruiser'), 20);

        return $units;
    }

    private function pointAt(int $x, int $y): PatrolDestination
    {
        $coords = $this->planetService->getPlanetCoordinates();

        return PatrolDestination::spatialPoint(
            resolve(PatrolPricing::class)->geometry(),
            $coords->galaxy,
            $coords->system,
            new SpatialPoint($x, $y)
        );
    }

    /**
     * @return array{0: Patrol, 1: FleetMission}
     */
    private function aParkedPatrol(): array
    {
        $this->arm();
        $this->planetAddResources(new Resources(0, 0, 60000, 0));
        $this->planetAddUnit('cruiser', 20);

        $patrouille = resolve(PatrolOrders::class)->launch(
            $this->planetService,
            $this->fleet(),
            new Resources(0, 0, 0, 0),
            10000,
            $this->pointAt(600, 600),
            10,
            (int)Date::now()->timestamp
        );

        $segment = FleetMission::query()->findOrFail($patrouille->current_mission_id);

        Date::setTestNow(Date::createFromTimestamp((int)$segment->time_arrival + 1));
        $this->player()->updateFleetMissions();

        return [$patrouille->refresh(), $segment->refresh()];
    }

    /**
     * @return array<string, int>
     */
    private function here(): array
    {
        $coords = $this->planetService->getPlanetCoordinates();

        return ['galaxy' => $coords->galaxy, 'system' => $coords->system];
    }

    /**
     * Le devis d une patrouille posee vient du serveur : point de depart, cout, et version d ordre.
     */
    public function testAQuoteForAParkedPatrolComesFromTheServerWithItsOrderVersion(): void
    {
        [$patrouille] = $this->aParkedPatrol();

        $reponse = $this->postJson(route('galaxy.patrol.quote'), $this->here() + [
            'patrol_id' => $patrouille->id,
            'x' => -600,
            'y' => 600,
            'speed' => 10,
        ])->assertStatus(200)->json();

        $this->assertTrue($reponse['success']);
        $this->assertSame(['x' => 600, 'y' => 600], $reponse['departure'], 'The quote does not leave from where the patrol is.');
        $this->assertTrue($reponse['quote']['possible']);
        $this->assertGreaterThan(0, $reponse['quote']['fuel_cost'], 'Crossing the system is quoted free.');
        $this->assertSame(-600, $reponse['quote']['destination']['x']);
        $this->assertSame((int)$patrouille->order_version, $reponse['quote']['order_version'], 'The quote does not carry the version the confirmation must bring back.');
        $this->assertGreaterThan(0, $reponse['quote']['safety_return_cost']);
        $this->assertIsInt($reponse['quote']['autonomy_seconds']);
        $this->assertNull($reponse['quote']['refusal_reason']);
    }

    /**
     * Le devis refuse ce que le bouton refuse : une patrouille engagee n a pas de devis.
     *
     * Le bouton lit `whyMoveIsRefused()` ; le devis doit le lire aussi, sinon un joueur qui
     * contourne l interface recoit des nombres pour un ordre que la confirmation refusera. Une
     * mutation survivante l a montre.
     */
    public function testAQuoteRefusesWhatTheButtonRefuses(): void
    {
        [$patrouille, $pose] = $this->aParkedPatrol();

        $combat = CombatInstance::create([
            'status' => CombatState::Active,
            'mission_id' => $pose->id,
            'target_planet_id' => null,
            'target_type' => 5,
            'galaxy' => (int)$patrouille->galaxy,
            'system' => (int)$patrouille->system,
            'position' => 9,
            'started_at' => 1_700_000_000,
        ]);
        $pose->forceFill(['combat_instance_id' => $combat->id])->save();

        $reponse = $this->postJson(route('galaxy.patrol.quote'), $this->here() + [
            'patrol_id' => $patrouille->id,
            'x' => -600,
            'y' => 600,
        ])->assertStatus(409)->json();

        $this->assertSame('engaged_in_combat', $reponse['reason_key']);
        $this->assertArrayNotHasKey('quote', $reponse, 'An engaged patrol was quoted anyway.');

        $pose->forceFill(['combat_instance_id' => null])->save();
    }

    /**
     * Un point hors grille, dans l etoile ou hors du systeme est refuse — pas arrondi.
     */
    public function testAnInvalidPointIsRefusedNotSnapped(): void
    {
        [$patrouille] = $this->aParkedPatrol();
        $reserveAvant = (float)$patrouille->fuel_reserve;

        foreach ([[603, 600, 'point_off_grid'], [10, 10, 'point_in_star'], [1800, 1800, 'point_outside_system']] as [$x, $y, $attendu]) {
            $reponse = $this->postJson(route('galaxy.patrol.quote'), $this->here() + [
                'patrol_id' => $patrouille->id,
                'x' => $x,
                'y' => $y,
            ])->assertStatus(422)->json();

            $this->assertFalse($reponse['success']);
            $this->assertSame($attendu, $reponse['reason_key'], 'The point (' . $x . ', ' . $y . ') was refused for another reason.');
            $this->assertStringNotContainsString('t_ingame', $reponse['reason'], 'The refusal is a raw translation key.');
            $this->assertSame($reponse['reason'], $reponse['errors'][0]['message']);
        }

        // Et un ordre sur un tel point ne part pas non plus.
        $this->postJson(route('galaxy.patrol.move', ['patrol' => $patrouille->id]), $this->here() + [
            'x' => 603,
            'y' => 600,
            'order_version' => $patrouille->order_version,
        ])->assertStatus(422);

        $patrouille->refresh();
        $this->assertSame(PatrolState::Stationed, $patrouille->state, 'A refused point moved the patrol anyway.');
        $this->assertSame($reserveAvant, (float)$patrouille->fuel_reserve, 'A refused point charged something.');
    }

    /**
     * Un devis perime est refuse et ne debite rien ; le bon devis fait partir la patrouille.
     */
    public function testAStaleQuoteIsRefusedAndTheRightOneLeaves(): void
    {
        [$patrouille, $segment] = $this->aParkedPatrol();
        $reserveAvant = (float)$patrouille->fuel_reserve;

        $perime = $this->postJson(route('galaxy.patrol.move', ['patrol' => $patrouille->id]), $this->here() + [
            'x' => -600,
            'y' => 600,
            'speed' => 10,
            'order_version' => (int)$patrouille->order_version + 1,
        ])->assertStatus(409)->json();

        $this->assertSame('stale_quote', $perime['reason_key']);

        $patrouille->refresh();
        $this->assertSame(PatrolState::Stationed, $patrouille->state);
        $this->assertSame((int)$segment->id, (int)$patrouille->current_mission_id, 'A stale quote replaced the segment.');
        $this->assertSame($reserveAvant, (float)$patrouille->fuel_reserve, 'A stale quote charged the reserve.');

        $accepte = $this->postJson(route('galaxy.patrol.move', ['patrol' => $patrouille->id]), $this->here() + [
            'x' => -600,
            'y' => 600,
            'speed' => 10,
            'order_version' => (int)$patrouille->order_version,
        ])->assertStatus(200)->json();

        $this->assertTrue($accepte['success']);
        $this->assertSame((int)$patrouille->id, $accepte['patrol_id']);

        $patrouille->refresh();
        $this->assertSame(PatrolState::EnRoute, $patrouille->state, 'The accepted order did not make the patrol leave.');
        $this->assertNotSame((int)$segment->id, (int)$patrouille->current_mission_id, 'The accepted order created no new segment.');
        $this->assertLessThan($reserveAvant, (float)$patrouille->fuel_reserve, 'The accepted leg was free.');
    }

    /**
     * La patrouille d un autre joueur n existe pas pour le demandeur : ni devis, ni ordre, ni rappel.
     */
    public function testAForeignPatrolDoesNotExistForTheRequester(): void
    {
        $this->arm();
        $coords = $this->planetService->getPlanetCoordinates();
        $etrangere = $this->getNearbyForeignPlanet();
        $proprietaire = $etrangere->getPlayer();
        $this->assertNotNull($proprietaire);

        $autre = (int)DB::table('patrols')->insertGetId([
            'user_id' => $proprietaire->getId(),
            'home_planet_id' => $etrangere->getPlanetId(),
            'state' => PatrolState::Stationed->value,
            'galaxy' => $coords->galaxy,
            'system' => $coords->system,
            'x' => 600,
            'y' => -600,
            'current_mission_id' => null,
            'fuel_reserve' => 5000,
            'upkeep_paid_at' => (int)Date::now()->timestamp,
            'stationed_since' => (int)Date::now()->timestamp,
            'order_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson(route('galaxy.patrol.quote'), $this->here() + ['patrol_id' => $autre, 'x' => -600, 'y' => 600])->assertStatus(404);
        $this->postJson(route('galaxy.patrol.move', ['patrol' => $autre]), $this->here() + ['x' => -600, 'y' => 600, 'order_version' => 1])->assertStatus(404);
        $this->postJson(route('galaxy.patrol.recall', ['patrol' => $autre]), [])->assertStatus(404);

        $this->assertSame(PatrolState::Stationed->value, DB::table('patrols')->where('id', $autre)->value('state'), 'A stranger moved the patrol.');
    }

    /**
     * Un rappel **atterrit** : les vaisseaux et ce qui reste de la reserve rentrent sur la planete.
     *
     * ## Le defaut que ce temoin a trouve
     *
     * Le rappel passait par l ordre de mouvement ordinaire, qui laisse la patrouille « en vol » ; a
     * l arrivee, le travailleur la **posait** a cote de sa planete, comme n importe quelle
     * destination. Le temoin du rappel s arretait au depart : segment vers la base, cout preleve. Il
     * ne faisait jamais tourner le travailleur jusqu au sol.
     */
    public function testARecallLandsTheFleetOnItsHomePlanet(): void
    {
        [$patrouille, $pose] = $this->aParkedPatrol();

        $this->planetService->reloadPlanet();
        $croiseursAvant = $this->planetService->getObjectAmount('cruiser');
        $deuteriumAvant = $this->planetService->deuterium()->get();

        $reponse = $this->postJson(route('galaxy.patrol.recall', ['patrol' => $patrouille->id]), [])
            ->assertStatus(200)
            ->json();

        $this->assertTrue($reponse['success']);

        $patrouille->refresh();
        $this->assertSame(PatrolState::Returning, $patrouille->state, 'A recalled patrol is not returning: on arrival it would park, not land.');
        $this->assertSame(1, (int)$pose->refresh()->processed, 'The parked segment survived the recall.');

        $retour = FleetMission::query()->findOrFail($patrouille->current_mission_id);
        $this->assertSame((int)$this->planetService->getPlanetId(), (int)$retour->planet_id_to, 'The recall does not aim at the home planet.');
        $reserveEnVol = (float)$patrouille->fuel_reserve;

        Date::setTestNow(Date::createFromTimestamp((int)$retour->time_arrival + 1));
        $this->player()->updateFleetMissions();

        $patrouille->refresh();
        $this->planetService->reloadPlanet();

        $this->assertSame(PatrolState::Finished, $patrouille->state, 'The recalled patrol did not land: it parked next to its planet.');
        $this->assertSame('came_home', $patrouille->finish_reason);
        $this->assertNull($patrouille->current_mission_id);
        $this->assertSame($croiseursAvant + 20, $this->planetService->getObjectAmount('cruiser'), 'The recalled ships did not come back.');
        $this->assertSame(
            $deuteriumAvant + (int)floor($reserveEnVol),
            $this->planetService->deuterium()->get(),
            'What was left of the reserve did not come back with the recalled fleet.'
        );
        $this->assertSame(0, FleetMission::query()->where('patrol_id', $patrouille->id)->where('processed', 0)->count(), 'A segment of the finished patrol is still alive.');
    }

    /**
     * Le retour de securite d une patrouille sans base atterrit sur le repli — et y arrive.
     *
     * ## Le defaut que ce temoin a trouve
     *
     * Le retour visait `home_planet_id` a cote des coordonnees du repli : lien tombe a vide,
     * `(int)null` donnait 0, et l atterrissage levait une exception ; base marquee detruite mais
     * ligne encore la, la flotte atterrissait sur une planete detruite. Le repli n etait consulte
     * que pour les coordonnees.
     */
    public function testTheSafetyReturnOfAnOrphanPatrolLandsOnTheFallbackPlanet(): void
    {
        [$patrouille, $pose] = $this->aParkedPatrol();

        // Le lien vers la base tombe a vide, comme la cle etrangere le fait a sa destruction.
        $patrouille->forceFill(['home_planet_id' => null])->save();

        $rendezVous = (int)$pose->time_arrival + (int)$pose->time_holding;
        Date::setTestNow(Date::createFromTimestamp($rendezVous + 1));
        $this->player()->updateFleetMissions();

        $patrouille->refresh();
        $this->assertSame(PatrolState::Returning, $patrouille->state, 'The safety return did not leave.');

        $retour = FleetMission::query()->findOrFail($patrouille->current_mission_id);

        // **Le repli est une planete vivante du joueur** — la plus proche, qui peut etre l une ou
        // l autre des deux planetes du compte du banc selon ou elles sont nees. Le temoin exige la
        // regle, pas un identifiant : ni zero, ni la base morte, ni la planete d un autre.
        $vivantes = DB::table('planets')->where('user_id', $this->currentUserId)->where('destroyed', 0)->pluck('id')->map(static fn (mixed $id): int => (int)$id)->all();
        $this->assertNotEmpty($vivantes);
        $this->assertContains((int)$retour->planet_id_to, $vivantes, 'The return aims at a planet that is not a living planet of the player.');
        $this->assertContains((int)$retour->planet_id_from, $vivantes, 'The segment is anchored on a body the worker would never find.');

        $repli = resolve(PlayerServiceFactory::class)->make($this->currentUserId, true)->planets->getById((int)$retour->planet_id_to);
        $repli->reloadPlanet();
        $croiseursAvant = $repli->getObjectAmount('cruiser');

        Date::setTestNow(Date::createFromTimestamp((int)$retour->time_arrival + 1));
        $this->player()->updateFleetMissions();

        $patrouille->refresh();
        $repli->reloadPlanet();

        $this->assertSame(PatrolState::Finished, $patrouille->state, 'The orphan patrol never landed.');
        $this->assertSame($croiseursAvant + 20, $repli->getObjectAmount('cruiser'), 'The orphan fleet did not come back to the fallback planet.');
    }

    /**
     * Un lancement depuis la carte cree la patrouille et debite la planete une fois.
     */
    public function testALaunchFromTheMapCreatesAPatrolAndDebitsThePlanet(): void
    {
        $this->arm();
        $this->planetAddResources(new Resources(0, 0, 60000, 0));
        $this->planetAddUnit('cruiser', 25);

        $this->planetService->reloadPlanet();
        $croiseursAvant = $this->planetService->getObjectAmount('cruiser');
        $deuteriumAvant = $this->planetService->deuterium()->get();

        $devis = $this->postJson(route('galaxy.patrol.quote'), $this->here() + [
            'am' . self::CRUISER => 20,
            'reserve' => 10000,
            'x' => 600,
            'y' => 600,
            'speed' => 10,
        ])->assertStatus(200)->json();

        $this->assertTrue($devis['quote']['possible']);
        $this->assertSame(1, $devis['quote']['order_version']);
        $this->assertGreaterThan(0, $devis['quote']['fuel_cost']);

        $reponse = $this->postJson(route('galaxy.patrol.launch'), $this->here() + [
            'am' . self::CRUISER => 20,
            'reserve' => 10000,
            'x' => 600,
            'y' => 600,
            'speed' => 10,
        ])->assertStatus(200)->json();

        $this->assertTrue($reponse['success']);

        $patrouille = Patrol::query()->whereKey((int)$reponse['patrol_id'])->firstOrFail();
        $this->assertSame($this->currentUserId, (int)$patrouille->user_id);
        $this->assertSame(PatrolState::EnRoute, $patrouille->state);
        $this->assertEqualsWithDelta(10000 - $devis['quote']['fuel_cost'], (float)$patrouille->fuel_reserve, 0.01, 'The reserve is not the quoted one.');

        $this->planetService->reloadPlanet();
        $this->assertSame($croiseursAvant - 20, $this->planetService->getObjectAmount('cruiser'), 'The planet did not lose the ships that left.');
        $this->assertSame($deuteriumAvant - 10000, $this->planetService->deuterium()->get(), 'The planet did not pay the reserve, or paid something else.');
    }

    /**
     * Un satellite solaire ne patrouille pas : le refus vient avant tout nombre, et par la meme
     * regle que la page Flotte.
     */
    public function testALaunchQuoteRefusesAnImmobileShipBeforeAnyNumber(): void
    {
        $this->arm();
        $this->planetAddResources(new Resources(0, 0, 60000, 0));
        $this->planetAddUnit('cruiser', 20);
        $this->planetAddUnit('solar_satellite', 1);

        $reponse = $this->postJson(route('galaxy.patrol.quote'), $this->here() + [
            'am' . self::CRUISER => 20,
            'am' . self::SOLAR_SATELLITE => 1,
            'reserve' => 10000,
            'x' => 600,
            'y' => 600,
        ])->assertStatus(409)->json();

        $this->assertSame('immobile_unit', $reponse['reason_key']);
        $this->assertArrayNotHasKey('quote', $reponse, 'A refused launch still shows numbers.');

        $this->postJson(route('galaxy.patrol.launch'), $this->here() + [
            'am' . self::CRUISER => 20,
            'am' . self::SOLAR_SATELLITE => 1,
            'reserve' => 10000,
            'x' => 600,
            'y' => 600,
        ])->assertStatus(409);

        $this->assertSame(0, Patrol::query()->where('user_id', $this->currentUserId)->count(), 'A refused launch created a patrol.');
    }

    /**
     * Une destination « pres d un corps » est resolue par le serveur ; un corps absent n en est pas une.
     */
    public function testABodyDestinationIsResolvedByTheServer(): void
    {
        [$patrouille] = $this->aParkedPatrol();
        $coords = $this->planetService->getPlanetCoordinates();

        $reponse = $this->postJson(route('galaxy.patrol.quote'), $this->here() + [
            'patrol_id' => $patrouille->id,
            'position' => $coords->position,
            'type' => 1,
        ])->assertStatus(200)->json();

        $this->assertSame(1, $reponse['quote']['destination']['type']);
        $this->assertSame((int)$this->planetService->getPlanetId(), $reponse['quote']['destination']['body_id'], 'The body was not resolved to the planet at those coordinates.');

        // Une position sans planete : aucune destination.
        $vide = null;

        for ($position = 1; $position <= 15; $position++) {
            if (DB::table('planets')->where('galaxy', $coords->galaxy)->where('system', $coords->system)->where('planet', $position)->doesntExist()) {
                $vide = $position;
                break;
            }
        }

        $this->assertNotNull($vide, 'The scenario needs an empty position in the home system.');

        $refus = $this->postJson(route('galaxy.patrol.quote'), $this->here() + [
            'patrol_id' => $patrouille->id,
            'position' => $vide,
            'type' => 1,
        ])->assertStatus(422)->json();

        $this->assertSame('bad_destination', $refus['reason_key']);
    }
}
