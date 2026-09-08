<?php

namespace OGame\Patrol;

use Illuminate\Support\Facades\DB;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Patrol;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;
use OGame\Patrol\Enums\PatrolState;
use OGame\Patrol\Exceptions\PatrolOrderRefused;
use OGame\Patrol\Geometry\SpatialPoint;
use OGame\Services\FleetMissionService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;
use Throwable;

/**
 * Ce qu une patrouille sait faire : partir, se poser, repartir, rentrer.
 *
 * ## Comment une patrouille existe, en deux lignes
 *
 * Une ligne de `patrols` porte ce qui survit a chacun de ses vols : etat, point, reserve, curseur de
 * facturation, version d ordre, base d attache. Une ligne de `fleet_missions` de genre 11 porte le
 * vol lui-meme, et **c est elle qui detient les unites et la cargaison** — une seule propriete a la
 * fois. C est aussi elle qui occupe le creneau de flotte, passe la porte des mouvements, s annonce
 * sur la carte et s inscrit au combat : rien de tout cela n a eu besoin d etre reecrit.
 *
 * ## Le segment pose reste « non traite », et c est voulu
 *
 * Une patrouille posee occupe son creneau pour toute sa vie (revue 117). Le compteur du jeu compte
 * les missions non traitees ; le segment d une patrouille posee reste donc `processed = 0`, et son
 * `time_holding` porte le temps qui reste avant le prochain evenement — le retour de securite.
 * `FleetMissionService::updateMission()` ne le reprend qu a cette echeance, sans qu aucune ligne de
 * son chemin n ait ete modifiee pour les patrouilles.
 *
 * Consequence voulue : une patrouille posee retient le retrait d un compte exactement comme une
 * flotte en vol, et une patrouille engagee dans un combat ne bouge plus, par les regles existantes.
 *
 * ## La reserve et la cargaison ne se melangent pas
 *
 * Le deuterium **de la reserve** vit sur la patrouille ; la cargaison vit sur le segment. C est ce
 * qui permet de les compter separement, comme la revue 117 l exige, et de proteger l un sans
 * proteger l autre. Le segment porte donc `deuterium_consumption = 0` : la colonne existe pour
 * rendre a une planete la moitie de ce qu une mission lui a coute, et le carburant d une patrouille
 * ne vient pas d une planete mais de sa propre reserve.
 */
