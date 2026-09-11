<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\CombatState;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\CombatInstance;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Patrol;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Patrol\Enums\PatrolState;
use OGame\Patrol\Exceptions\PatrolOrderRefused;
use OGame\Patrol\Geometry\SpatialPoint;
use OGame\Patrol\PatrolDestination;
use OGame\Patrol\PatrolOrders;
use OGame\Patrol\PatrolPricing;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
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

    /**
     * La charge d un lancement depuis la planete du banc.
     *
     * @return array<string, int>
     */
    private function launchPayload(int $reserve = 10000): array
    {
        return $this->here() + [
            'planet_id' => (int)$this->planetService->getPlanetId(),
            'am' . self::CRUISER => 20,
            'reserve' => $reserve,
            'x' => 600,
            'y' => 600,
            'speed' => 10,
        ];
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
     * Une patrouille posee pres d une planete etrangere reste invisible a son proprietaire.
     *
     * ## La fuite que ce temoin ferme
     *
     * Un segment de flotte inscrit son corps d arrivee, et le jeu rend a chaque joueur **toute
     * mission qui arrive sur une de ses planetes** : c est ainsi qu une attaque s annonce. Une
     * patrouille qui stationne au **voisinage** d un corps n y arrive pas, mais elle y inscrivait
     * quand meme l identite du corps : le proprietaire la voyait dans sa boite d evenements et sur
     * sa carte, **sans aucun detecteur**, avec son genre, ses deux instants et ses deux bouts. La
     * detection doit etre le seul chemin par lequel une patrouille etrangere se montre.
     *
     * ## Ce que ce temoin exige, et pourquoi il passe par le vrai parcours
     *
     * Une ligne fabriquee a la main dans un essai ne prouve rien d une correction **a l ecriture** :
     * elle porterait ce que l essai lui donne. Le lancement passe donc par le point d entree du jeu,
     * avec une destination « pres de ce corps », et le temoin lit ensuite ce que le serveur a ecrit.
     *
     * Il exige aussi que **la base de suivi survive** : le segment garde sa planete de depart, et la
     * patrouille se pose vraiment a l arrivee — c est par cette ancre, et non par la destination, que
     * le travailleur des pages retrouve la mission.
     */
    public function testAPatrolStationedNextToAStrangerPlanetStaysInvisibleToThatStranger(): void
    {
        $this->arm();
        $this->planetAddResources(new Resources(0, 0, 200000, 0));
        $this->planetAddUnit('cruiser', 20);

        $etrangere = $this->getNearbyForeignPlanet();
        $proprietaire = $etrangere->getPlayer();
        $this->assertNotNull($proprietaire, 'The foreign planet has no owner.');
        $this->assertNotSame($this->currentUserId, $proprietaire->getId(), 'The « foreign » planet belongs to the bench player.');

        $ici = (int)$this->planetService->getPlanetId();
        $coords = $etrangere->getPlanetCoordinates();

        // **Ce que l etranger voit deja n appartient pas a cet essai.** La base est partagee entre les
        // essais d un processus, et il peut avoir ses propres flottes en vol : compter zero
        // supposerait l etat de ses voisins. On mesure donc l ecart que la patrouille creuse, qui doit
        // etre nul.
        $moi = User::findOrFail($this->currentUserId);
        $this->be(User::findOrFail($proprietaire->getId()));
        $boiteAvant = $this->getJson('/ajax/fleet/eventbox/fetch')->assertStatus(200)->json();
        $compteAvant = (int)($boiteAvant['friendly'] ?? 0) + (int)($boiteAvant['hostile'] ?? 0) + (int)($boiteAvant['neutral'] ?? 0);
        $this->be($moi);

        $reponse = $this->postJson(route('galaxy.patrol.launch'), [
            'galaxy' => $coords->galaxy,
            'system' => $coords->system,
            'position' => $coords->position,
            'type' => PlanetType::Planet->value,
            'planet_id' => $ici,
            'am' . self::CRUISER => 20,
            'reserve' => 15000,
            'speed' => 10,
        ]);

        $reponse->assertStatus(200);
        $reponse->assertJson(['success' => true]);

        $patrouille = Patrol::query()->where('user_id', $this->currentUserId)->orderByDesc('id')->firstOrFail();
        $segment = FleetMission::query()->whereKey($patrouille->current_mission_id)->firstOrFail();

        // Le fait serveur : le segment ne nomme aucun corps d arrivee, et garde sa base de suivi.
        $this->assertNull($segment->planet_id_to, 'The patrol leg names the stranger body it merely parks next to.');
        $this->assertSame($ici, (int)$segment->planet_id_from, 'The patrol leg lost the base the worker finds it by.');
        $this->assertSame($coords->galaxy, (int)$segment->galaxy_to, 'The leg does not even go there: the scenario would prove nothing.');
        $this->assertSame($coords->system, (int)$segment->system_to);

        // Le proprietaire de la planete visee ne voit rien, ni sur sa carte ni dans ses evenements.
        $this->be(User::findOrFail($proprietaire->getId()));

        $couche = $this->getJson(route('galaxy.fleets', ['galaxy' => $coords->galaxy, 'system' => $coords->system]))
            ->assertStatus(200)
            ->json();

        $mouvements = $couche['movements'] ?? [];
        $patrouilles = $couche['patrols'] ?? [];
        $this->assertSame([], array_values(array_filter($mouvements, static fn (array $m): bool => (int)($m['id'] ?? 0) === (int)$segment->id)), 'The stranger sees the patrol leg as a movement, with no detector at all.');
        $this->assertSame([], $patrouilles, 'The stranger is served a patrol layer that is not his.');

        $liste = (string)$this->get('/ajax/fleet/eventlist/fetch')->assertStatus(200)->getContent();
        $this->assertStringNotContainsString('timer_' . $segment->id, $liste, 'The stranger event list carries a timer for the patrol leg.');

        // Et la boite ne compte pas cette patrouille parmi les flottes qui le concernent.
        $boite = $this->getJson('/ajax/fleet/eventbox/fetch')->assertStatus(200)->json();
        $this->assertSame($compteAvant, (int)($boite['friendly'] ?? 0) + (int)($boite['hostile'] ?? 0) + (int)($boite['neutral'] ?? 0), 'The stranger event box counts a fleet that is only a patrol parking nearby.');

        // Le proprietaire de la patrouille, lui, la voit — la correction ne masque pas tout.
        $this->be($moi);
        $sienne = $this->getJson(route('galaxy.fleets', ['galaxy' => $coords->galaxy, 'system' => $coords->system]))
            ->assertStatus(200)
            ->json();
        $this->assertNotSame([], array_values(array_filter($sienne['movements'] ?? [], static fn (array $m): bool => (int)($m['id'] ?? 0) === (int)$segment->id)), 'The owner lost sight of his own patrol leg.');

        // La base de suivi tient : la patrouille se pose vraiment a l arrivee.
        Date::setTestNow(Date::createFromTimestamp((int)$segment->time_arrival));
        $this->get('/overview')->assertStatus(200);

        $patrouille->refresh();
        $this->assertSame(PatrolState::Stationed, $patrouille->state, 'The patrol never parked: the worker no longer finds a leg without a destination body.');
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
        $this->postJson(route('galaxy.patrol.recall', ['patrol' => $autre]), ['order_version' => 1])->assertStatus(404);

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

        // **Le parcours entier de la carte** : le devis, puis la confirmation qui rapporte ce qu il
        // annoncait. Confirmer sans avoir lu le devis laissait passer un rappel qui volait a une
        // autre vitesse que celle annoncee — une mutation l a montre.
        $devis = $this->postJson(route('galaxy.patrol.quote'), [
            'patrol_id' => $patrouille->id,
            'kind' => 'recall',
        ])->assertStatus(200)->json();

        $reponse = $this->postJson(route('galaxy.patrol.recall', ['patrol' => $patrouille->id]), [
            'order_version' => (int)$devis['quote']['order_version'],
            'quoted_fuel_cost' => (int)$devis['quote']['fuel_cost'],
        ])->assertStatus(200)->json();

        $this->assertTrue($reponse['success']);

        $patrouille->refresh();
        $this->assertSame(PatrolState::Returning, $patrouille->state, 'A recalled patrol is not returning: on arrival it would park, not land.');
        $this->assertSame(1, (int)$pose->refresh()->processed, 'The parked segment survived the recall.');

        $retour = FleetMission::query()->findOrFail($patrouille->current_mission_id);
        $this->assertSame((int)$this->planetService->getPlanetId(), (int)$retour->planet_id_to, 'The recall does not aim at the home planet.');

        // Le vol est celui du devis : meme duree, au delai de manoeuvre pres (nul depuis un stationnement).
        $this->assertSame(
            (int)$devis['quote']['duration_seconds'],
            (int)$retour->time_arrival - (int)$retour->time_departure,
            'The recall flies at another speed than the one it was quoted at.'
        );

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

        $devis = $this->postJson(route('galaxy.patrol.quote'), $this->launchPayload())
            ->assertStatus(200)
            ->json();

        $this->assertTrue($devis['quote']['possible']);
        $this->assertSame(1, $devis['quote']['order_version']);
        $this->assertGreaterThan(0, $devis['quote']['fuel_cost']);

        $reponse = $this->postJson(route('galaxy.patrol.launch'), $this->launchPayload())
            ->assertStatus(200)
            ->json();

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

        $avec = $this->launchPayload() + ['am' . self::SOLAR_SATELLITE => 1];

        $reponse = $this->postJson(route('galaxy.patrol.quote'), $avec)->assertStatus(409)->json();

        $this->assertSame('immobile_unit', $reponse['reason_key']);
        $this->assertArrayNotHasKey('quote', $reponse, 'A refused launch still shows numbers.');

        $this->postJson(route('galaxy.patrol.launch'), $avec)->assertStatus(409);

        $this->assertSame(0, Patrol::query()->where('user_id', $this->currentUserId)->count(), 'A refused launch created a patrol.');
    }

    /**
     * Un corps du joueur qui n est **pas** celui d ou la patrouille est partie.
     *
     * Le nommer par « le second » plutot que par un identifiant ecrit garde le temoin lisible quand
     * le banc change de decor ; et l essai s arrete si le compte n en a qu un, au lieu de retomber
     * sur la base et de ne plus rien distinguer.
     */
    private function anotherBodyOfMine(): PlanetService
    {
        $base = (int)$this->planetService->getPlanetId();

        foreach ($this->player()->planets->all() as $corps) {
            if ((int)$corps->getPlanetId() !== $base) {
                return $corps;
            }
        }

        $this->fail('Le compte du banc n a qu un corps : cet essai ne distinguerait pas un atterrissage choisi d un rappel.');
    }

    /**
     * **Une patrouille part vers un autre systeme, et elle y arrive.**
     *
     * Demande de Keven, 11 septembre 2026. La tarification le prevoyait deja —
     * `PatrolPricing::distanceBetween()` bascule sur les regles de distance du jeu des que la galaxie
     * ou le systeme different —, mais « c est prevu dans le code » n est pas « le joueur peut le
     * faire » : trois blocages totaux de ce chantier ont ete trouves exactement dans cet ecart.
     *
     * Ce temoin traverse donc le parcours reel : devis, confirmation, puis le vrai travailleur a
     * l heure de l arrivee.
     *
     * ## Ce qu il exige au-dela de « ca ne refuse pas »
     *
     * Le segment doit **viser l autre systeme** — un ordre accepte qui poserait la flotte dans le
     * systeme de depart serait vert sur un simple « pas de refus ». Et le trajet doit couter plus
     * cher qu un trajet interne : sans cela, la distance intersysteme ne serait pas comptee, et la
     * valeur juste coinciderait avec la fausse.
     */
    public function testUnePatrouillePartVersUnAutreSystemeEtSYPose(): void
    {
        $this->arm();
        $this->planetAddResources(new Resources(0, 0, 200000, 0));
        $this->planetAddUnit('cruiser', 20);

        $coords = $this->planetService->getPlanetCoordinates();
        $ailleurs = $coords->system + 1;

        $ici = $this->launchPayload();
        $labas = $this->launchPayload();
        $labas['system'] = $ailleurs;

        $interne = $this->postJson(route('galaxy.patrol.quote'), $ici)->assertStatus(200)->json();

        $lointain = $this->postJson(route('galaxy.patrol.quote'), $labas)->assertStatus(200)->json();

        $this->assertTrue((bool)$lointain['quote']['possible'], 'Le devis vers un autre systeme est refuse : ' . ($lointain['quote']['refusal_reason'] ?? ''));

        $this->assertGreaterThan(
            (int)$interne['quote']['distance'],
            (int)$lointain['quote']['distance'],
            'Un trajet vers un autre systeme ne coute pas plus loin qu un trajet interne : la distance intersysteme n est pas comptee.'
        );

        // Le lancement reel, puis l arrivee par le vrai travailleur.
        $this->postJson(route('galaxy.patrol.launch'), $labas + ['order_version' => (int)$lointain['quote']['order_version']])->assertStatus(200);

        $patrouille = Patrol::query()->where('user_id', $this->currentUserId)->latest('id')->first();

        $this->assertNotNull($patrouille, 'Aucune patrouille n a ete creee.');
        $this->assertSame($ailleurs, (int)$patrouille->system, 'La patrouille n est pas partie vers le systeme demande.');

        $segment = FleetMission::query()->findOrFail($patrouille->current_mission_id);

        $this->assertSame($ailleurs, (int)$segment->system_to, 'Le segment ne vise pas l autre systeme.');
        $this->assertSame((int)$coords->system, (int)$segment->system_from, 'Le segment ne part pas du systeme de la base.');

        Date::setTestNow(Date::createFromTimestamp((int)$segment->time_arrival + 1));
        $this->player()->updateFleetMissions();

        $this->assertSame(
            PatrolState::Stationed,
            $patrouille->refresh()->state,
            'La patrouille n est pas posee a l arrivee dans l autre systeme.'
        );
        $this->assertSame($ailleurs, (int)$patrouille->system, 'La patrouille a change de systeme en arrivant.');
    }

    /**
     * **Une patrouille se pose sur un corps choisi, et cesse d exister.** Le geste que Keven decrit :
     * on depose la flotte sur une de ses planetes, elle y rentre et la patrouille est dissoute.
     *
     * ## Pourquoi ce temoin va jusqu au sol
     *
     * Un essai qui s arreterait a « l ordre est accepte » ne prouverait pas le parcours : c est
     * l arrivee qui credite, et c est elle qui a deja echoue une fois — un rappel parti « en vol »
     * se posait **a cote** de la planete au lieu d y rendre ses vaisseaux. Le temoin traverse donc
     * le vrai travailleur et compte les vaisseaux sur le corps choisi.
     *
     * Il vise **l autre** corps, jamais la base : viser la base rendrait ce temoin indistinguable de
     * celui du rappel, et une regression qui ramenerait tout le monde a `homeOf()` y survivrait.
     */
    public function testUnePatrouilleSePoseSurUnCorpsChoisiEtCesseDExister(): void
    {
        [$patrouille] = $this->aParkedPatrol();
        $autre = $this->anotherBodyOfMine();

        $devis = $this->postJson(route('galaxy.patrol.quote'), [
            'patrol_id' => $patrouille->id,
            'kind' => 'land',
            'body_id' => $autre->getPlanetId(),
        ])->assertStatus(200)->json();

        $this->assertSame(
            (int)$autre->getPlanetId(),
            (int)$devis['quote']['destination']['body_id'],
            'Le devis ne vise pas le corps demande.'
        );

        // **L attendu vient de l entree, pas du resultat** : ce que la patrouille emporte est ce
        // qu elle doit rendre. Ecrire un nombre en dur ferait passer le temoin pour une flotte et
        // rougir pour une autre, sans que la regle ait bouge.
        $embarques = $this->fleet()->getAmount();
        $avant = $autre->getObjectAmount('cruiser');

        $this->postJson(route('galaxy.patrol.land', ['patrol' => $patrouille->id]), [
            'body_id' => $autre->getPlanetId(),
            'order_version' => (int)$patrouille->order_version,
        ])->assertStatus(200);

        $patrouille->refresh();
        $this->assertSame(PatrolState::Returning, $patrouille->state, 'L ordre accepte n a pas fait partir la patrouille.');

        // **Jusqu au sol** : le vrai travailleur, a l heure de l arrivee.
        $segment = FleetMission::query()->findOrFail($patrouille->current_mission_id);
        Date::setTestNow(Date::createFromTimestamp((int)$segment->time_arrival + 1));
        $this->player()->updateFleetMissions();

        $this->assertSame(
            PatrolState::Finished,
            $patrouille->refresh()->state,
            'La patrouille existe encore apres s etre posee.'
        );

        $apres = resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);
        $corps = null;

        foreach ($apres->planets->all() as $p) {
            if ((int)$p->getPlanetId() === (int)$autre->getPlanetId()) {
                $corps = $p;
            }
        }

        $this->assertNotNull($corps, 'Le corps choisi a disparu du compte.');
        $this->assertSame(
            $avant + $embarques,
            $corps->getObjectAmount('cruiser'),
            'Les vaisseaux ne sont pas rentres sur le corps choisi.'
        );
    }

    /**
     * **Se poser sur le corps d un autre est refuse**, et le refus le dit sans rien apprendre.
     *
     * Le joueur voit deja ce corps dans sa Galaxie : lui dire « il n est pas a vous » n ajoute aucune
     * information. Ce qui compte est que rien ne parte — une flotte posee chez un adversaire serait
     * un cadeau.
     */
    public function testSePoserSurLeCorpsDUnAutreEstRefuse(): void
    {
        [$patrouille, $segment] = $this->aParkedPatrol();
        $etranger = $this->getNearbyForeignPlanet();
        $reserveAvant = (float)$patrouille->fuel_reserve;

        $this->postJson(route('galaxy.patrol.quote'), [
            'patrol_id' => $patrouille->id,
            'kind' => 'land',
            'body_id' => $etranger->getPlanetId(),
        ])->assertStatus(409)->assertJsonPath('reason_key', 'bad_origin');

        $this->postJson(route('galaxy.patrol.land', ['patrol' => $patrouille->id]), [
            'body_id' => $etranger->getPlanetId(),
            'order_version' => (int)$patrouille->order_version,
        ])->assertStatus(409);

        $patrouille->refresh();
        $this->assertSame(PatrolState::Stationed, $patrouille->state, 'Un atterrissage refuse a quand meme fait partir la patrouille.');
        $this->assertSame((int)$segment->id, (int)$patrouille->current_mission_id, 'Un atterrissage refuse a remplace le segment.');
        $this->assertSame($reserveAvant, (float)$patrouille->fuel_reserve, 'Un atterrissage refuse a debite la reserve.');
    }

    /**
     * **Et le service refuse aussi, pour son propre compte.**
     *
     * Le point d entree filtre deja : `landingBodyFrom()` ne resout un corps que parmi ceux du
     * joueur, donc `not_your_body` n est **pas atteignable par HTTP** aujourd hui. C est une defense
     * en profondeur, et elle se garde : le service est appele ailleurs — le banc, l administration,
     * un futur point d entree — et le jour ou le filtre du controleur s assouplirait, c est elle qui
     * empecherait une flotte d atterrir chez un adversaire.
     *
     * **Ce temoin existe parce qu une mutation a survecu.** Supprimer le controle de propriete du
     * service ne faisait rougir personne : l essai voisin lit `bad_origin`, le refus du controleur,
     * et n atteint jamais la seconde garde. Une garde sans temoin n est pas une garde.
     */
    public function testLeServiceRefuseAussiDePoserLaFlotteChezUnAutre(): void
    {
        [$patrouille] = $this->aParkedPatrol();
        $etranger = $this->getNearbyForeignPlanet();
        $ordres = resolve(PatrolOrders::class);
        $maintenant = (int)Date::now()->timestamp;

        $this->assertSame(
            'not_your_body',
            $ordres->whyLandingIsRefused($patrouille, $etranger, $maintenant),
            'Le service accepte de poser la flotte sur le corps d un autre joueur.'
        );

        // La premisse du temoin : sur un corps a soi, le meme appel ne refuse rien.
        $this->assertNull(
            $ordres->whyLandingIsRefused($patrouille, $this->anotherBodyOfMine(), $maintenant),
            'Le service refuse aussi un corps du joueur : le refus ne distingue plus rien.'
        );

        $this->expectException(PatrolOrderRefused::class);
        $ordres->landOn($patrouille, $etranger, (int)$patrouille->order_version, $maintenant);
    }

    /**
     * Le devis d un rappel est celui de l ordre : **la pleine vitesse**, et la base que le serveur
     * resout — pas ce qu une requete propose.
     *
     * ## Le defaut que ce temoin a trouve
     *
     * Le rappel etait chiffre a 100 % vers un corps que la carte composait de coordonnees, en
     * supposant une planete. L ordre confirme, lui, volait vers `homeOf()`. Le joueur lisait donc
     * une duree fausse et une destination approximative, et le verdict `possible` du devis pouvait
     * differer de celui de la confirmation.
     *
     * ## Pourquoi la valeur attendue a change le 11 septembre 2026
     *
     * Ce temoin exigeait `patrolSafetyReturnSpeed()` des deux cotes, et il avait raison sur le fond :
     * le devis doit dire ce que l ordre fera. Mais **la valeur commune etait la mauvaise**. Les 30 %
     * du retour de securite ont une raison qui ne vaut que pour l urgence — une patrouille dont la
     * reserve touche le strict necessaire vole lentement parce qu un vol lent consomme moins. Un
     * rappel n a pas ce probleme : le joueur a du carburant et veut sa flotte. Il payait pourtant la
     * lenteur de l urgence **sans que sa reserve soit jamais consultee**, et un aller de huit minutes
     * rentrait en vingt-six. Constate en jeu par Keven, sous maintenance, au premier controle
     * navigateur.
     *
     * L exigence, elle, n a pas bouge : le devis dit ce que l ordre fera, et il le dit maintenant sur
     * la bonne vitesse.
     */
    public function testARecallIsQuotedAtFullSpeedTowardTheHomeTheServerResolves(): void
    {
        [$patrouille] = $this->aParkedPatrol();
        $coords = $this->planetService->getPlanetCoordinates();

        $rappel = $this->postJson(route('galaxy.patrol.quote'), [
            'patrol_id' => $patrouille->id,
            'kind' => 'recall',
            // Ce que la requete propose est ignore : ni cette vitesse, ni cette destination. Elle
            // demande ici la vitesse du secours, precisement pour qu une reponse qui la lirait soit
            // indistinguable d une regression — et le temoin la refuse deux lignes plus bas.
            'speed' => 3,
            'x' => -600,
            'y' => 600,
        ])->assertStatus(200)->json();

        $vitesseDuSecours = resolve(SettingsService::class)->patrolSafetyReturnSpeed();
        $this->assertLessThan(PatrolOrders::RECALL_SPEED, $vitesseDuSecours, 'The witness needs a safety return slower than a recall to tell the two apart.');

        $this->assertEqualsWithDelta(PatrolOrders::RECALL_SPEED, (float)$rappel['quote']['speed_percent'], 0.001, 'The recall is quoted at a speed that is not the recall speed.');
        $this->assertNotEqualsWithDelta($vitesseDuSecours, (float)$rappel['quote']['speed_percent'], 0.001, 'The recall is still quoted at the speed of the emergency return.');
        $this->assertSame($coords->position, $rappel['quote']['destination']['orbit'], 'The recall is not quoted toward the home body.');
        $this->assertSame((int)$this->planetService->getPlanetId(), $rappel['quote']['destination']['body_id'], 'The recall destination is not the home body itself.');

        // **L attendu se calcule a part** : le meme trajet demande a 100 % doit rendre exactement les
        // memes nombres. C est cela, « a pleine vitesse » — une comparaison plus large (« plus court
        // que le secours ») laisserait passer n importe quelle valeur intermediaire.
        $centPourCent = $this->postJson(route('galaxy.patrol.quote'), $this->here() + [
            'patrol_id' => $patrouille->id,
            'position' => $coords->position,
            'type' => 1,
            'speed' => 10,
        ])->assertStatus(200)->json();

        $this->assertSame($rappel['quote']['distance'], $centPourCent['quote']['distance'], 'The witness compares two different journeys.');
        $this->assertGreaterThan(0, (int)$centPourCent['quote']['duration_seconds'], 'A journey of no duration would tell the two speeds apart by nothing.');
        $this->assertSame($centPourCent['quote']['duration_seconds'], $rappel['quote']['duration_seconds'], 'The recall is not quoted as fast as a full-speed leg.');
        $this->assertSame($centPourCent['quote']['fuel_cost'], $rappel['quote']['fuel_cost'], 'The recall is not quoted at the price of a full-speed leg.');
    }

    /**
     * Un rappel confirme sur un devis perime est refuse, et ne debite rien.
     *
     * Le service se donnait lui-meme la version courante : le controle etait toujours satisfait, et
     * un rappel affiche partait quand meme apres qu un autre ordre soit passe.
     */
    public function testAStaleRecallIsRefusedAndChargesNothing(): void
    {
        [$patrouille, $segment] = $this->aParkedPatrol();
        $reserveAvant = (float)$patrouille->fuel_reserve;

        $refus = $this->postJson(route('galaxy.patrol.recall', ['patrol' => $patrouille->id]), [
            'order_version' => (int)$patrouille->order_version + 1,
        ])->assertStatus(409)->json();

        $this->assertSame('stale_quote', $refus['reason_key']);

        $patrouille->refresh();
        $this->assertSame(PatrolState::Stationed, $patrouille->state, 'A stale recall moved the patrol.');
        $this->assertSame((int)$segment->id, (int)$patrouille->current_mission_id, 'A stale recall replaced the segment.');
        $this->assertSame($reserveAvant, (float)$patrouille->fuel_reserve, 'A stale recall charged the reserve.');

        // Sans version du tout : meme refus.
        $this->postJson(route('galaxy.patrol.recall', ['patrol' => $patrouille->id]), [])->assertStatus(409);
    }

    /**
     * Un cout devenu superieur a celui du devis est refuse ; un cout inferieur passe et c est le vrai
     * qui est preleve.
     */
    public function testAnOrderIsRefusedWhenTheCostHasMovedAboveWhatWasQuoted(): void
    {
        [$patrouille] = $this->aParkedPatrol();
        $reserveAvant = (float)$patrouille->fuel_reserve;

        $devis = $this->postJson(route('galaxy.patrol.quote'), $this->here() + [
            'patrol_id' => $patrouille->id,
            'x' => -600,
            'y' => 600,
        ])->assertStatus(200)->json();

        $cout = (int)$devis['quote']['fuel_cost'];
        $this->assertGreaterThan(1, $cout, 'The witness needs a leg that costs something.');

        // Un cout lu **inferieur** au vrai : le serveur refuse de debiter plus que ce qui a ete lu.
        $refus = $this->postJson(route('galaxy.patrol.move', ['patrol' => $patrouille->id]), $this->here() + [
            'x' => -600,
            'y' => 600,
            'order_version' => (int)$patrouille->order_version,
            'quoted_fuel_cost' => $cout - 1,
        ])->assertStatus(409)->json();

        $this->assertSame('quote_cost_moved', $refus['reason_key']);

        $patrouille->refresh();
        $this->assertSame(PatrolState::Stationed, $patrouille->state, 'A refused order moved the patrol.');

        /*
         * **Le trajet n est pas facture ; le stationnement du l est.** Un ordre depuis un
         * stationnement paie d abord ce qu il doit — le curseur ne recule pas, et la seconde ecoulee
         * depuis l arrivee reste due que l ordre passe ou non. Ce qui doit rester intact est le cout
         * du trajet, de plusieurs centaines : la reserve n en a pas perdu une unite.
         */
        $apresRefus = (float)$patrouille->fuel_reserve;
        $this->assertGreaterThan($reserveAvant - 1.0, $apresRefus, 'A refused order charged the leg.');
        $this->assertLessThanOrEqual($reserveAvant, $apresRefus, 'A refused order credited the reserve.');

        // Le cout exact passe, et c est lui qui est preleve.
        $this->postJson(route('galaxy.patrol.move', ['patrol' => $patrouille->id]), $this->here() + [
            'x' => -600,
            'y' => 600,
            'order_version' => (int)$patrouille->order_version,
            'quoted_fuel_cost' => $cout,
        ])->assertStatus(200);

        $patrouille->refresh();
        $this->assertEqualsWithDelta($apresRefus - $cout, (float)$patrouille->fuel_reserve, 0.01, 'The leg charged something else than what was quoted.');

        /*
         * **Un coût devenu inférieur passe, et c'est le vrai qui est prélevé.** Sans ce demi-témoin,
         * un serveur qui refuserait toute différence — et non le seul dépassement — passerait :
         * dans les cas ci-dessus le coût lu et le coût refait coïncident, donc « juste » et « faux »
         * rendaient le même verdict. Une mutation l'a montré.
         */
        $avantLarge = (float)$patrouille->fuel_reserve;

        $retour = $this->postJson(route('galaxy.patrol.quote'), $this->here() + [
            'patrol_id' => $patrouille->id,
            'x' => 600,
            'y' => 600,
        ])->assertStatus(200)->json();

        $vrai = (int)$retour['quote']['fuel_cost'];

        $this->postJson(route('galaxy.patrol.move', ['patrol' => $patrouille->id]), $this->here() + [
            'x' => 600,
            'y' => 600,
            'order_version' => (int)$patrouille->refresh()->order_version,
            'quoted_fuel_cost' => $vrai + 500,
        ])->assertStatus(200);

        $patrouille->refresh();
        $this->assertEqualsWithDelta($avantLarge - $vrai, (float)$patrouille->fuel_reserve, 0.01, 'A cost quoted higher than the real one was charged at the quoted value.');
    }

    /**
     * Un lancement nomme le corps d ou il part ; celui d un autre joueur est refuse.
     *
     * **Aucun officier n est demande** (decision de Keven, 8 septembre 2026, relayee par Codex : la
     * carte est l interface centrale du systeme, et les patrouilles y sont ouvertes a tous, aux
     * memes contraintes de vaisseaux, de carburant et de creneaux). Le compte du banc n a pas
     * d Amiral, et c est justement ce qui rend ce temoin utile.
     */
    public function testALaunchNamesItsOriginAndNeedsNoOfficer(): void
    {
        $this->arm();
        $this->planetAddResources(new Resources(0, 0, 60000, 0));
        $this->planetAddUnit('cruiser', 20);

        $this->assertFalse($this->player()->hasAdmiral(), 'The witness needs an account without an Admiral to prove anything.');

        // Le corps d un autre joueur n est pas une origine.
        $etrangere = $this->getNearbyForeignPlanet();

        $refus = $this->postJson(route('galaxy.patrol.launch'), ['planet_id' => $etrangere->getPlanetId()] + $this->launchPayload())
            ->assertStatus(409)
            ->json();

        $this->assertSame('bad_origin', $refus['reason_key']);
        $this->assertSame(0, Patrol::query()->where('user_id', $this->currentUserId)->count(), 'A launch from a foreign body created a patrol.');

        // Le sien, oui — sans Amiral, et la patrouille part.
        $reponse = $this->postJson(route('galaxy.patrol.launch'), $this->launchPayload())->assertStatus(200)->json();
        $patrouille = Patrol::query()->whereKey((int)$reponse['patrol_id'])->firstOrFail();

        $this->assertSame((int)$this->planetService->getPlanetId(), (int)$patrouille->home_planet_id, 'The patrol was not launched from the named body.');

        // **Les contraintes ordinaires tiennent toujours** : les vaisseaux qu on n a pas sont refuses.
        $trop = $this->launchPayload();
        $trop['am' . self::CRUISER] = 999;

        $refusFlotte = $this->postJson(route('galaxy.patrol.quote'), $trop)->assertStatus(409)->json();
        $this->assertSame('not_enough_on_planet', $refusFlotte['reason_key'], 'Without an Admiral the ordinary constraints were dropped too.');
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
