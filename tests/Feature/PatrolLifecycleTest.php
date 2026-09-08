<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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
     * Une base qui a change de mains n accueille pas la patrouille : elle repart vers ce qui reste.
     *
     * ## Ce que ce temoin ferme
     *
     * Un retour vise le corps qu il a nomme en partant. Entre le depart et l arrivee, ce corps peut
     * avoir change de mains — une planete abandonnee puis colonisee par un autre. L atterrissage
     * creditait alors la flotte, la cargaison et la reserve au **proprietaire du moment** : un cadeau
     * a un tiers, et une perte seche pour le joueur.
     *
     * La destination gardee sur le segment dit ou la patrouille se rendait ; elle n autorise pas la
     * livraison. Le corps est verifie **a l arrivee**, et s il n est plus celui du joueur la
     * patrouille stationne la ou elle est — elle y est physiquement — puis reprend le retour de
     * securite vers la base qui lui reste. Rien n est perdu, rien n est credite deux fois.
     */
    public function testABaseThatChangedHandsDoesNotReceiveThePatrolAndTheSafetyReturnStartsAgain(): void
    {
        $this->arm();
        $this->planetAddResources(new Resources(0, 0, 60000, 0));
        $this->planetAddUnit('cruiser', 20);

        $base = (int)$this->planetService->getPlanetId();

        $patrouille = $this->orders()->launch(
            $this->planetService,
            $this->fleet(['cruiser' => 20]),
            new Resources(0, 0, 0, 0),
            10000,
            $this->aFreePoint(),
            10,
            (int)Date::now()->timestamp
        );

        // Aller, stationnement, puis le retour de securite part vers la base.
        $segment = FleetMission::query()->findOrFail($patrouille->current_mission_id);
        Date::setTestNow(Date::createFromTimestamp((int)$segment->time_arrival + 1));
        $this->runTheWorker();

        $segment->refresh();
        Date::setTestNow(Date::createFromTimestamp((int)$segment->time_arrival + (int)$segment->time_holding + 1));
        $this->runTheWorker();

        $patrouille->refresh();
        $this->assertSame(PatrolState::Returning, $patrouille->state, 'The safety return never left: the scenario would prove nothing.');
        $retour = FleetMission::query()->findOrFail($patrouille->current_mission_id);
        $this->assertSame($base, (int)$retour->planet_id_to, 'The safety return does not name the base it lands on.');

        // **Le geste du jeu** : pendant le vol, la base passe en d autres mains.
        $etrangere = $this->getNearbyForeignPlanet();
        $nouveauProprietaire = $etrangere->getPlayer();
        $this->assertNotNull($nouveauProprietaire);
        $this->assertNotSame($this->currentUserId, $nouveauProprietaire->getId());
        DB::table('planets')->where('id', $base)->update(['user_id' => $nouveauProprietaire->getId()]);

        // **Le carburant n est pas le sujet de ce temoin.** La reserve paie largement le repli, pour
        // qu il ne prouve qu une chose : a qui appartient le corps. L insuffisance de carburant a son
        // propre temoin, et melanger les deux rendrait l issue dependante d une distance.
        DB::table('patrols')->where('id', $patrouille->id)->update(['fuel_reserve' => 100000]);

        $avant = DB::table('planets')->where('id', $base)->first();
        $this->assertNotNull($avant);

        // Le travailleur qui trouve la mission est desormais celui du nouveau proprietaire : les deux
        // bouts du segment nomment sa planete.
        Date::setTestNow(Date::createFromTimestamp((int)$retour->time_arrival + 1));
        resolve(PlayerServiceFactory::class)->make($nouveauProprietaire->getId(), true)->updateFleetMissions();

        $apres = DB::table('planets')->where('id', $base)->first();
        $this->assertNotNull($apres);
        $this->assertSame((int)$avant->cruiser, (int)$apres->cruiser, 'The fleet was handed to the new owner of the base.');
        $this->assertEqualsWithDelta((float)$avant->deuterium, (float)$apres->deuterium, 0.001, 'The reserve was handed to the new owner of the base.');

        $patrouille->refresh();
        $this->assertNotSame(PatrolState::Finished, $patrouille->state, 'The patrol was closed on a body that is no longer its own.');
        $this->assertSame((int)$this->currentUserId, (int)$patrouille->user_id, 'The patrol changed hands with its base.');

        $suivant = FleetMission::query()->findOrFail($patrouille->current_mission_id);
        $this->assertNotSame((int)$retour->id, (int)$suivant->id, 'The patrol kept the leg that could not land: it would retry for ever.');
        $repli = (int)$suivant->planet_id_to;
        $this->assertNotSame($base, $repli, 'The new return still names the base that changed hands.');
        $this->assertSame(
            $this->currentUserId,
            (int)DB::table('planets')->where('id', $repli)->value('user_id'),
            'The new return does not name a planet of the patrol owner.'
        );
    }

    /**
     * Un compte en cours de suppression ne lance pas de patrouille, et ne perd rien en essayant.
     *
     * ## Ce que ce temoin ferme
     *
     * Le lancement d une flotte ordinaire prend la barriere de suppression de compte ; le lancement
     * d une patrouille ne la prenait pas. Une patrouille pouvait donc naitre pendant que son
     * proprietaire et ses biens sont effaces : la mission serait restee orpheline, ou le debit sans
     * mission. Ce n est pas une regle de jeu nouvelle — c est la protection existante, reprise telle
     * quelle, sur le meme etat type et le meme verrou de ligne.
     */
    public function testAnAccountBeingDeletedLaunchesNoPatrolAndLosesNothingTrying(): void
    {
        $this->arm();
        $this->planetAddResources(new Resources(0, 0, 60000, 0));
        $this->planetAddUnit('cruiser', 20);

        $avantVaisseaux = (int)DB::table('planets')->where('id', $this->planetService->getPlanetId())->value('cruiser');
        $avantDeuterium = (float)DB::table('planets')->where('id', $this->planetService->getPlanetId())->value('deuterium');
        $avantMissions = (int)DB::table('fleet_missions')->where('user_id', $this->currentUserId)->count();

        DB::table('users')->where('id', $this->currentUserId)->update(['deletion_pending_since' => (int)Date::now()->timestamp]);

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
            $this->fail('A patrol was launched while the account was being deleted.');
        } catch (PatrolOrderRefused $refus) {
            $this->assertSame('account_being_deleted', $refus->reason, 'The launch was refused for another reason than the deletion.');
        }

        $this->assertSame($avantVaisseaux, (int)DB::table('planets')->where('id', $this->planetService->getPlanetId())->value('cruiser'), 'The refused launch took the ships anyway.');
        $this->assertEqualsWithDelta($avantDeuterium, (float)DB::table('planets')->where('id', $this->planetService->getPlanetId())->value('deuterium'), 0.001, 'The refused launch took the reserve anyway: a debit without a mission.');
        $this->assertSame($avantMissions, (int)DB::table('fleet_missions')->where('user_id', $this->currentUserId)->count(), 'The refused launch left an orphan mission.');
        $this->assertSame(0, (int)DB::table('patrols')->where('user_id', $this->currentUserId)->count(), 'The refused launch left a patrol behind.');
    }

    /**
     * Changer de systeme remet l horloge d entree ; rester dans le sien ne la touche pas.
     *
     * ## Ce que ce temoin ferme
     *
     * L instant d entree dans un systeme fait courir le delai d acquisition des reseaux de
     * surveillance : une patrouille qui garderait la date de son systeme precedent serait detectee
     * pour un sejour qu elle n a pas fait. Le depart ecrit deja le systeme de destination sur la
     * ligne de la patrouille ; la comparer a l arrivee la trouvait toujours egale, et l instant
     * n etait **jamais** remis. Les deux bouts du segment, eux, sont des faits du trajet.
     */
    public function testEnteringANewSystemResetsTheClockAndAnInternalMoveDoesNot(): void
    {
        $this->arm();
        $this->planetAddResources(new Resources(0, 0, 200000, 0));
        $this->planetAddUnit('cruiser', 20);

        $patrouille = $this->orders()->launch(
            $this->planetService,
            $this->fleet(['cruiser' => 20]),
            new Resources(0, 0, 0, 0),
            15000,
            $this->aFreePoint(),
            10,
            (int)Date::now()->timestamp
        );

        $segment = FleetMission::query()->findOrFail($patrouille->current_mission_id);
        Date::setTestNow(Date::createFromTimestamp((int)$segment->time_arrival + 1));
        $this->runTheWorker();

        $patrouille->refresh();
        $premiereEntree = (int)$patrouille->entered_system_at;
        $this->assertGreaterThan(0, $premiereEntree, 'The patrol never recorded an entry instant.');

        // Un deplacement **interne** : meme systeme, l horloge ne repart pas.
        $coords = $this->planetService->getPlanetCoordinates();
        $geometrie = resolve(PatrolPricing::class)->geometry();
        $interne = PatrolDestination::spatialPoint($geometrie, $coords->galaxy, $coords->system, new SpatialPoint(300, -300));

        $patrouille->refresh();
        $this->orders()->orderMove($patrouille, $interne, 10, (int)$patrouille->order_version, (int)Date::now()->timestamp);
        $patrouille->refresh();
        $segmentInterne = FleetMission::query()->findOrFail($patrouille->current_mission_id);
        Date::setTestNow(Date::createFromTimestamp((int)$segmentInterne->time_arrival + 1));
        $this->runTheWorker();

        $patrouille->refresh();
        $this->assertSame($premiereEntree, (int)$patrouille->entered_system_at, 'An internal move restarted the entry clock.');

        // Un deplacement vers **un autre systeme** : l horloge repart a l arrivee.
        $ailleurs = PatrolDestination::spatialPoint($geometrie, $coords->galaxy, $coords->system + 1, new SpatialPoint(600, 600));

        $this->orders()->orderMove($patrouille, $ailleurs, 10, (int)$patrouille->order_version, (int)Date::now()->timestamp);
        $patrouille->refresh();
        $segmentAilleurs = FleetMission::query()->findOrFail($patrouille->current_mission_id);
        Date::setTestNow(Date::createFromTimestamp((int)$segmentAilleurs->time_arrival + 1));
        $this->runTheWorker();

        $patrouille->refresh();
        $this->assertSame($coords->system + 1, (int)$patrouille->system, 'The patrol did not change system: the scenario would prove nothing.');
        $this->assertSame(
            (int)$segmentAilleurs->time_arrival,
            (int)$patrouille->entered_system_at,
            'The patrol kept the entry instant of the system it left: surveillance would count a stay that never happened.'
        );
    }

    /**
     * Le devis annonce le retour que le retour executera.
     *
     * ## Ce que ce temoin ferme
     *
     * Le devis mesurait la distance du retour entre **coordonnees**, celle du jeu classique, alors
     * que le retour reel la mesure entre **points** de la carte. Dans un meme systeme les deux ne
     * coincident pas : le cout et l autonomie annonces au joueur pouvaient etre faux, et une reserve
     * insuffisante acceptee au lancement. Un devis doit decrire l ordre qui sera execute.
     */
    public function testTheQuotedSafetyReturnCostsWhatTheRealOneWillCost(): void
    {
        $this->arm();
        $this->planetAddResources(new Resources(0, 0, 200000, 0));
        $this->planetAddUnit('cruiser', 20);

        $units = $this->fleet(['cruiser' => 20]);
        $devis = resolve(PatrolPricing::class)->quote(
            $this->player(),
            $units,
            15000.0,
            (int)$this->planetService->getPlanetCoordinates()->galaxy,
            (int)$this->planetService->getPlanetCoordinates()->system,
            resolve(PatrolPricing::class)->geometry()->stationingPointNear((int)$this->planetService->getPlanetCoordinates()->position),
            $this->aFreePoint(),
            10,
            1,
            $this->planetService->getPlanetCoordinates()
        );

        $this->assertTrue($devis->isPossible(), 'The quote refused: the scenario would prove nothing.');
        $this->assertGreaterThan(0, $devis->safetyReturnCost, 'The quoted return costs nothing.');

        $patrouille = $this->orders()->launch(
            $this->planetService,
            $units,
            new Resources(0, 0, 0, 0),
            15000,
            $this->aFreePoint(),
            10,
            (int)Date::now()->timestamp
        );

        $segment = FleetMission::query()->findOrFail($patrouille->current_mission_id);
        Date::setTestNow(Date::createFromTimestamp((int)$segment->time_arrival + 1));
        $this->runTheWorker();

        $patrouille->refresh();

        $this->assertSame(
            $devis->safetyReturnCost,
            $this->orders()->safetyReturnCostOf($patrouille, $units),
            'The quoted safety return and the one the game will charge do not agree: the player was told a cost that is not his.'
        );
    }

    /**
     * Le seuil du retour de securite est exact : sous le cout on s immobilise, a partir de lui on part.
     *
     * ## Pourquoi ce seuil devait etre defini, et pas approche
     *
     * L autonomie est arrondie a la seconde inferieure : a l instant du rendez-vous, la reserve
     * couvre **exactement** le cout du retour. Le travailleur, lui, passe quand il passe, et
     * facturer les secondes de son retard avant de decider du depart mangeait dans la reserve
     * reservee — un depart promis devenait impayable de quelques centiemes. Le stationnement cesse
     * donc d etre facture au rendez-vous, et la comparaison est exacte, sans tolerance.
     *
     * Les trois points sont eprouves parce qu une regle de seuil ne se prouve qu a son seuil : juste
     * en dessous, exactement dessus, juste au-dessus.
     *
     * @return array{0: int, 1: string}
     */
    private function whatHappensWithAReserveOf(int $reserve): array
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

        $cout = $this->orders()->safetyReturnCostOf($patrouille, $this->fleet(['cruiser' => 20]));
        $this->assertGreaterThan(0, $cout, 'The return costs nothing: the threshold would prove nothing.');

        // La reserve visee, et un curseur de facturation pose maintenant : le rendez-vous se
        // recalcule sur ces deux faits.
        $maintenant = (int)Date::now()->timestamp;
        DB::table('patrols')->where('id', $patrouille->id)->update([
            'fuel_reserve' => $cout + $reserve,
            'upkeep_paid_at' => $maintenant,
        ]);
        $patrouille->refresh();
        $this->orders()->scheduleNextEvent($patrouille, $segment);
        $segment->refresh();

        $rendezVous = $this->orders()->nextEventAt($segment);
        $this->assertNotNull($rendezVous, 'The parked patrol has no appointment: the scenario would prove nothing.');

        Date::setTestNow(Date::createFromTimestamp(max($maintenant, $rendezVous) + 1));
        $this->runTheWorker();

        $patrouille->refresh();

        return [$cout, $patrouille->state->value];
    }

    /**
     * Une unite sous le cout : la patrouille s immobilise, elle ne part pas a credit.
     */
    public function testAReserveOneUnitShortOfTheReturnImmobilisesThePatrol(): void
    {
        [, $etat] = $this->whatHappensWithAReserveOf(-1);

        $this->assertSame(PatrolState::Immobilised->value, $etat, 'A patrol one unit short of its return flew anyway.');
    }

    /**
     * Exactement le cout : elle part, et il ne lui reste rien.
     */
    public function testAReserveExactlyEqualToTheReturnLetsThePatrolLeave(): void
    {
        [, $etat] = $this->whatHappensWithAReserveOf(0);

        $this->assertSame(PatrolState::Returning->value, $etat, 'A patrol holding exactly its return cost was immobilised.');
    }

    /**
     * Une unite de plus : elle part aussi.
     */
    public function testAReserveOneUnitAboveTheReturnLetsThePatrolLeave(): void
    {
        [, $etat] = $this->whatHappensWithAReserveOf(1);

        $this->assertSame(PatrolState::Returning->value, $etat, 'A patrol holding more than its return cost was immobilised.');
    }

    /**
     * Une base detruite pendant le vol n accueille pas la patrouille non plus.
     *
     * Le corps existe encore comme ligne — une planete detruite garde la sienne, avec son
     * horodatage — et le seul controle de propriete la laisserait passer. La verification sous verrou
     * lit les deux faits : a qui elle est, et si elle est encore la.
     */
    public function testABaseDestroyedDuringTheFlightDoesNotReceiveThePatrolEither(): void
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
        $base = (int)$this->planetService->getPlanetId();

        // **Le geste du jeu** : la base est detruite. La ligne reste, avec son horodatage.
        DB::table('planets')->where('id', $base)->update(['destroyed' => (int)Date::now()->timestamp]);

        $avant = DB::table('planets')->where('id', $base)->first();
        $this->assertNotNull($avant);
        $this->assertGreaterThan(0, (int)$avant->destroyed, 'The base was not destroyed: the scenario would prove nothing.');

        $pose = $this->orders()->land($patrouille, $segment, (int)Date::now()->timestamp, $this->planetService);

        $this->assertFalse($pose, 'The patrol landed on a destroyed base.');

        $apres = DB::table('planets')->where('id', $base)->first();
        $this->assertNotNull($apres);
        $this->assertSame((int)$avant->cruiser, (int)$apres->cruiser, 'The fleet was given back to a destroyed base.');

        $segment->refresh();
        $this->assertSame(0, (int)$segment->processed, 'The refused leg was settled anyway.');
    }

    /**
     * L ecriture refuse un corps qui n est pas celui du joueur, **sous le verrou qui credite**.
     *
     * La verification et le credit vivent dans la meme transaction et sur la meme ligne relue : un
     * controle fait avant elle decrirait un passe, et la planete pourrait changer de mains entre les
     * deux. Le refus est une **reponse** — le segment reste non traite, la patrouille intacte — et
     * non une exception : l appelant doit alors poser la patrouille et reprendre le retour de
     * securite, ce qu il ne pourrait pas faire depuis une transaction annulee.
     */
    public function testLandingRefusesABodyThatIsNotThePatrolOwners(): void
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
        $etrangere = $this->getNearbyForeignPlanet();
        $proprietaire = $etrangere->getPlayer();
        $this->assertNotNull($proprietaire);
        $this->assertNotSame($this->currentUserId, $proprietaire->getId());

        $avant = DB::table('planets')->where('id', $etrangere->getPlanetId())->first();
        $this->assertNotNull($avant);

        $pose = $this->orders()->land($patrouille, $segment, (int)Date::now()->timestamp, $etrangere);

        $this->assertFalse($pose, 'Landing on a body that is not the patrol owner reported success.');

        $apres = DB::table('planets')->where('id', $etrangere->getPlanetId())->first();
        $this->assertNotNull($apres);
        $this->assertSame((int)$avant->cruiser, (int)$apres->cruiser, 'The fleet was handed to a stranger.');
        $this->assertEqualsWithDelta((float)$avant->deuterium, (float)$apres->deuterium, 0.001, 'The reserve was handed to a stranger.');

        $segment->refresh();
        $this->assertSame(0, (int)$segment->processed, 'The refused leg was settled anyway: the patrol would never come home.');

        $patrouille->refresh();
        $this->assertNotSame(PatrolState::Finished, $patrouille->state, 'The patrol was closed although nothing was given back.');
    }

    /**
     * Un retour de securite que la reserve ne paie pas immobilise la patrouille au lieu de partir.
     *
     * ## Ce que ce temoin ferme
     *
     * Le retour de securite est arme pour que la reserve suffise. Mais une patrouille peut arriver
     * quelque part avec une reserve qui ne paie plus le trajet vers la base **qui lui reste** — une
     * base perdue, un repli plus lointain. Le code partait quand meme : le cout etait retranche avec
     * un plancher a zero, et la flotte volait gratis. `Immobilised` decrit ce cas depuis le premier
     * jour et n etait jamais pose.
     *
     * La patrouille reste donc a la position qu elle a reellement atteinte, posee et attaquable, et
     * son rendez-vous est repousse sans terme : le travailleur ne propose plus un depart que
     * personne ne peut payer, et le secours borne est le seul chemin qui la remette en route.
     */
    public function testASafetyReturnTheReserveCannotPayImmobilisesThePatrolInPlace(): void
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
        $this->assertSame(PatrolState::Stationed, $patrouille->state, 'The patrol never parked: the scenario would prove nothing.');
        $segment->refresh();

        // **Le geste du jeu** : la reserve tombe a une goutte. Le trajet du retour coute plus.
        DB::table('patrols')->where('id', $patrouille->id)->update(['fuel_reserve' => 1]);
        $position = ['galaxy' => (int)$patrouille->galaxy, 'system' => (int)$patrouille->system, 'x' => (int)$patrouille->x, 'y' => (int)$patrouille->y];

        Date::setTestNow(Date::createFromTimestamp((int)$segment->time_arrival + (int)$segment->time_holding + 1));
        $this->runTheWorker();

        $patrouille->refresh();
        $this->assertSame(PatrolState::Immobilised, $patrouille->state, 'The patrol flew home on fuel it did not have.');
        $this->assertSame($position['galaxy'], (int)$patrouille->galaxy, 'The immobilised patrol moved.');
        $this->assertSame($position['system'], (int)$patrouille->system, 'The immobilised patrol moved.');
        $this->assertSame($position['x'], (int)$patrouille->x, 'The immobilised patrol moved.');
        $this->assertSame($position['y'], (int)$patrouille->y, 'The immobilised patrol moved.');
        $this->assertSame((int)$segment->id, (int)$patrouille->current_mission_id, 'A return leg was created despite the empty reserve.');

        // **Repousser l echeance n arrete que les tentatives automatiques.** Le segment reste non
        // traite : la patrouille garde son creneau, porte toujours ses vaisseaux, reste dans la boite
        // d evenements et reste inscriptible a un combat. Ce qui decide de ce qu elle a le droit de
        // faire est son etat, jamais son rendez-vous — le secours et l attaque passent par la.
        $segment->refresh();
        $this->assertSame(0, (int)$segment->processed, 'The parked leg was settled: the patrol would lose its slot.');
        $this->assertSame(20, (int)$segment->cruiser, 'The immobilised patrol no longer carries its ships: a rescue would find nothing.');
        $this->assertNull($this->orders()->nextEventAt($segment), 'The worker will keep proposing a departure nobody can pay.');
        $this->assertFalse($patrouille->state->acceptsMovementOrders(), 'An immobilised patrol accepts a movement order it cannot pay.');
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
        //
        // **Il s arrete au rendez-vous, pas au passage du travailleur.** L autonomie est arrondie a la
        // seconde inferieure pour qu a cet instant la reserve couvre exactement le cout du retour ;
        // facturer en plus les secondes de retard du travailleur mangeait dans cette reserve et
        // rendait le depart impayable de quelques centiemes. Le travailleur passe ici une seconde
        // apres l echeance, et cette seconde-la n est pas due : la patrouille etait deja partie.
        $this->assertSame(
            $echeance,
            (int)$patrouille->upkeep_paid_at,
            'The stationing was billed past the appointment, eating into the reserve the return needs.'
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