final class PatrolOrders
{
    public function __construct(
        private readonly PatrolPricing $pricing,
        private readonly PatrolUpkeep $upkeep,
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * Lance une patrouille depuis un corps du joueur.
     *
     * Tout se joue dans une transaction : le devis est refait sur l etat tenu, les ressources et les
     * unites sont retirees une seule fois, et la patrouille nait avec son premier segment. Un echec
     * a n importe quel moment ne laisse rien derriere.
     *
     * @throws PatrolOrderRefused
     */
    public function launch(
        PlanetService $from,
        UnitCollection $units,
        Resources $cargo,
        int $reserve,
        PatrolDestination $to,
        float $speedPercent,
        int $now,
    ): Patrol {
        $this->refuseIfDisabled();

        $player = $from->getPlayer();

        if ($player === null) {
            throw new PatrolOrderRefused('no_owner');
        }

        $depart = $from->getPlanetCoordinates();
        $geometrie = $this->pricing->geometry();

        $devis = $this->pricing->quote(
            $player,
            $units,
            (float)$reserve,
            $depart->galaxy,
            $depart->system,
            $geometrie->bodyPoint($depart->position),
            $to,
            $speedPercent,
            1,
            $depart
        );

        if (!$devis->isPossible()) {
            throw new PatrolOrderRefused((string)$devis->refusal);
        }

        // **Le creneau se verifie avant le debit**, comme pour toute mission : la porte du jeu est
        // le nombre de missions non traitees, et une patrouille en occupe une pour toute sa vie.
        if ($player->getFleetSlotsInUse() >= $player->getFleetSlotsMax()) {
            throw new PatrolOrderRefused('no_fleet_slot');
        }

        $soute = $units->getTotalCargoCapacity($player);

        // **La reserve occupe du fret et se compte a part de la cargaison** (revue 117) : les deux
        // ensemble doivent tenir, et aucune des deux ne se compte deux fois.
        if ($cargo->sum() + $reserve > $soute) {
            throw new PatrolOrderRefused('cargo_exceeds_hold');
        }

        return DB::transaction(function () use ($from, $player, $units, $cargo, $reserve, $to, $devis, $depart, $now, $geometrie): Patrol {
            $aRetirer = new Resources(
                $cargo->metal->get(),
                $cargo->crystal->get(),
                $cargo->deuterium->get() + $reserve,
                0
            );

            if (!$from->deductResourcesAndUnitsAtomic($aRetirer, $units)) {
                throw new PatrolOrderRefused('not_enough_on_planet');
            }

            $patrouille = Patrol::forceCreate([
                'user_id' => $player->getId(),
                'home_planet_id' => $from->getPlanetId(),
                'state' => PatrolState::EnRoute,
                'galaxy' => $to->galaxy,
                'system' => $to->system,
                'x' => null,
                'y' => null,
                'fuel_reserve' => (float)$reserve - $devis->fuelCost,
                'upkeep_paid_at' => null,
                'order_version' => 1,
            ]);

            $segment = $this->createSegment(
                $patrouille,
                $depart->galaxy,
                $depart->system,
                $depart->position,
                $from->getPlanetType(),
                $from->getPlanetId(),
                null,
                $to,
                $units,
                $cargo,
                $now,
                $now + $devis->durationSeconds
            );

            $patrouille->forceFill(['current_mission_id' => $segment->id])->save();

            unset($geometrie);

            return $patrouille;
        });
    }

    /**
     * Pose la patrouille a l arrivee de son segment, et arme son prochain rendez-vous.
     *
     * ## Ce que « poser » veut dire exactement
     *
     * Le point courant devient celui du segment ; la facturation demarre a l **arrivee physique**,
     * pas a l instant ou le travailleur est passe — sans quoi un serveur en retard offrirait du
     * stationnement gratuit. L entree dans le systeme n est reecrite que si le systeme a change :
     * c est elle qui fait courir l horloge d acquisition des reseaux de surveillance, et la revue 120
     * interdit qu un deplacement interne la remette a zero.
     *
     * Le segment reste non traite, avec un `time_holding` qui porte jusqu au retour de securite.
     */
    public function park(Patrol $patrol, FleetMission $segment): void
    {
        $arrivee = (int)$segment->time_arrival;
        $point = $this->pointOf($segment);
        $memeSysteme = (int)$patrol->galaxy === (int)$segment->galaxy_to && (int)$patrol->system === (int)$segment->system_to;

        $patrol->forceFill([
            'state' => PatrolState::Stationed,
            'galaxy' => (int)$segment->galaxy_to,
            'system' => (int)$segment->system_to,
            'x' => $point->x,
            'y' => $point->y,
            'upkeep_paid_at' => $arrivee,
            'stationed_since' => $arrivee,
            'entered_system_at' => $memeSysteme && $patrol->entered_system_at !== null
                ? (int)$patrol->entered_system_at
                : $arrivee,
        ])->save();

        $this->scheduleNextEvent($patrol, $segment);
    }

    /**
     * Arme le prochain rendez-vous du segment pose : l instant du retour de securite.
     *
     * `time_holding` porte le delai depuis l arrivee physique. `updateMission()` ne reprendra le
     * segment qu a `time_arrival + time_holding`, sans qu aucune ligne de son chemin n ait ete
     * ecrite pour les patrouilles.
     */
    public function scheduleNextEvent(Patrol $patrol, FleetMission $segment): void
    {
        $units = $this->unitsOf($segment);
        $coutRetour = $this->safetyReturnCostOf($patrol, $units);
        $paidAt = (int)($patrol->upkeep_paid_at ?? $segment->time_arrival);

        $instant = $this->upkeep->safetyReturnAt($units, (float)$patrol->fuel_reserve, (float)$coutRetour, $paidAt);

        // Une flotte qui ne brule rien n a pas de terme ; le jeu n en connait pas, mais la garde
        // existe et doit produire une valeur qui ne deborde pas la colonne.
        $delai = $instant === PHP_INT_MAX
            ? self::HOLD_WITHOUT_END
            : max(0, $instant - (int)$segment->time_arrival);

        $segment->forceFill(['time_holding' => $delai])->save();
    }

    /**
     * Cent ans : le rendez-vous d une patrouille qui ne brule rien.
     *
     * Une valeur finie plutot qu un nul, pour que `time_arrival + time_holding` reste une somme
     * d entiers que la base compare sans cas particulier.
     */
    private const int HOLD_WITHOUT_END = 100 * 365 * 24 * 3600;

    /**
     * Facture le stationnement du curseur jusqu a cet instant, et avance le curseur.
     *
     * **Au prorata exact, et jamais deux fois.** Le curseur ne recule pas, et ce qui a ete facture
     * ne l est pas de nouveau : c est la seule chose qui empeche un ordre repete de recreer une
     * heure gratuite ou d en facturer une deja payee.
     *
     * @return float Ce qui vient d etre preleve.
     */
    public function bill(Patrol $patrol, UnitCollection $units, int $jusqua): float
    {
        $depuis = (int)($patrol->upkeep_paid_at ?? $jusqua);

        if ($jusqua <= $depuis) {
            return 0.0;
        }

        $du = $this->upkeep->dueBetween($units, $depuis, $jusqua);
        $preleve = min($du, (float)$patrol->fuel_reserve);

        $patrol->forceFill([
            'fuel_reserve' => (float)$patrol->fuel_reserve - $preleve,
            'upkeep_paid_at' => $jusqua,
        ])->save();

        return $preleve;
    }

    /**
     * Le cout du retour de securite depuis le point courant vers la base d attache.
     *
     * La base peut avoir disparu : le repli est alors la planete du joueur la plus proche, et il se
     * decide **au moment de l ordre**, jamais recopie a l avance.
     */
    public function safetyReturnCostOf(Patrol $patrol, UnitCollection $units): int
    {
        $joueur = $this->playerOf($patrol);
        $base = $this->homeCoordinateOf($patrol);

        if ($joueur === null || $base === null) {
            return 0;
        }

        $distance = $this->pricing->distanceBetween(
            (int)$patrol->galaxy,
            (int)$patrol->system,
            $patrol->point() ?? $this->pricing->geometry()->bodyPoint(1),
            PatrolDestination::spatialPoint(
                $this->pricing->geometry(),
                $base->galaxy,
                $base->system,
                $this->pricing->geometry()->stationingPointNear($base->position)
            )
        );

        return $this->fleetMissionsFor($joueur)
            ->consumptionOverDistance($joueur, $units, $distance, 0, $this->settings->patrolSafetyReturnSpeed());
    }

    /**
     * Les coordonnees de la base d attache, ou celles du repli si elle a disparu.
     */
    public function homeCoordinateOf(Patrol $patrol): Coordinate|null
    {
        $base = $patrol->homePlanet;

        if ($base !== null && (int)$base->destroyed === 0) {
            return new Coordinate((int)$base->galaxy, (int)$base->system, (int)$base->planet);
        }

        return $this->nearestOwnPlanetOf($patrol);
    }

    /**
     * La planete du joueur la plus proche du point courant, a egalite le plus petit identifiant.
     *
     * Deterministe et rejouable : c est ce que le protocole de retour du combat exige deja de ses
     * propres recours, et la meme discipline s applique ici.
     */
    private function nearestOwnPlanetOf(Patrol $patrol): Coordinate|null
    {
        $joueur = $this->playerOf($patrol);

        if ($joueur === null) {
            return null;
        }

        $meilleure = null;
        $meilleurCout = null;
        $meilleurId = null;

        foreach ($joueur->planets->allPlanets() as $planete) {
            $coordonnees = $planete->getPlanetCoordinates();
            $cout = $this->pricing->distanceBetween(
                (int)$patrol->galaxy,
                (int)$patrol->system,
                $patrol->point() ?? $this->pricing->geometry()->bodyPoint(1),
                PatrolDestination::spatialPoint(
                    $this->pricing->geometry(),
                    $coordonnees->galaxy,
                    $coordonnees->system,
                    $this->pricing->geometry()->stationingPointNear($coordonnees->position)
                )
            );

            $identifiant = $planete->getPlanetId();

            if ($meilleurCout === null || $cout < $meilleurCout || ($cout === $meilleurCout && $identifiant < $meilleurId)) {
                $meilleurCout = $cout;
                $meilleurId = $identifiant;
                $meilleure = $coordonnees;
            }
        }

        return $meilleure;
    }

    private function playerOf(Patrol $patrol): PlayerService|null
    {
        try {
            return resolve(PlayerServiceFactory::class)->make((int)$patrol->user_id, true);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Les unites que porte ce segment, lues par le service canonique.
     *
     * **Avec le proprietaire du segment, jamais avec le joueur de la requete.** Le conteneur garde
     * une instance partagee du joueur courant : la resoudre ici ferait lire une flotte au nom de
     * quelqu un d autre des qu un travailleur traite la patrouille d un tiers.
     */
    public function unitsOf(FleetMission $segment): UnitCollection
    {
        $proprietaire = resolve(PlayerServiceFactory::class)->make((int)$segment->user_id, true);

        return $this->fleetMissionsFor($proprietaire)->getFleetUnits($segment);
    }

    /**
     * Le service des missions, monte sur ce joueur-la.
     */
    private function fleetMissionsFor(PlayerService $player): FleetMissionService
    {
        return resolve(FleetMissionService::class, ['player' => $player]);
    }

    /**
     * Le point ou ce segment se termine, en coordonnees de reference.
     */
    private function pointOf(FleetMission $segment): SpatialPoint
    {
        if ($segment->x_to !== null && $segment->y_to !== null) {
            return new SpatialPoint((int)$segment->x_to, (int)$segment->y_to);
        }

        return $this->pricing->geometry()->stationingPointNear((int)$segment->position_to);
    }

    /**
     * Fait partir le retour de securite : la patrouille rentre pendant qu elle le peut encore.
     *
     * ## L ordre des trois ecritures compte
     *
     * On facture d abord ce qui est du jusqu a cet instant, **puis** on lit la reserve : l inverse
     * ferait partir la flotte avec un carburant qu elle a deja brule. Le segment pose est ensuite
     * marque traite — il a fini son office — et un segment neuf porte les memes unites et la meme
     * cargaison vers la base. Une seule propriete a la fois : les unites passent d une ligne a
     * l autre, elles n existent jamais sur les deux.
     */
    public function launchSafetyReturn(Patrol $patrol, FleetMission $parked, int $now): FleetMission|null
    {
        $units = $this->unitsOf($parked);
        $this->bill($patrol, $units, $now);

        $base = $this->homeCoordinateOf($patrol);
        $proprietaire = $this->playerOf($patrol);

        if ($base === null || $proprietaire === null) {
            // Plus de base et plus de proprietaire : rien a faire ici. La patrouille reste posee, et
            // c est la suppression du compte qui la traitera — jamais un retour invente.
            return null;
        }

        $geometrie = $this->pricing->geometry();
        $vers = PatrolDestination::spatialPoint(
            $geometrie,
            $base->galaxy,
            $base->system,
            $geometrie->stationingPointNear($base->position)
        );

        $depart = $patrol->point() ?? $geometrie->stationingPointNear((int)$parked->position_to);
        $distance = $this->pricing->distanceBetween((int)$patrol->galaxy, (int)$patrol->system, $depart, $vers);
        $vitesse = $this->settings->patrolSafetyReturnSpeed();
        $duree = $this->fleetMissionsFor($proprietaire)->durationOverDistance($proprietaire, $units, $distance, null, $vitesse);
        $cout = $this->fleetMissionsFor($proprietaire)->consumptionOverDistance($proprietaire, $units, $distance, 0, $vitesse);

        return DB::transaction(function () use ($patrol, $parked, $units, $base, $depart, $now, $duree, $cout): FleetMission {
            $parked->forceFill(['processed' => 1])->save();

            $retour = $this->createSegment(
                $patrol,
                (int)$patrol->galaxy,
                (int)$patrol->system,
                (int)$parked->position_to,
                PlanetType::SpatialPoint,
                null,
                $depart,
                PatrolDestination::nearBody(
                    $this->pricing->geometry(),
                    $base->galaxy,
                    $base->system,
                    $base->position,
                    PlanetType::Planet,
                    (int)$patrol->home_planet_id
                ),
                $units,
                new Resources((float)$parked->metal, (float)$parked->crystal, (float)$parked->deuterium, 0),
                $now,
                $now + $duree
            );

            // **La reserve paie le retour maintenant**, comme tout segment : ce qui reste rentrera
            // avec la flotte. Le plancher a zero est un fait, pas une indulgence — la patrouille part
            // avec ce qu elle a, et le retour de securite est calcule pour que cela suffise.
            $patrol->forceFill([
                'state' => PatrolState::Returning,
                'current_mission_id' => $retour->id,
                'fuel_reserve' => max(0.0, (float)$patrol->fuel_reserve - $cout),
                'x' => null,
                'y' => null,
            ])->save();

            return $retour;
        });
    }

    /**
     * La patrouille se pose chez elle : tout revient, et elle cesse d exister.
     *
     * Unites, cargaison **et reserve restante** rejoignent la planete. La reserve n a jamais ete
     * consommee que par les segments et le stationnement ; ce qui reste appartient au joueur, et le
     * lui rendre est la seule ecriture qui ferme le compte du carburant.
     */
    public function land(Patrol $patrol, FleetMission $segment, int $now, PlanetService $home): void
    {
        DB::transaction(function () use ($patrol, $segment, $now, $home): void {
            $home->addUnits($this->unitsOf($segment));

            $home->addResourcesAtomic(new Resources(
                (float)$segment->metal,
                (float)$segment->crystal,
                (float)$segment->deuterium + max(0.0, (float)$patrol->fuel_reserve),
                0
            ));

            $segment->forceFill(['processed' => 1])->save();

            $patrol->forceFill([
                'state' => PatrolState::Finished,
                'current_mission_id' => null,
                'fuel_reserve' => 0,
                'x' => null,
                'y' => null,
                'finished_at' => $now,
                'finish_reason' => 'came_home',
            ])->save();
        });
    }

    /**
     * Ecrit un segment de patrouille.
     *
     * `planet_id_from` porte **toujours la base d attache**, meme quand le segment part d un point
     * libre : c est par lui que le travailleur des pages trouve la mission, sa requete cherchant les
     * missions liees aux planetes du joueur. Un segment entre deux points de l espace lui serait
     * autrement invisible, et la patrouille ne se poserait jamais.
     */
    private function createSegment(
        Patrol $patrol,
        int $galaxyFrom,
        int $systemFrom,
        int $positionFrom,
        PlanetType $typeFrom,
        int|null $bodyFrom,
        SpatialPoint|null $pointFrom,
        PatrolDestination $to,
        UnitCollection $units,
        Resources $cargo,
        int $departure,
        int $arrival,
    ): FleetMission {
        $segment = new FleetMission();

        $segment->user_id = (int)$patrol->user_id;
        $segment->patrol_id = (int)$patrol->id;
        $segment->mission_type = 11;

        $segment->planet_id_from = (int)($patrol->home_planet_id ?? $bodyFrom);
        $segment->type_from = $typeFrom->value;
        $segment->galaxy_from = $galaxyFrom;
        $segment->system_from = $systemFrom;
        $segment->position_from = $positionFrom;
        $segment->x_from = $pointFrom?->x;
        $segment->y_from = $pointFrom?->y;

        $segment->planet_id_to = $to->bodyId;
        $segment->type_to = $to->type->value;
        $segment->galaxy_to = $to->galaxy;
        $segment->system_to = $to->system;
        $segment->position_to = $to->orbit;
        $segment->x_to = $to->point->x;
        $segment->y_to = $to->point->y;

        $segment->time_departure = $departure;
        $segment->time_arrival = $arrival;
        $segment->time_holding = null;

        // **Le carburant d une patrouille ne vient pas d une planete.** Cette colonne existe pour
        // rendre a une planete la moitie de ce qu une mission lui a coute ; la reserve, elle, est
        // debitee de la patrouille et lui revient entiere. La laisser a zero evite un credit ne de
        // nulle part.
        $segment->deuterium_consumption = 0;

        foreach ($units->units as $unite) {
            $segment->{$unite->unitObject->machine_name} = $unite->amount;
        }

        $segment->metal = $cargo->metal->getRounded();
        $segment->crystal = $cargo->crystal->getRounded();
        $segment->deuterium = $cargo->deuterium->getRounded();

        $segment->save();

        return $segment;
    }

    /**
     * @throws PatrolOrderRefused
     */
    private function refuseIfDisabled(): void
    {
        if (!$this->settings->patrolsEnabled()) {
            throw new PatrolOrderRefused('disabled');
        }
    }
}
