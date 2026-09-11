<?php

namespace OGame\Patrol;

use Illuminate\Support\Facades\DB;
use OGame\Combat\Services\EngagedFleetCheck;
use OGame\Enums\AccountDeletionState;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Hull\DamagedHulls;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Patrol;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;
use OGame\Patrol\Enums\PatrolState;
use OGame\Patrol\Exceptions\PatrolOrderRefused;
use OGame\Patrol\Geometry\SpatialPoint;
use OGame\Services\AccountDeletionBarrier;
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
 * Consequence voulue : une patrouille engagee dans un combat ne bouge plus, par les regles
 * existantes. Une patrouille posee ne retient **pas** le retrait d un compte — le retrait ne tient
 * que les genres qui peuvent encore engager un combat, et le genre 11 n en est pas
 * (`PatrolIsolationTest`). Engagee, elle le retient comme cible d une bataille qu elle n a pas
 * ouverte : la bataille va a son terme, la suppression attend, rien n est annule ni rendu.
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
        private readonly SurveillanceWatch $watch,
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
        $refus = $this->whyLaunchIsRefused($from, $units, $cargo, $reserve);

        if ($refus !== null) {
            throw new PatrolOrderRefused($refus);
        }

        $player = $from->getPlayer();

        if ($player === null) {
            // Deja exclu par `whyLaunchIsRefused()` ; redit pour l analyse statique.
            throw new PatrolOrderRefused('no_owner');
        }

        $depart = $from->getPlanetCoordinates();
        $devis = $this->quoteForLaunch($from, $units, $reserve, $to, $speedPercent);

        if (!$devis->isPossible()) {
            throw new PatrolOrderRefused((string)$devis->refusal);
        }

        return DB::transaction(function () use ($from, $player, $units, $cargo, $reserve, $to, $devis, $depart, $now): Patrol {
            // **La meme protection que pour une flotte ordinaire, pas une regle inventee.** Le
            // lancement de flotte prend la barriere de suppression de compte ; une patrouille est une
            // flotte, et rien ne justifie qu elle naisse pendant que son proprietaire et ses biens
            // sont effaces — la mission resterait orpheline, ou le debit sans mission. L etat type est
            // celui du jeu, lu sur la ligne tenue.
            //
            // **Et c est le meme verrou qui reserve le creneau.** Le controle d avant la transaction
            // lit un etat que rien ne tient : deux lancements simultanes y voient tous deux la
            // derniere place libre, puis partent tous les deux. La ligne du compte serialise les
            // departs du meme joueur, et le compte est refait une fois qu elle est tenue.
            //
            // Sous SQLite `lockForUpdate()` ne compile a rien : la serialisation est decrite ici et
            // **prouvee au bac MariaDB**, comme toutes les courses de ce depot.
            if (AccountDeletionBarrier::heldState($player->getId()) === AccountDeletionState::Pending) {
                throw new PatrolOrderRefused('account_being_deleted');
            }

            if ($player->getFleetSlotsInUse() >= $player->getFleetSlotsMax()) {
                throw new PatrolOrderRefused('no_fleet_slot');
            }

            $aRetirer = self::takenFromThePlanet($cargo, $reserve);

            // **Une patrouille part comme toute flotte** : les plus intactes d abord. Le retrait
            // rend les degats emportes, qui s ecrivent sur le premier segment plus bas — sans quoi
            // une patrouille serait le seul chemin du jeu par lequel une flotte partirait guerie.
            $degatsEmportes = $from->detachUnitsForDeparture($aRetirer, $units);

            if ($degatsEmportes === null) {
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
                $now + $devis->durationSeconds,
                $degatsEmportes,
            );

            $patrouille->forceFill(['current_mission_id' => $segment->id])->save();

            return $patrouille;
        });
    }

    /**
     * Pourquoi un lancement serait refuse avant tout devis — ou `null` s il passerait.
     *
     * Le meme decideur pour la carte, qui grise et explique, et pour `launch()`, qui refuse. Le devis
     * vient apres, avec ses propres refus chiffres (carburant du trajet, reserve de retour) : ceux-ci
     * ne se disent pas sans nombres.
     */
    /**
     * La vitesse d un rappel : **pleine**, comme toute flotte qu on rappelle dans le jeu.
     *
     * Elle ne se confond pas avec `patrolSafetyReturnSpeed()`. Celle-la est la vitesse de l urgence,
     * decidee a 30 % pour qu une patrouille a court de reserve puisse rentrer malgre tout — un vol
     * lent consomme moins. Un rappel, lui, est une decision du joueur qui a du carburant : il paie
     * son trajet au prix plein et rentre au plus vite.
     *
     * Les deux valaient la meme chose, et le rappel heritait donc de la lenteur de l urgence sans
     * que rien ne l ait decide : un aller de huit minutes rentrait en vingt-six.
     */
    public const float RECALL_SPEED = 10.0;

    public function whyLaunchIsRefused(PlanetService $from, UnitCollection $units, Resources $cargo, int $reserve): string|null
    {
        if (!$this->settings->patrolsEnabled()) {
            return 'disabled';
        }

        $player = $from->getPlayer();

        if ($player === null) {
            return 'no_owner';
        }

        if ($units->getAmount() === 0) {
            return 'no_units';
        }

        if (self::hasImmobileUnit($player, $units)) {
            return 'immobile_unit';
        }

        // **Le creneau se verifie avant le debit**, comme pour toute mission : la porte du jeu est
        // le nombre de missions non traitees, et une patrouille en occupe une pour toute sa vie.
        if ($player->getFleetSlotsInUse() >= $player->getFleetSlotsMax()) {
            return 'no_fleet_slot';
        }

        // **La reserve occupe du fret et se compte a part de la cargaison** (revue 117) : les deux
        // ensemble doivent tenir, et aucune des deux ne se compte deux fois.
        if ($reserve < 0 || $cargo->sum() + $reserve > $units->getTotalCargoCapacity($player)) {
            return 'cargo_exceeds_hold';
        }

        if (!$from->hasUnits($units) || !$from->hasResources(self::takenFromThePlanet($cargo, $reserve))) {
            return 'not_enough_on_planet';
        }

        return null;
    }

    /**
     * Une unite sans vitesse — satellite solaire, foreur — rendrait la duree d un vol infinie.
     *
     * Partagee avec `PatrolMission::isMissionPossible()` : la page Flotte et la carte refusent par
     * la meme regle, ecrite une fois.
     */
    public static function hasImmobileUnit(PlayerService $player, UnitCollection $units): bool
    {
        foreach ($units->units as $unit) {
            if ($unit->unitObject->properties->speed->calculate($player)->totalValue <= 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ce qu un lancement retire a la planete : la cargaison, et la reserve comptee en deuterium.
     */
    private static function takenFromThePlanet(Resources $cargo, int $reserve): Resources
    {
        return new Resources(
            $cargo->metal->get(),
            $cargo->crystal->get(),
            $cargo->deuterium->get() + $reserve,
            0
        );
    }

    /**
     * Le devis d un lancement depuis un corps du joueur, tel que la carte le montre avant de confirmer.
     *
     * Le depart est le corps lui-meme, la version d ordre vaut 1 — celle que la patrouille aura en
     * naissant —, et la base d attache est ce corps.
     *
     * @throws PatrolOrderRefused
     */
    public function quoteForLaunch(PlanetService $from, UnitCollection $units, int $reserve, PatrolDestination $to, float $speedPercent): PatrolQuote
    {
        $player = $from->getPlayer();

        if ($player === null) {
            throw new PatrolOrderRefused('no_owner');
        }

        $depart = $from->getPlanetCoordinates();

        return $this->pricing->quote(
            $player,
            $units,
            (float)$reserve,
            $depart->galaxy,
            $depart->system,
            $this->pricing->geometry()->bodyPoint($depart->position),
            $to,
            $speedPercent,
            1,
            $depart
        );
    }

    /**
     * Le devis d un nouvel ordre, tel que le joueur le lira avant de confirmer.
     *
     * Il part de l endroit ou la patrouille **sera** quand l ordre prendra effet : son point actuel
     * si elle est posee, la position atteinte a la fin du delai de manoeuvre si elle vole. Le devis
     * porte la version d ordre courante, que la confirmation devra rapporter.
     *
     * `$fleetWillStation` dit si la flotte **restera** a destination. Les deux refus qui protegent
     * une patrouille posee — reserve insuffisante, plus de quoi rentrer une fois la-bas — n ont de
     * sens que dans ce cas. Une attaque, elle, frappe et revient au point : les lui appliquer la
     * refuserait pour une situation qui n arrivera jamais, et le refus le plus courant du jeu
     * deviendrait faux.
     */
    public function quoteFor(Patrol $patrol, PatrolDestination $to, float $speedPercent, int $now, bool $fleetWillStation = true): PatrolQuote
    {
        $proprietaire = $this->playerOf($patrol);
        $segment = $patrol->currentMission;

        if ($proprietaire === null || !$segment instanceof FleetMission) {
            throw new PatrolOrderRefused('no_current_segment');
        }

        $depart = $this->departurePointFor($patrol, $segment, $now);
        $base = $this->homeCoordinateOf($patrol);

        return $this->pricing->quote(
            $proprietaire,
            $this->unitsOf($segment),
            (float)$patrol->fuel_reserve - $this->upkeep->dueBetween($this->unitsOf($segment), (int)($patrol->upkeep_paid_at ?? $now), $patrol->state->isParked() ? $now : (int)($patrol->upkeep_paid_at ?? $now)),
            (int)$patrol->galaxy,
            (int)$patrol->system,
            $depart,
            $to,
            $speedPercent,
            (int)$patrol->order_version,
            $base ?? $to->coordinate(),
            $fleetWillStation
        );
    }

    /**
     * D ou le prochain segment partira.
     *
     * ## La position reelle, jamais « la plus proche »
     *
     * Posee, la patrouille part de son point. En vol, elle part de la position qu elle aura **a la
     * fin du delai de manoeuvre**, interpolee sur le segment en cours : la revue 120 interdit
     * explicitement de la teleporter vers un point commode, et la revue 121 fait courir ce delai
     * depuis la confirmation du serveur, pas depuis l ouverture du devis.
     */
    public function departurePointFor(Patrol $patrol, FleetMission $segment, int $now): SpatialPoint
    {
        $geometrie = $this->pricing->geometry();

        if ($patrol->state->isParked()) {
            return $patrol->point() ?? $geometrie->stationingPointNear((int)$segment->position_to);
        }

        $debut = (int)$segment->time_departure;
        $fin = (int)$segment->time_arrival;
        $instant = $now + $this->settings->patrolManoeuvreDelaySeconds();

        $de = $segment->x_from !== null && $segment->y_from !== null
            ? new SpatialPoint((int)$segment->x_from, (int)$segment->y_from)
            : $geometrie->stationingPointNear((int)$segment->position_from);

        $vers = $segment->x_to !== null && $segment->y_to !== null
            ? new SpatialPoint((int)$segment->x_to, (int)$segment->y_to)
            : $geometrie->stationingPointNear((int)$segment->position_to);

        $fraction = $fin <= $debut ? 1.0 : ($instant - $debut) / ($fin - $debut);

        return $geometrie->along($de, $vers, $fraction);
    }

    /**
     * Pourquoi un ordre de mouvement serait refuse maintenant — ou `null` s il passerait.
     *
     * ## Un seul decideur, deux lecteurs
     *
     * La carte grise un bouton et en dit la raison ; `orderMove()` refuse. Les deux doivent dire la
     * meme chose : un bouton actif qui mene a un refus, ou un bouton grise pour une raison que la
     * confirmation ignore, est un mensonge d interface. Ils lisent donc **la meme methode**, et
     * l interface ne calcule rien de son cote — positions, couts et autorisations viennent du
     * serveur, c est la regle de la carte.
     *
     * ## Ce qui n est pas ici, et pourquoi
     *
     * La **version d ordre** ne se juge qu a la confirmation : elle compare le devis que le joueur a
     * lu a l etat courant, et n existe pas avant qu un devis soit affiche. L etat du **segment relu
     * sous verrou** ne vaut que sous ce verrou. Ces deux refus-la restent dans `orderMove()`.
     */
    public function whyMoveIsRefused(Patrol $patrol, int $now): string|null
    {
        // **Une manoeuvre est une nouvelle entree** : l interrupteur la ferme.
        if (!$this->settings->patrolsEnabled()) {
            return 'disabled';
        }

        return $this->whyAnyOrderIsRefused($patrol, $now);
    }

    /**
     * Ce qui refuse **tout** ordre, interrupteur mis a part.
     *
     * Ces quatre controles ne dependent pas du chantier : ils decrivent une patrouille qui ne peut
     * pas recevoir d ordre, quel qu il soit. Les separer de l interrupteur est ce qui permet au
     * rappel de rester ouvert sans rien relacher d autre.
     */
    private function whyAnyOrderIsRefused(Patrol $patrol, int $now): string|null
    {
        $segment = $patrol->currentMission;

        if (!$segment instanceof FleetMission || $this->playerOf($patrol) === null) {
            return 'no_current_segment';
        }

        if (!$patrol->state->acceptsMovementOrders()) {
            return 'state_refuses_orders';
        }

        if (resolve(EngagedFleetCheck::class)->isEngaged($segment)) {
            return 'engaged_in_combat';
        }

        if (!$patrol->state->isParked()
            && (int)$segment->time_arrival <= $now + $this->settings->patrolManoeuvreDelaySeconds()) {
            return 'arriving_before_the_manoeuvre_ends';
        }

        return null;
    }

    /**
     * Pourquoi un rappel serait refuse maintenant : les memes raisons qu un mouvement, plus
     * l absence de toute base ou rentrer.
     */
    public function whyRecallIsRefused(Patrol $patrol, int $now): string|null
    {
        // **Rentrer chez soi n est pas une nouvelle entree.** Un interrupteur baisse doit fermer ce
        // qui n a pas encore commence, jamais retenir une flotte deja en l air : sans cela, un arret
        // d urgence laisserait les patrouilles du joueur sans aucun moyen d agir, en attendant que
        // l usure de la reserve declenche le retour de securite des heures plus tard.
        //
        // La carte lit ce verdict pour armer son bouton (`PatrolProjection`) : la reponse de cette
        // methode **est** l accessibilite du bouton, corriger l une corrige l autre.
        $refus = $this->whyAnyOrderIsRefused($patrol, $now);

        if ($refus !== null) {
            return $refus;
        }

        return $this->homeCoordinateOf($patrol) === null ? 'no_home_left' : null;
    }

    /**
     * Donne un nouvel ordre a une patrouille : elle repart, posee ou en vol.
     *
     * ## Ce qui est revalide a la confirmation, et pourquoi chacun
     *
     * La **version d ordre** : entre l affichage du devis et sa confirmation, un autre ordre a pu
     * passer. Le devis decrivait alors un autre monde, et l accepter debiterait autre chose que ce
     * que le joueur a lu (revue 121, R2).
     *
     * L **engagement** : une patrouille prise dans un combat ne bouge plus, par la meme regle que
     * toute flotte engagee.
     *
     * L **arrivee imminente** : une manoeuvre dont le segment se termine avant la fin du delai
     * n aurait pas de point de depart — la patrouille serait deja posee. L ordre est refuse, et le
     * joueur le redonne une fois qu elle l est.
     *
     * @throws PatrolOrderRefused
     */
    public function orderMove(Patrol $patrol, PatrolDestination $to, float $speedPercent, int $orderVersion, int $now, int|null $quotedFuelCost = null): FleetMission
    {
        return $this->dispatchOrder($patrol, $to, $speedPercent, $orderVersion, $now, PatrolState::EnRoute, $quotedFuelCost);
    }

    /**
     * **Pourquoi cette patrouille ne peut pas frapper maintenant** — ou rien, si elle le peut.
     *
     * La carte grise son bouton avec cette reponse, et `attackFrom()` refuse avec la meme : un
     * bouton actif qui mene a un refus est un mensonge d interface.
     *
     * ## Pourquoi « posee » et rien d autre
     *
     * Une patrouille en vol n a pas de point : ses unites sont entre deux endroits, et il n y aurait
     * **aucun endroit ou son attaque pourrait revenir**. Une patrouille immobilisee n a plus de quoi
     * rentrer chez elle : lui laisser depenser le peu qui reste en offensive la condamnerait.
     *
     * `whyAnyOrderIsRefused()` ecarte deja le segment manquant, l engagement et les etats qui ne
     * recoivent aucun ordre ; ce qui reste a dire tient en une phrase, et le refus la dit.
     */
    public function whyAttackIsRefused(Patrol $patrol, int $now): string|null
    {
        // **Attaquer est une entree neuve, sans l exception du rappel.** Un chantier desarme ferme
        // ce qui n a pas commence ; rentrer reste ouvert, frapper non.
        if (!$this->settings->patrolsEnabled()) {
            return 'disabled';
        }

        $refus = $this->whyAnyOrderIsRefused($patrol, $now);

        if ($refus !== null) {
            return $refus;
        }

        // L etat **et** la position, parce que ce sont deux faits distincts : un etat juste avec une
        // colonne vide ferait partir la flotte d un point fabrique.
        if ($patrol->state !== PatrolState::Stationed || $patrol->x === null || $patrol->y === null) {
            return 'must_be_stationed_to_attack';
        }

        return $this->homeCoordinateOf($patrol) === null ? 'no_home_left' : null;
    }

    /**
     * Le devis d une attaque lancee depuis le point de la patrouille.
     *
     * @throws PatrolOrderRefused
     */
    public function quoteForAttack(Patrol $patrol, FrozenPatrolTarget $target, float $speedPercent, int $now): PatrolQuote
    {
        $refus = $this->whyAttackIsRefused($patrol, $now);

        if ($refus !== null) {
            throw new PatrolOrderRefused($refus);
        }

        return $this->attackQuote($patrol, $target, $speedPercent, $now);
    }

    /**
     * Ce que coute une frappe depuis le point, et ce qu il en restera.
     *
     * ## Le carburant est celui d un **aller-retour**, et c est la difference avec tout le reste
     *
     * Un deplacement paie un trajet : la patrouille reste ou elle arrive. Une attaque revient
     * toujours a son point — c est ce qui la distingue d un ordre de mouvement vers la cible — donc
     * elle paie les deux trajets, au depart, sur la reserve. Ne prelever que l aller rendrait le
     * retour gratuit, et une patrouille pourrait frapper indefiniment en payant moitie prix.
     *
     * La distance et la vitesse etant les memes dans les deux sens, le double du cout d un trajet
     * **est** le cout reel : rien n est estime ici.
     *
     * ## Ce que les deux derniers champs decrivent, et pourquoi ce ne sont pas ceux de l aller
     *
     * `safety_return_*` repond a « et apres, peut-elle rentrer ? ». Apres une attaque, la patrouille
     * est **de retour a son point** : le retour de securite part donc de la, pas de la cible. Prendre
     * les nombres du devis aller aurait decrit un trajet que personne ne fera jamais.
     */
    private function attackQuote(Patrol $patrol, FrozenPatrolTarget $target, float $speedPercent, int $now): PatrolQuote
    {
        $segment = $patrol->currentMission;

        if (!$segment instanceof FleetMission) {
            throw new PatrolOrderRefused('no_current_segment');
        }

        $vers = PatrolDestination::spatialPoint(
            $this->pricing->geometry(),
            $target->galaxy,
            $target->system,
            $target->point()
        );

        $aller = $this->quoteFor($patrol, $vers, $speedPercent, $now, false);

        // Un refus de geometrie ou de flotte vide se transmet tel quel : il decrit deja ce qui ne va
        // pas, et le reecrire ici en dirait moins.
        if (!$aller->isPossible()) {
            return $aller;
        }

        $base = $this->homeOf($patrol);

        if ($base === null) {
            return $aller->refusedBecause('no_home_left');
        }

        $retourDeSecurite = $this->quoteFor($patrol, $this->destinationOnto($base), $this->settings->patrolSafetyReturnSpeed(), $now, false);

        $units = $this->unitsOf($segment);
        $allerRetour = 2 * $aller->fuelCost;
        $restant = (float)$patrol->fuel_reserve - $allerRetour;

        $devis = new PatrolQuote(
            $vers,
            $aller->distance,
            $aller->durationSeconds,
            $speedPercent,
            $allerRetour,
            $restant,
            $retourDeSecurite->fuelCost,
            $retourDeSecurite->durationSeconds,
            $this->upkeep->autonomySeconds($units, $restant, (float)$retourDeSecurite->fuelCost),
            (int)$patrol->order_version
        );

        if ($restant < 0.0) {
            return $devis->refusedBecause('not_enough_fuel');
        }

        // **Frapper ne doit pas immobiliser.** Une patrouille qui rentrerait de son raid sans de quoi
        // regagner sa base serait condamnee a l immobilisation : le refus est prononce avant, pas
        // constate apres.
        if ($restant < (float)$retourDeSecurite->fuelCost) {
            return $devis->refusedBecause('no_return_reserve');
        }

        return $devis;
    }

    /**
     * La patrouille envoie ses vaisseaux frapper une cible detectee, et garde son point.
     *
     * ## Ce qui change sur la patrouille, et ce qui ne change pas
     *
     * Son segment pose est consomme et remplace par la mission d attaque : **un seul vol a la fois**,
     * comme toujours. Elle passe a `Attacking`, ce qui la rend inattaquable a son point — il n y a
     * plus rien la — sans lui faire perdre ce point : `x` et `y` restent, et c est la que le retour
     * se posera.
     *
     * Aucun creneau de flotte n est consomme : la mission d attaque **remplace** le segment pose,
     * qui en occupait deja un. La patrouille ne paie pas deux fois le meme vol.
     *
     * ## Ce qui est revalide sous verrou, et pourquoi chacun
     *
     * La **version d ordre**, comme tout ordre : un autre ordre a pu passer depuis le devis. Le
     * **segment relu** : deux confirmations simultanees consommeraient deux fois le meme vol. La
     * **barriere de suppression** : une flotte ne part pas pendant qu on efface son proprietaire.
     *
     * @throws PatrolOrderRefused
     */
    public function attackFrom(
        Patrol $patrol,
        FrozenPatrolTarget $target,
        float $speedPercent,
        int $orderVersion,
        int $now,
        int|null $quotedFuelCost = null,
    ): FleetMission {
        $refus = $this->whyAttackIsRefused($patrol, $now);

        if ($refus !== null) {
            throw new PatrolOrderRefused($refus);
        }

        $segment = $patrol->currentMission;

        if (!$segment instanceof FleetMission) {
            throw new PatrolOrderRefused('no_current_segment');
        }

        if ((int)$patrol->order_version !== $orderVersion) {
            throw new PatrolOrderRefused('stale_quote');
        }

        $units = $this->unitsOf($segment);

        // **Ce qui est du se paie avant que le devis soit refait**, comme pour un deplacement : sans
        // cela, un ordre donne juste avant l echeance effacerait la periode ecoulee.
        $this->bill($patrol, $units, $now);

        $devis = $this->attackQuote($patrol, $target, $speedPercent, $now);

        if (!$devis->isPossible()) {
            throw new PatrolOrderRefused((string)$devis->refusal);
        }

        // Le cout lu decide s il a ete rapporte : un cout devenu superieur est refuse, jamais debite
        // en silence.
        if ($quotedFuelCost !== null && $devis->fuelCost > $quotedFuelCost) {
            throw new PatrolOrderRefused('quote_cost_moved');
        }

        $depart = $this->departurePointFor($patrol, $segment, $now);

        return DB::transaction(function () use ($patrol, $segment, $units, $target, $devis, $depart, $now): FleetMission {
            if (AccountDeletionBarrier::heldState((int)$patrol->user_id) === AccountDeletionState::Pending) {
                throw new PatrolOrderRefused('account_being_deleted');
            }

            $tenu = FleetMission::query()->whereKey($segment->id)->lockForUpdate()->first();

            if (!$tenu instanceof FleetMission || (int)$tenu->processed === 1) {
                throw new PatrolOrderRefused('segment_already_settled');
            }

            $tenu->forceFill(['processed' => 1])->save();
            $segment->forceFill(['processed' => 1])->syncOriginal();

            $attaque = $this->createSpatialAttack($patrol, $tenu, $units, $target, $depart, $devis, $now);

            $patrol->forceFill([
                'state' => PatrolState::Attacking,
                'current_mission_id' => $attaque->id,
                // **Le point ne bouge pas.** Il reste celui de la patrouille pendant tout le raid :
                // c est l adresse a laquelle ses vaisseaux reviennent, et l effacer ferait retomber
                // le retour sur la base alors que rien ne l exige.
                'fuel_reserve' => max(0.0, (float)$patrol->fuel_reserve - $devis->fuelCost),
                'order_version' => (int)$patrol->order_version + 1,
                'stationed_since' => null,
            ])->save();

            return $attaque;
        });
    }

    /**
     * Ecrit la mission d attaque partie du point d une patrouille.
     *
     * C est une **attaque ordinaire de genre 1** : elle passe par `AttackMission`, occupe le creneau
     * du segment qu elle remplace, se voit sur la carte et rentre par le retour habituel. Trois
     * choses la distinguent : elle part d un point au lieu d un corps, elle nomme sa patrouille par
     * `patrol_id` — c est ce lien qui ramenera ses vaisseaux au bon endroit —, et elle porte
     * l identite gelee de sa cible.
     */
    private function createSpatialAttack(
        Patrol $patrol,
        FleetMission $segment,
        UnitCollection $units,
        FrozenPatrolTarget $target,
        SpatialPoint $depart,
        PatrolQuote $devis,
        int $now,
    ): FleetMission {
        // **L ancre administrative reste une planete vivante**, pour la meme raison qu un segment :
        // le travailleur des pages cherche les missions par les planetes du joueur, et une mission
        // ancree nulle part ne serait jamais reprise — la flotte ne rentrerait jamais.
        $ancre = $this->homeOf($patrol)?->getPlanetId();

        if ($ancre === null) {
            throw new PatrolOrderRefused('no_home_left');
        }

        $attaque = new FleetMission();

        $attaque->user_id = (int)$patrol->user_id;
        $attaque->patrol_id = (int)$patrol->id;
        $attaque->mission_type = 1;

        $attaque->planet_id_from = $ancre;
        $attaque->type_from = PlanetType::SpatialPoint->value;
        $attaque->galaxy_from = (int)$patrol->galaxy;
        $attaque->system_from = (int)$patrol->system;
        $attaque->position_from = $this->pricing->geometry()->orbitIndexOf($depart);
        $attaque->x_from = $depart->x;
        $attaque->y_from = $depart->y;

        $attaque->planet_id_to = null;
        $attaque->type_to = PlanetType::SpatialPoint->value;
        $attaque->galaxy_to = $target->galaxy;
        $attaque->system_to = $target->system;
        $attaque->position_to = 0;
        $attaque->x_to = $target->x;
        $attaque->y_to = $target->y;

        $attaque->target_patrol_id = $target->patrolId;
        $attaque->target_patrol_owner_id = $target->ownerId;

        $attaque->time_departure = $now;
        $attaque->time_arrival = $now + $devis->durationSeconds;
        $attaque->time_holding = null;
        $attaque->processed = 0;
        $attaque->canceled = 0;

        // **La cargaison suit la flotte.** Ce que la patrouille transportait part avec elle : il n y
        // a pas d endroit ou le laisser, son point n est pas un entrepot.
        $attaque->metal = (int)$segment->metal;
        $attaque->crystal = (int)$segment->crystal;
        $attaque->deuterium = (int)$segment->deuterium;

        // Le carburant vient de la reserve, jamais d une planete : rien a rendre a personne.
        $attaque->deuterium_consumption = 0;

        foreach ($units->units as $unite) {
            $attaque->{$unite->unitObject->machine_name} = $unite->amount;
        }

        $degats = DamagedHulls::fromStorage($segment->damaged_hulls);

        if (!$degats->isEmpty()) {
            $attaque->damaged_hulls = $degats->toStorage();
        }

        $attaque->save();

        return $attaque;
    }

    /**
     * Le corps commun d un ordre : les refus, le devis, puis le nouveau segment sous verrou.
     *
     * L etat de depart est la seule chose qui distingue un deplacement d un rappel : « en vol »,
     * l arrivee se pose ; « en retour », l arrivee atterrit. C est `PatrolMission::processArrival()`
     * qui lit cet etat, et rien d autre ne dit a l arrivee ce qu elle doit faire.
     *
     * @throws PatrolOrderRefused
     */
    private function dispatchOrder(Patrol $patrol, PatrolDestination $to, float $speedPercent, int $orderVersion, int $now, PatrolState $departureState, int|null $quotedFuelCost = null): FleetMission
    {
        // **La porte de l interrupteur, et son unique exception.** Elle ne porte pas sur l etat seul :
        // un ordre qui se dirait « retour » vers un autre point n aurait aucune raison d etre
        // dispense. Il faut qu il parte en retour **et** qu il vise exactement la base de cette
        // patrouille — c est la seule chose qu un chantier desarme doit encore laisser faire.
        if (!$this->settings->patrolsEnabled() && !$this->landsOnABodyOfItsOwner($patrol, $to, $departureState)) {
            throw new PatrolOrderRefused('disabled');
        }

        // Tout le reste est verifie comme avant, sans exception : ce qui suit ne connait pas
        // l interrupteur et ne relache rien.
        $refus = $this->whyAnyOrderIsRefused($patrol, $now);

        if ($refus !== null) {
            throw new PatrolOrderRefused($refus);
        }

        $segment = $patrol->currentMission;
        $proprietaire = $this->playerOf($patrol);

        // Deja etabli par `whyMoveIsRefused()` ; redit pour que l analyse statique le sache.
        if (!$segment instanceof FleetMission || $proprietaire === null) {
            throw new PatrolOrderRefused('no_current_segment');
        }

        // **La version ne se juge qu ici**, jamais dans le refus lisible d avance : elle compare le
        // devis que le joueur a lu a l etat courant, et n a de sens qu a la confirmation.
        if ((int)$patrol->order_version !== $orderVersion) {
            throw new PatrolOrderRefused('stale_quote');
        }

        $delai = $patrol->state->isParked() ? 0 : $this->settings->patrolManoeuvreDelaySeconds();

        $units = $this->unitsOf($segment);

        // **Ce qui est du se paie avant que le devis soit refait.** Sans cela, un ordre donne juste
        // avant l heure effacerait la periode ecoulee : c est l heure gratuite que la revue 120
        // interdit, recreee a chaque deplacement.
        if ($patrol->state->isParked()) {
            $this->bill($patrol, $units, $now);
        }

        $depart = $this->departurePointFor($patrol, $segment, $now);
        $base = $this->homeCoordinateOf($patrol);

        $devis = $this->pricing->quote(
            $proprietaire,
            $units,
            (float)$patrol->fuel_reserve,
            (int)$patrol->galaxy,
            (int)$patrol->system,
            $depart,
            $to,
            $speedPercent,
            (int)$patrol->order_version,
            $base ?? $to->coordinate()
        );

        if (!$devis->isPossible()) {
            throw new PatrolOrderRefused((string)$devis->refusal);
        }

        // **Le cout que le joueur a lu decide, s il l a rapporte.**
        //
        // La version d ordre ne bouge pas avec le temps, et une patrouille en vol se deplace : le
        // point de depart d une manoeuvre est interpole a l instant de la confirmation, donc la
        // distance — et le cout — peuvent avoir change depuis l affichage. Refaire le devis en
        // silence et debiter le nouveau, c est exactement le « debit different » que la revue 121
        // interdit. Un cout devenu **superieur** est donc refuse ; le joueur redemande un devis.
        //
        // Un cout devenu inferieur passe, et c est le vrai cout qui est preleve : le joueur n y perd
        // rien. Ce qui a deja ete facture au stationnement plus haut reste facture — c etait du, et
        // le curseur ne recule pas.
        if ($quotedFuelCost !== null && $devis->fuelCost > $quotedFuelCost) {
            throw new PatrolOrderRefused('quote_cost_moved');
        }

        return DB::transaction(function () use ($patrol, $segment, $units, $to, $devis, $depart, $now, $delai, $departureState): FleetMission {
            // La meme porte que partout : la ligne relue sous verrou decide.
            $tenu = FleetMission::query()->whereKey($segment->id)->lockForUpdate()->first();

            if (!$tenu instanceof FleetMission || (int)$tenu->processed === 1) {
                throw new PatrolOrderRefused('segment_already_settled');
            }

            $tenu->forceFill(['processed' => 1])->save();
            $segment->forceFill(['processed' => 1])->syncOriginal();

            $nouveau = $this->createSegment(
                $patrol,
                (int)$patrol->galaxy,
                (int)$patrol->system,
                $this->pricing->geometry()->orbitIndexOf($depart),
                PlanetType::SpatialPoint,
                null,
                $depart,
                $to,
                $units,
                new Resources((float)$tenu->metal, (float)$tenu->crystal, (float)$tenu->deuterium, 0),
                $now + $delai,
                $now + $delai + $devis->durationSeconds,
                DamagedHulls::fromStorage($tenu->damaged_hulls),
            );

            $patrol->forceFill([
                'state' => $departureState,
                'current_mission_id' => $nouveau->id,
                'galaxy' => $to->galaxy,
                'system' => $to->system,
                'x' => null,
                'y' => null,
                'fuel_reserve' => max(0.0, (float)$patrol->fuel_reserve - $devis->fuelCost),
                // **La version augmente a chaque ordre accepte** : tout devis plus ancien devient
                // caduc, et sa confirmation sera refusee au lieu de debiter autre chose.
                'order_version' => (int)$patrol->order_version + 1,
                'stationed_since' => null,
            ])->save();

            return $nouveau;
        });
    }

    /**
     * Le devis d un rappel : la destination **et** la vitesse sont celles de l ordre, pas celles
     * qu un appelant proposerait.
     *
     * ## Pourquoi le rappel a son propre devis
     *
     * Un rappel ne se decrit pas comme un deplacement ordinaire : il vole a la vitesse du retour de
     * securite, vers la base que `homeOf()` resout — une planete ou une lune, la base d attache ou
     * le repli. Un devis compose par l appelant annoncait donc une autre duree et un autre cout que
     * l ordre confirme, et son verdict `possible` pouvait diverger de celui de la confirmation. Le
     * serveur compose le rappel de bout en bout : la carte n a rien a deviner, et ne le peut plus.
     *
     * @throws PatrolOrderRefused
     */
    public function quoteForRecall(Patrol $patrol, int $now): PatrolQuote
    {
        $refus = $this->whyRecallIsRefused($patrol, $now);

        if ($refus !== null) {
            throw new PatrolOrderRefused($refus);
        }

        $base = $this->homeOf($patrol);

        if ($base === null) {
            throw new PatrolOrderRefused('no_home_left');
        }

        return $this->quoteFor($patrol, $this->destinationOnto($base), self::RECALL_SPEED, $now);
    }

    /**
     * Rappelle la patrouille : elle rentre chez elle, **a pleine vitesse**.
     *
     * Le rappel n est pas un privilege : il paie son segment comme un autre, il rapporte la version
     * du devis comme un autre, et il est refuse aux memes conditions. Ce qu il ajoute, c est de
     * pouvoir partir sans attendre l echeance.
     *
     * **Il emprunte le chemin du retour de securite, pas sa vitesse.** La lenteur de l urgence a une
     * raison — consommer moins quand la reserve est presque vide — que le rappel n a pas : le joueur
     * a du carburant et veut sa flotte. Les confondre triplait la duree du retour.
     *
     * @throws PatrolOrderRefused
     */
    public function recall(Patrol $patrol, int $orderVersion, int $now, int|null $quotedFuelCost = null): FleetMission
    {
        $refus = $this->whyRecallIsRefused($patrol, $now);

        if ($refus !== null) {
            throw new PatrolOrderRefused($refus);
        }

        $base = $this->homeOf($patrol);

        if ($base === null) {
            // Deja exclu par `whyRecallIsRefused()` ; redit pour l analyse statique.
            throw new PatrolOrderRefused('no_home_left');
        }

        // **Le rappel rentre, il ne se pose pas.** Le segment part « en retour », et c est cet etat
        // que l arrivee lit pour atterrir. Parti « en vol », il se posait a cote de la planete au
        // lieu d y rendre ses vaisseaux : le temoin du rappel jusqu au sol l a montre. Et la cible
        // porte l identite de la base **qui existe** — le repli si la base a disparu —, jamais
        // l identifiant d une planete detruite a cote des coordonnees d une autre.
        // **La version vient de l appelant, jamais de la ligne.** Se donner soi-meme la version
        // courante rendait le controle toujours vrai : un devis de rappel affiche, un autre ordre
        // accepte entre-temps, et la confirmation partait quand meme depuis un autre point et pour
        // un autre cout que ceux qui avaient ete lus.
        return $this->dispatchLanding($patrol, $base, $orderVersion, $now, $quotedFuelCost);
    }

    /**
     * **Pourquoi le joueur peut-il se poser sur ce corps ?** — ou pourquoi il ne le peut pas.
     *
     * **Aucun interrupteur ici**, et c est deliberé : se poser est une **sortie**. Un chantier
     * desarme ferme les nouvelles entrees, il ne retient aucune flotte deja en l air. C est la meme
     * regle que le rappel, appliquee a une destination choisie au lieu de la base.
     *
     * Les deux refus propres a cet ordre disent ce qu ils refusent et rien de plus : le corps a
     * disparu, ou il n est pas a vous. Aucun des deux n apprend quoi que ce soit sur un corps
     * etranger que le joueur ne verrait pas deja dans sa Galaxie.
     */
    public function whyLandingIsRefused(Patrol $patrol, PlanetService $body, int $now): string|null
    {
        $refus = $this->whyAnyOrderIsRefused($patrol, $now);

        if ($refus !== null) {
            return $refus;
        }

        if ($body->isDestroyed()) {
            return 'body_destroyed';
        }

        if (!$this->belongsToTheSameOwner($patrol, $body)) {
            return 'not_your_body';
        }

        return null;
    }

    /**
     * Le devis d un atterrissage sur un corps choisi : pleine vitesse, comme un rappel.
     *
     * Le rappel **est** ce meme ordre vers la base ; l un et l autre passent par la meme
     * composition, pour que le devis ne puisse pas decrire un trajet que la confirmation
     * n executerait pas.
     *
     * @throws PatrolOrderRefused
     */
    public function quoteForLanding(Patrol $patrol, PlanetService $body, int $now): PatrolQuote
    {
        $refus = $this->whyLandingIsRefused($patrol, $body, $now);

        if ($refus !== null) {
            throw new PatrolOrderRefused($refus);
        }

        return $this->quoteFor($patrol, $this->destinationOnto($body), self::RECALL_SPEED, $now);
    }

    /**
     * Pose la patrouille sur un corps choisi : la flotte, la cargaison et la reserve y reviennent,
     * et la patrouille cesse d exister.
     *
     * L arrivee n a rien de neuf a apprendre : `homecomingBase()` relit `planet_id_to`, verifie le
     * proprietaire, et `land()` credite sous verrou. Tout ce que cet ordre ajoute est le choix du
     * corps.
     *
     * @throws PatrolOrderRefused
     */
    public function landOn(Patrol $patrol, PlanetService $body, int $orderVersion, int $now, int|null $quotedFuelCost = null): FleetMission
    {
        $refus = $this->whyLandingIsRefused($patrol, $body, $now);

        if ($refus !== null) {
            throw new PatrolOrderRefused($refus);
        }

        return $this->dispatchLanding($patrol, $body, $orderVersion, $now, $quotedFuelCost);
    }

    /**
     * La composition unique d un atterrissage, partagee par le rappel et par le choix du joueur.
     *
     * **Le segment part « en retour », et c est cet etat que l arrivee lit pour atterrir.** Parti
     * « en vol », il se posait a cote du corps au lieu d y rendre ses vaisseaux. Et la cible porte
     * l identite du corps **qui existe**, jamais l identifiant d une planete detruite a cote des
     * coordonnees d une autre.
     *
     * **La version vient de l appelant, jamais de la ligne.** Se donner soi-meme la version courante
     * rendait le controle toujours vrai : un devis affiche, un autre ordre accepte entre-temps, et
     * la confirmation partait quand meme depuis un autre point et pour un autre cout que ceux lus.
     *
     * @throws PatrolOrderRefused
     */
    private function dispatchLanding(Patrol $patrol, PlanetService $body, int $orderVersion, int $now, int|null $quotedFuelCost): FleetMission
    {
        return $this->dispatchOrder(
            $patrol,
            $this->destinationOnto($body),
            self::RECALL_SPEED,
            $orderVersion,
            $now,
            PatrolState::Returning,
            $quotedFuelCost
        );
    }

    /**
     * **Cet ordre pose-t-il vraiment la flotte sur un corps de son proprietaire ?**
     *
     * C est l unique exception de l interrupteur, et elle reste **semantique**, jamais un simple
     * etat : trois conditions, et les trois comptent.
     *
     * 1. le depart se fait en retour — c est ce que `recall()` et `landOn()` posent ;
     * 2. la destination **atterrit** (`landsOnTheBody`), elle ne se contente pas d en approcher ;
     * 3. le corps vise vit encore et appartient au proprietaire de la patrouille, et la destination
     *    est **exactement** celle que le service compose vers lui.
     *
     * Un appel qui emprunterait l etat `Returning` pour aller ailleurs ne passe donc pas, et
     * l exception ne peut pas servir a deplacer une patrouille pendant que le chantier est desarme :
     * tout ce qu elle autorise est une **sortie**, qui dissout la patrouille a l arrivee.
     *
     * ## Pourquoi elle s est elargie de la base a tous les corps du joueur
     *
     * Elle ne connaissait que `homeOf()`. Depuis que le joueur peut poser sa flotte sur n importe
     * laquelle de ses planetes, s en tenir a la base aurait rendu l atterrissage impossible des que
     * le chantier est desarme — soit exactement le defaut que cette exception existe pour empecher :
     * **un interrupteur ferme les entrees, il n emprisonne jamais ce qui existe.** Le garde n a pas
     * faibli pour autant, il a seulement change de question : « est-ce sa base ? » devient « est-ce
     * un corps a lui, et s y pose-t-on vraiment ? ».
     */
    private function landsOnABodyOfItsOwner(Patrol $patrol, PatrolDestination $to, PatrolState $departureState): bool
    {
        if ($departureState !== PatrolState::Returning) {
            return false;
        }

        // Approcher un corps n est pas s y poser : seule la seconde forme dissout la patrouille.
        if (!$to->landsOnTheBody || $to->bodyId === null) {
            return false;
        }

        $corps = $this->liveBodyOfTheOwner($patrol, (int)$to->bodyId);

        if ($corps === null) {
            return false;
        }

        return $to->equals($this->destinationOnto($corps));
    }

    /**
     * Ce corps vit-il encore, et est-il a ce joueur ? Rend le service, ou rien.
     *
     * Le rendre plutot qu un booleen evite a l appelant une seconde resolution, et surtout une
     * seconde chance de se tromper de corps entre la question et l usage.
     */
    private function liveBodyOfTheOwner(Patrol $patrol, int $bodyId): PlanetService|null
    {
        try {
            $corps = resolve(PlanetServiceFactory::class)->make($bodyId, true);
        } catch (Throwable) {
            return null;
        }

        if (!$corps instanceof PlanetService || $corps->isDestroyed()) {
            return null;
        }

        return $this->belongsToTheSameOwner($patrol, $corps) ? $corps : null;
    }

    /**
     * La destination « rentrer sur ce corps » : son voisinage, avec son identite et son genre.
     */
    private function destinationOnto(PlanetService $body): PatrolDestination
    {
        $coordonnees = $body->getPlanetCoordinates();

        return PatrolDestination::landingOn(
            $this->pricing->geometry(),
            $coordonnees->galaxy,
            $coordonnees->system,
            $coordonnees->position,
            $body->getPlanetType(),
            $body->getPlanetId()
        );
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
     *
     * ## Ce que l appelant doit garantir, parce que cette methode ne le verifie pas
     *
     * L ecriture est **inconditionnelle** : aucun controle d etat, aucun verrou pris ici. Deux
     * obligations pesent donc sur qui appelle, et elles se tiennent toutes deux en amont.
     *
     *  - **La ligne de la mission est tenue `for update`.** Sans elle, deux traitements du meme
     *    segment reposeraient la patrouille deux fois. Le jeton de traitement
     *    (`FleetMissionService::claimForProcessing()`) ne suffit pas a l affirmer : il se reprend
     *    passe son delai, et c est le verrou de ligne qui reste. Les deux chemins qui menent ici le
     *    prennent — le travailleur des pages avant `updateMission()`, et le traitement d une mission
     *    bloquee par l administration avant le sien.
     *  - **L etat admet le stationnement.** Une patrouille `Finished` reposee ici repartirait
     *    stationnee, avec un rendez-vous arme. Le garde est en amont : `updateMission()` refuse un
     *    segment deja traite, et l atterrissage marque `processed` sous verrou avant tout credit.
     *
     * Ces deux garanties sont aujourd hui tenues par les appelants, pas par cette methode. Les
     * deplacer ici demanderait de lui donner sa propre transaction, ce qui n a pas ete fait : c est
     * une obligation documentee, pas une protection en place.
     */
    public function park(Patrol $patrol, FleetMission $segment): void
    {
        $arrivee = (int)$segment->time_arrival;
        $point = $this->pointOf($segment);
        // **Le trajet dit s il a change de systeme, jamais la ligne de la patrouille.** Le depart
        // ecrit deja le systeme de destination sur cette ligne : la comparer a l arrivee la trouvait
        // toujours egale, et l instant d entree n etait jamais remis. Une patrouille venue d ailleurs
        // aurait alors porte la date de son ancien systeme, et l horloge d acquisition des reseaux de
        // surveillance — qui part de cet instant — aurait compte un sejour qui n a pas eu lieu.
        // Les deux bouts du segment, eux, sont des faits du trajet que rien ne reecrit.
        $memeSysteme = (int)$segment->galaxy_from === (int)$segment->galaxy_to
            && (int)$segment->system_from === (int)$segment->system_to;

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

        // **Changer de systeme ferme ce que l ancien voyait, et ouvre l acquisition du neuf.**
        // Un deplacement interne ne ferme rien : l horloge d acquisition court depuis l entree,
        // et la remettre a zero pour de petits sauts est exactement ce que la revue 120 interdit.
        // L acquisition, elle, est appelee dans les deux cas : elle est idempotente, et un corps
        // dont le reseau vient d etre construit doit pouvoir ouvrir son contact au passage suivant.
        if (!$memeSysteme) {
            $this->watch->revokeAllFor($patrol, $arrivee);
        }

        $this->watch->acquire($patrol, $arrivee);

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
     * L instant du prochain rendez-vous d un segment pose — le retour de securite —, ou `null` si la
     * flotte ne brule rien et n en a aucun.
     *
     * **Lu sur la ligne, jamais recalcule** : c est `time_arrival + time_holding` que le travailleur
     * attend, et une carte qui recalculerait de son cote pourrait annoncer un autre instant que celui
     * qui aura lieu.
     */
    public function nextEventAt(FleetMission $segment): int|null
    {
        if ($segment->time_holding === null || (int)$segment->time_holding >= self::HOLD_WITHOUT_END) {
            return null;
        }

        return (int)$segment->time_arrival + (int)$segment->time_holding;
    }

    /**
     * Dix ans : le rendez-vous d une patrouille qui ne brule rien, ou que rien ne peut faire partir.
     *
     * Une valeur finie plutot qu un nul, pour que `time_arrival + time_holding` reste une somme
     * d entiers que la base compare sans cas particulier.
     *
     * **Elle doit tenir dans la colonne, et cent ans n y tenaient pas.** `time_holding` est un
     * entier signe de quatre octets : au-dela de 2 147 483 647, MariaDB refuse l ecriture (erreur
     * 1264) tandis que SQLite l accepte sans rien dire. Cent ans valent 3 153 600 000, et le defaut
     * ne s est vu qu au bac, sur la premiere patrouille immobilisee. Dix ans tiennent, et disent la
     * meme chose : aucun rendez-vous que ce jeu verra.
     */
    private const int HOLD_WITHOUT_END = 10 * 365 * 24 * 3600;

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
        return DB::transaction(function () use ($patrol, $units, $jusqua): float {
            // **La ligne relue sous verrou decide, jamais celle que l appelant tenait.** Entre son
            // chargement et cette ecriture, un autre passage a pu facturer : le curseur qu il tient
            // decrirait alors un passe, et rejouer la periode prelverait deux fois. Le verrou
            // serialise, et le curseur relu dit ce qui reste vraiment du.
            $tenue = Patrol::query()->whereKey($patrol->id)->lockForUpdate()->first();

            if (!$tenue instanceof Patrol) {
                return 0.0;
            }

            $depuis = (int)($tenue->upkeep_paid_at ?? $jusqua);

            if ($jusqua <= $depuis) {
                $patrol->forceFill([
                    'fuel_reserve' => (float)$tenue->fuel_reserve,
                    'upkeep_paid_at' => $tenue->upkeep_paid_at,
                ])->syncOriginal();

                return 0.0;
            }

            $du = $this->upkeep->dueBetween($units, $depuis, $jusqua);
            $preleve = min($du, (float)$tenue->fuel_reserve);
            $reste = (float)$tenue->fuel_reserve - $preleve;

            $tenue->forceFill([
                'fuel_reserve' => $reste,
                'upkeep_paid_at' => $jusqua,
            ])->save();

            // L objet de l appelant suit la ligne : il vient de la lire, il doit la lire juste.
            $patrol->forceFill(['fuel_reserve' => $reste, 'upkeep_paid_at' => $jusqua])->syncOriginal();

            return $preleve;
        });
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
     * La base d attache si elle existe encore, sinon le repli : la planete du joueur la plus proche.
     *
     * **C est elle, et pas seulement ses coordonnees, que tout retour vise.** Le segment porte son
     * identifiant et l atterrissage la lit. Ecrire l identifiant de la base a cote des coordonnees
     * du repli — ce que faisaient le rappel et le retour de securite — envoyait la flotte atterrir
     * sur une planete detruite, ou sur aucune quand le lien etait tombe a vide.
     */
    public function homeOf(Patrol $patrol): PlanetService|null
    {
        $base = $patrol->homePlanet;

        if ($base !== null && !$base->isDestroyed()) {
            $service = resolve(PlanetServiceFactory::class)->make((int)$base->id, true);

            // **Vivante ne suffit pas : encore faut-il qu elle soit la sienne.** Une base abandonnee
            // puis colonisee par un autre existe toujours et n est pas detruite, et elle etait rendue
            // ici comme si de rien n etait : les devis, les ancres et les retours de securite
            // visaient alors la planete d un tiers. Le repli sur la planete la plus proche du joueur
            // est exactement ce que ce cas demande.
            if ($service instanceof PlanetService && $this->belongsToTheSameOwner($patrol, $service)) {
                return $service;
            }
        }

        return $this->nearestOwnPlanetOf($patrol);
    }

    /**
     * Les coordonnees de la base d attache, ou celles du repli si elle a disparu.
     */
    public function homeCoordinateOf(Patrol $patrol): Coordinate|null
    {
        return $this->homeOf($patrol)?->getPlanetCoordinates();
    }

    /**
     * La planete du joueur la plus proche du point courant, telle que le joueur charge la connait.
     *
     * **Lecture d agrement, jamais de decision.** La liste vient du service du joueur, donc de
     * l instant ou il a ete charge. Elle convient a un devis ou a une ancre d affichage ; elle ne
     * convient pas a un ordre qui engage une flotte — pour cela, `homeUnderLock()`.
     */
    private function nearestOwnPlanetOf(Patrol $patrol): PlanetService|null
    {
        $joueur = $this->playerOf($patrol);

        if ($joueur === null) {
            return null;
        }

        return $this->nearestOf($patrol, $joueur->planets->allPlanets());
    }

    /**
     * La base vers laquelle un ordre peut reellement partir, decidee sur des lignes relues sous verrou.
     *
     * ## Pourquoi une liste chargee ne peut pas decider d un depart
     *
     * `homeOf()` refuse deja une base passee en d autres mains, puis se rabat sur « la planete du
     * joueur la plus proche » — prise dans la liste que le service du joueur porte depuis son
     * chargement. Sous `REPEATABLE READ`, meme une relecture ordinaire rendrait cet instant-la :
     * seule une lecture verrouillante voit ce que la base dit maintenant. Le repli renvoyait donc
     * la patrouille vers le corps qui venait de lui echapper, et le garde de `homeOf()` etait defait
     * par son propre recours. Trouve par la course de l atterrissage sur le bac MariaDB, jamais par
     * la suite locale : sous SQLite les deux valeurs coincident.
     *
     * Les lignes sont prises **par identifiant croissant**, l ordre que tout le depot emploie pour
     * les corps, et le travailleur des pages verrouille deja le meme ensemble.
     */
    public function homeUnderLock(Patrol $patrol): PlanetService|null
    {
        $identifiants = $this->ownPlanetIdsUnderLock($patrol);

        if ($identifiants === []) {
            return null;
        }

        $corps = [];

        foreach ($identifiants as $identifiant) {
            $service = resolve(PlanetServiceFactory::class)->make($identifiant, true);

            if ($service instanceof PlanetService) {
                $corps[] = $service;
            }
        }

        $base = $patrol->homePlanet;

        if ($base !== null && in_array((int)$base->id, $identifiants, true)) {
            foreach ($corps as $service) {
                if ($service->getPlanetId() === (int)$base->id) {
                    return $service;
                }
            }
        }

        return $this->nearestOf($patrol, $corps);
    }

    /**
     * Les planetes que ce joueur possede **maintenant**, relues sous verrou, par identifiant croissant.
     *
     * @return array<int, int>
     */
    private function ownPlanetIdsUnderLock(Patrol $patrol): array
    {
        $lignes = DB::table('planets')
            ->where('user_id', (int)$patrol->user_id)
            ->where('planet_type', PlanetType::Planet->value)
            ->where(function ($requete): void {
                $requete->whereNull('destroyed')->orWhere('destroyed', 0);
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->all();

        return array_map(static fn ($identifiant): int => (int)$identifiant, $lignes);
    }

    /**
     * Le plus proche de ces corps, a egalite le plus petit identifiant.
     *
     * Deterministe et rejouable : c est ce que le protocole de retour du combat exige deja de ses
     * propres recours, et la meme discipline s applique ici.
     *
     * @param array<int, PlanetService> $candidats
     */
    private function nearestOf(Patrol $patrol, array $candidats): PlanetService|null
    {
        $meilleure = null;
        $meilleurCout = null;
        $meilleurId = null;

        foreach ($candidats as $planete) {
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
                $meilleure = $planete;
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

        // **Le stationnement s arrete au rendez-vous, pas au passage du travailleur.** L autonomie
        // est arrondie a la seconde inferieure : a l instant du rendez-vous, la reserve couvre
        // exactement le cout du retour — c est la garantie « avant d entamer l indispensable ». Le
        // travailleur, lui, passe quand il passe ; facturer les secondes de son retard **avant** de
        // decider du depart mangeait dans la reserve reservee et rendait ce depart impayable de
        // quelques centiemes. Mesure : 133,9167 pour un cout de 134, soit une seconde de retard.
        // Le curseur ne recule jamais : quand il n y a pas de rendez-vous, l instant courant fait foi.
        $rendezVous = $this->nextEventAt($parked);
        $this->bill($patrol, $units, $rendezVous === null ? $now : min($now, $rendezVous));

        // **Le depart se decide sur des lignes tenues, pas sur une liste chargee.** Le corps vise
        // par ce retour est un ordre, pas un devis : il doit appartenir au joueur a l instant ou
        // l ordre est ecrit, et seule une lecture verrouillante le dit.
        $base = $this->homeUnderLock($patrol);
        $proprietaire = $this->playerOf($patrol);

        if ($base === null || $proprietaire === null) {
            // Plus de base et plus de proprietaire : rien a faire ici. La patrouille reste posee, et
            // c est la suppression du compte qui la traitera — jamais un retour invente.
            return null;
        }

        $geometrie = $this->pricing->geometry();
        $coordonneesBase = $base->getPlanetCoordinates();
        $vers = PatrolDestination::spatialPoint(
            $geometrie,
            $coordonneesBase->galaxy,
            $coordonneesBase->system,
            $geometrie->stationingPointNear($coordonneesBase->position)
        );

        $depart = $patrol->point() ?? $geometrie->stationingPointNear((int)$parked->position_to);
        $distance = $this->pricing->distanceBetween((int)$patrol->galaxy, (int)$patrol->system, $depart, $vers);
        $vitesse = $this->settings->patrolSafetyReturnSpeed();
        $duree = $this->fleetMissionsFor($proprietaire)->durationOverDistance($proprietaire, $units, $distance, null, $vitesse);
        $cout = $this->fleetMissionsFor($proprietaire)->consumptionOverDistance($proprietaire, $units, $distance, 0, $vitesse);

        // **Le retour se paie, il ne se donne pas.** La reserve relue apres la facturation du
        // stationnement est ce qui reste vraiment ; celle que l appelant tient decrit un passe.
        // Quand ce trajet coute plus que ce reste, la patrouille ne part pas : elle s immobilise la
        // ou elle est, posee et attaquable, et le secours borne est le seul chemin qui la remette en
        // route — c est ce que l etat `Immobilised` decrit depuis le premier jour sans que rien ne
        // le pose. Le rendez-vous est repousse sans terme : sans cela le travailleur reprendrait le
        // segment a chaque passage pour refuser le meme depart.
        $patrol->refresh();

        // **La comparaison est exacte, sans tolerance.** Le stationnement ayant cesse d etre facture
        // au rendez-vous, la reserve couvre le cout des que le socle l a promis : un manque, ici,
        // est un vrai manque. Le cas vise est le repli, ou le trajet est recalcule vers une base plus
        // lointaine que celle pour laquelle la reserve avait ete dimensionnee.
        if ($cout > (float)$patrol->fuel_reserve) {
            $this->immobilise($patrol, $parked);

            return null;
        }

        return DB::transaction(function () use ($patrol, $parked, $units, $base, $depart, $now, $duree, $cout): FleetMission|null {
            // **Le segment pose est la porte, et la base l arbitre.** Deux passages simultanes
            // liraient tous deux « non traite » ; celui qui perd la course trouve ici la ligne deja
            // marquee et repart sans creer un second retour. Le jeton de `updateMission()` couvre le
            // cas ordinaire, mais il se reprend au bout de cinq minutes : la garde ne peut pas
            // reposer sur lui seul.
            $tenu = FleetMission::query()->whereKey($parked->id)->lockForUpdate()->first();

            if (!$tenu instanceof FleetMission || (int)$tenu->processed === 1) {
                return null;
            }

            $tenu->forceFill(['processed' => 1])->save();
            $parked->forceFill(['processed' => 1])->syncOriginal();

            $retour = $this->createSegment(
                $patrol,
                (int)$patrol->galaxy,
                (int)$patrol->system,
                (int)$parked->position_to,
                PlanetType::SpatialPoint,
                null,
                $depart,
                // La base **qui existe**, identite comprise : c est elle que l atterrissage lira.
                $this->destinationOnto($base),
                $units,
                new Resources((float)$parked->metal, (float)$parked->crystal, (float)$parked->deuterium, 0),
                $now,
                $now + $duree,
                DamagedHulls::fromStorage($parked->damaged_hulls),
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
     * Le corps sur lequel cette patrouille a le droit de se poser, ou `null` s il n en est plus un.
     *
     * ## Pourquoi cette question se pose a l arrivee, et pas seulement au depart
     *
     * Un retour vise le corps qu il a nomme en partant. Entre le depart et l arrivee, ce corps peut
     * avoir ete **detruit** ou avoir **change de mains** : une lune abattue, une planete abandonnee
     * puis colonisee par un autre. Se poser dessus quand meme livrerait la flotte, la cargaison et
     * la reserve au **proprietaire du moment** — un cadeau a un tiers, et une perte seche pour le
     * joueur. La destination gardee sur le segment dit ou la patrouille se rendait ; elle
     * n autorise pas la livraison.
     *
     * Ce que la patrouille fait alors est ce que le socle prevoit deja : elle **stationne sur
     * place** — elle y est physiquement — et le retour de securite la ramene vers la base qui lui
     * reste. Rien n est perdu : unites, cargaison et reserve restent a bord.
     *
     * @param Patrol $patrol
     * @param FleetMission $segment
     * @return PlanetService|null
     */
    public function homecomingBase(Patrol $patrol, FleetMission $segment): PlanetService|null
    {
        if ($segment->planet_id_to === null) {
            return null;
        }

        try {
            $base = resolve(PlanetServiceFactory::class)->make((int)$segment->planet_id_to, true);
        } catch (Throwable) {
            return null;
        }

        if (!$base instanceof PlanetService || $base->isDestroyed()) {
            return null;
        }

        return $this->belongsToTheSameOwner($patrol, $base) ? $base : null;
    }

    /**
     * Ce corps appartient-il au proprietaire de cette patrouille ?
     *
     * @param Patrol $patrol
     * @param PlanetService $body
     * @return bool
     */
    private function belongsToTheSameOwner(Patrol $patrol, PlanetService $body): bool
    {
        $proprietaire = $body->getPlayer();

        return $proprietaire !== null && $proprietaire->getId() === (int)$patrol->user_id;
    }

    /**
     * La patrouille reste posee, sans carburant pour rentrer.
     *
     * Le segment pose n est pas marque traite : il porte toujours le creneau, la presence dans la
     * boite d evenements et l inscription au combat. Seul son rendez-vous est repousse, pour que le
     * travailleur cesse de proposer un depart que la reserve ne paie pas.
     *
     * @param Patrol $patrol
     * @param FleetMission $parked
     * @return void
     */
    private function immobilise(Patrol $patrol, FleetMission $parked): void
    {
        DB::transaction(function () use ($patrol, $parked): void {
            $tenu = FleetMission::query()->whereKey($parked->id)->lockForUpdate()->first();

            if (!$tenu instanceof FleetMission || (int)$tenu->processed === 1) {
                return;
            }

            $tenu->forceFill(['time_holding' => self::HOLD_WITHOUT_END])->save();
            $parked->forceFill(['time_holding' => self::HOLD_WITHOUT_END])->syncOriginal();

            $patrol->forceFill(['state' => PatrolState::Immobilised])->save();
        });
    }

    /**
     * La patrouille se pose chez elle : tout revient, et elle cesse d exister.
     *
     * Unites, cargaison **et reserve restante** rejoignent la planete. La reserve n a jamais ete
     * consommee que par les segments et le stationnement ; ce qui reste appartient au joueur, et le
     * lui rendre est la seule ecriture qui ferme le compte du carburant.
     *
     * **La propriete se verifie la ou le credit s ecrit, sous le meme verrou.** Un controle fait
     * avant la transaction decrit un passe : entre lui et l ecriture, la planete peut changer de
     * mains ou etre detruite, et la flotte partirait au proprietaire du moment. La ligne du corps
     * est donc relue `for update` **dans** cette transaction, et rien n est marque ni credite si
     * elle ne repond plus.
     *
     * Rend `true` si la patrouille s est posee. **`false` n est pas un detail** : le segment reste
     * non traite et la patrouille intacte, et l appelant doit alors la poser sur place et reprendre
     * le retour de securite. L ignorer laisserait une flotte en vol perpetuel.
     */
    public function land(Patrol $patrol, FleetMission $segment, int $now, PlanetService $home): bool
    {
        return DB::transaction(function () use ($patrol, $segment, $now, $home): bool {
            // **La meme porte que le depart, pour la meme raison** : deux passages simultanes
            // rendraient les vaisseaux deux fois, et le carburant avec. Le marquage vient donc avant
            // tout credit, sous verrou, et le perdant de la course ne credite rien.
            $tenu = FleetMission::query()->whereKey($segment->id)->lockForUpdate()->first();

            if (!$tenu instanceof FleetMission || (int)$tenu->processed === 1) {
                return false;
            }

            // **Le corps decide ici, relu sous verrou.** Ni le service que l appelant tient, ni un
            // controle fait avant la transaction : tous deux decrivent un instant revolu.
            $corps = DB::table('planets')->where('id', $home->getPlanetId())->lockForUpdate()->first();

            if ($corps === null || (int)$corps->user_id !== (int)$patrol->user_id || (int)$corps->destroyed > 0) {
                return false;
            }

            $tenu->forceFill(['processed' => 1])->save();
            $segment->forceFill(['processed' => 1])->syncOriginal();

            $home->addUnits($this->unitsOf($segment));

            // Les coques entamees rentrent avec la patrouille. Une patrouille passe sa vie a se
            // battre en espace libre : c est le chemin par lequel des degats reviennent le plus
            // souvent, et atterrir n en repare aucun.
            if ($this->settings->hullDamageEnabled()) {
                $home->landDamagedHulls(DamagedHulls::fromStorage($segment->damaged_hulls));
            }

            // **La reserve rentre en unites entieres.** Le stationnement se facture au prorata de la
            // seconde et laisse une fraction de deuterium ; la frontiere economique refuse de la
            // crediter, a raison. Le plancher est un fait dit ici : la fraction reste dans l espace.
            // Le retour de securite passait par coincidence — sa reserve tombait juste — et c est le
            // temoin du rappel jusqu au sol qui a vu le refus.
            $home->addResourcesAtomic(new Resources(
                (float)$segment->metal,
                (float)$segment->crystal,
                (float)$segment->deuterium + floor(max(0.0, (float)$patrol->fuel_reserve)),
                0
            ));

            $patrol->forceFill([
                'state' => PatrolState::Finished,
                'current_mission_id' => null,
                'fuel_reserve' => 0,
                'x' => null,
                'y' => null,
                'finished_at' => $now,
                'finish_reason' => 'came_home',
            ])->save();

            // La patrouille n existe plus dans l espace : ce que les reseaux voyaient d elle
            // cesse de se voir. Revoque, jamais efface — la ligne reste lisible.
            $this->watch->revokeAllFor($patrol, $now);

            return true;
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
        DamagedHulls|null $damagedHulls = null,
    ): FleetMission {
        $segment = new FleetMission();

        $segment->user_id = (int)$patrol->user_id;
        $segment->patrol_id = (int)$patrol->id;
        $segment->mission_type = 11;

        // **Les coques entamees passent d un segment au suivant.** Une patrouille est une suite de
        // `FleetMission` : sans cette ligne, une flotte abimee au premier segment repartirait neuve
        // au deuxieme, et le systeme serait faux precisement la ou il sert le plus.
        //
        // Par defaut `null` : un segment cree sans rien preciser ne transporte aucun degat, ce qui
        // est le bon comportement pour tous les appelants qui ne s en occupent pas encore.
        if ($damagedHulls !== null && !$damagedHulls->isEmpty()) {
            $segment->damaged_hulls = $damagedHulls->toStorage();
        }

        // **L ancre administrative est une planete vivante.** Le travailleur des pages cherche les
        // missions par les planetes du joueur, et cette liste exclut les corps detruits : un segment
        // ancre sur une base detruite — ou sur `0`, quand le lien etait tombe a vide — n etait plus
        // jamais repris. Le corps de depart quand il y en a un, sinon la base qui existe.
        $ancre = $bodyFrom ?? $this->homeOf($patrol)?->getPlanetId();

        if ($ancre === null) {
            throw new PatrolOrderRefused('no_home_left');
        }

        $segment->planet_id_from = $ancre;
        $segment->type_from = $typeFrom->value;
        $segment->galaxy_from = $galaxyFrom;
        $segment->system_from = $systemFrom;
        $segment->position_from = $positionFrom;
        $segment->x_from = $pointFrom?->x;
        $segment->y_from = $pointFrom?->y;

        // **Seul un atterrissage nomme son corps.** Un stationnement au voisinage d un corps n y
        // arrive pas, et l y inscrire le donnait a voir au proprietaire de ce corps — la boite
        // d evenements rend toute mission arrivant sur une de ses planetes — sans le moindre
        // detecteur. Le suivi ne perd rien : `planet_id_from` porte toujours la base d attache, et
        // c est par elle que le travailleur des pages trouve la mission.
        $segment->planet_id_to = $to->landsOnTheBody ? $to->bodyId : null;
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
}
