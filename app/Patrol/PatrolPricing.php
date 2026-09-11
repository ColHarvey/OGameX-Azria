<?php

namespace OGame\Patrol;

use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Planet\Coordinate;
use OGame\Patrol\Geometry\SpatialPoint;
use OGame\Patrol\Geometry\SystemGeometry;
use OGame\Services\FleetMissionService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;

/**
 * Ce qu un segment de patrouille coute et dure, et ce qu il restera pour rentrer.
 *
 * ## Deux geometries, une seule formule
 *
 * A l interieur d un systeme, la distance vient de la geometrie de reference : proportionnelle a la
 * distance reelle entre les deux points, comme la revue 121 l a exige — un deplacement proche doit
 * etre plus court qu une traversee. Entre systemes ou entre galaxies, ce sont les regles du jeu qui
 * s appliquent telles quelles : un segment intersysteme ne doit pas couter autre chose qu une
 * mission ordinaire vers les memes coordonnees.
 *
 * Duree, consommation et distance intersysteme sortent toutes des **memes methodes que le reste du
 * jeu** — `FleetMissionService::durationOverDistance()`, `consumptionOverDistance()` et
 * `distanceBetweenCoordinates()`. Cette classe ne recopie aucune formule : elle choisit laquelle
 * s applique, et elle nourrit la distance.
 *
 * ## Le retour de securite fait partie du devis
 *
 * Un joueur ne peut pas juger un ordre sans savoir ce qu il lui en coutera de revenir. Chaque devis
 * calcule donc, depuis la destination vers la base, le retour a la vitesse du retour de securite —
 * 30 % par defaut — et en deduit l autonomie. C est aussi cette valeur que le serveur protege : un
 * ordre qui laisserait la patrouille incapable de rentrer est refuse avant d etre propose.
 */
final class PatrolPricing
{
    public function __construct(
        private readonly FleetMissionService $fleetMissionService,
        private readonly SettingsService $settings,
        private readonly PatrolUpkeep $upkeep,
    ) {
    }

    public function geometry(): SystemGeometry
    {
        return SystemGeometry::fromSettings($this->settings);
    }

    /**
     * La duree d un segment — **proportionnelle a la distance a l interieur d un systeme**.
     *
     * ## Pourquoi la formule du jeu ne convient pas ici
     *
     * `durationOverDistance()` vaut `35000 / vitesse x racine(distance x 10 / vitesseVaisseau) + 10`,
     * divise par le facteur de flotte. Une **racine carree** : elle ecrase les longues distances et,
     * a l inverse, fait payer un cout fixe enorme aux courtes.
     *
     * En OGame ordinaire cela ne se voit jamais — deux planetes d un meme systeme sont a 1000 unites
     * au minimum. Mais la geometrie des patrouilles produit des distances **petites par
     * construction** (`gameDistanceWithinSystem()` divise par `distanceDivisor`). Mesure du
     * 12 septembre 2026, rapportee par Keven : une patrouille qui venait de parcourir 14 unites en
     * 65 secondes se voyait reclamer **380 secondes** pour refaire ces memes 14 unites en sens
     * inverse. La meme flotte, a la meme vitesse, volait six fois plus lentement sur le trajet court.
     *
     * ## Ce que fait cette methode
     *
     * A l interieur d un systeme, la duree devient **lineaire en distance**, calibree sur la
     * traversee complete du systeme : traverser tout le systeme coute ce que la formule du jeu
     * reclamerait pour cette distance-la, et tout trajet plus court coute sa part exacte. Aller et
     * retour deviennent donc coherents, et un rappel immediat rentre en quelques secondes.
     *
     * **La vitesse reste celle du jeu** : la formule est evaluee une fois sur la traversee, donc le
     * pourcentage de vitesse, la lenteur du vaisseau le plus lent et le facteur de flotte du serveur
     * gouvernent toujours. Seule la **forme** de la courbe change, pas ce qui la regle.
     *
     * **Entre systemes, rien ne change** : les distances y sont celles du jeu, la formule du jeu leur
     * convient, et une patrouille ne doit pas traverser la galaxie plus vite qu une flotte ordinaire.
     *
     * **Le carburant n est pas touche** : il se calcule sur la distance
     * (`consumptionOverDistance()`), jamais sur la duree. Un trajet plus rapide ne coute donc pas
     * moins cher — ce serait une tout autre decision de jeu.
     */
    private function durationOver(PlayerService $player, UnitCollection $units, int $distance, float $speedPercent, bool $withinOneSystem): int
    {
        if (!$withinOneSystem) {
            return $this->fleetMissionService->durationOverDistance($player, $units, $distance, null, $speedPercent);
        }

        // La traversee complete : le diametre du systeme, le plus long trajet qui s y fasse.
        $traversee = 2 * $this->geometry()->systemRadiusUnits();
        $dureeDeLaTraversee = $this->fleetMissionService->durationOverDistance($player, $units, $traversee, null, $speedPercent);

        // **Jamais zero** : une duree nulle ferait arriver la flotte a l instant du depart, et le
        // travailleur reglerait le segment avant que le joueur ne l ait vu partir.
        return (int)max(1, (int)round($distance * $dureeDeLaTraversee / $traversee));
    }

    /**
     * La distance de jeu entre deux endroits, quels qu ils soient.
     *
     * Meme systeme : la geometrie de reference, donc le vrai ecart entre les deux points. Sinon les
     * regles du jeu, sur les coordonnees — et le point ne compte alors plus, ce qui est voulu : la
     * revue 120 interdit qu une distance intersysteme depende de l endroit ou l on se trouve dans
     * son systeme de depart.
     */
    public function distanceBetween(int $galaxyFrom, int $systemFrom, SpatialPoint $from, PatrolDestination $to): int
    {
        $geometrie = $this->geometry();

        if ($galaxyFrom === $to->galaxy && $systemFrom === $to->system) {
            return $geometrie->gameDistanceWithinSystem($from, $to->point);
        }

        return $this->fleetMissionService->distanceBetweenCoordinates(
            new Coordinate($galaxyFrom, $systemFrom, $geometrie->orbitIndexOf($from)),
            $to->coordinate()
        );
    }

    /**
     * Le devis d un segment, retour de securite compris.
     *
     * @param PlayerService $player Le proprietaire : technologies, classe, bonus de carburant.
     * @param UnitCollection $units La flotte qui vole.
     * @param float $reserve Le deuterium embarque au moment du devis.
     * @param int $orderVersion La version d ordre que la confirmation devra rapporter.
     * @param Coordinate $home Les coordonnees de la base d attache, pour le retour de securite.
     */
    public function quote(
        PlayerService $player,
        UnitCollection $units,
        float $reserve,
        int $galaxyFrom,
        int $systemFrom,
        SpatialPoint $from,
        PatrolDestination $to,
        float $speedPercent,
        int $orderVersion,
        Coordinate $home,
        bool $fleetWillStation = true,
    ): PatrolQuote {
        $distance = $this->distanceBetween($galaxyFrom, $systemFrom, $from, $to);

        // **Une flotte vide n a ni vitesse ni soif** : les formules du jeu diviseraient par zero.
        // Le refus vient donc avant elles, et le devis ne porte alors aucun nombre invente.
        if ($units->getAmount() === 0) {
            return (new PatrolQuote($to, $distance, 0, $speedPercent, 0, $reserve, 0, 0, null, $orderVersion))
                ->refusedBecause('no_units');
        }

        $duree = $this->durationOver($player, $units, $distance, $speedPercent, $galaxyFrom === $to->galaxy && $systemFrom === $to->system);
        $cout = $this->fleetMissionService->consumptionOverDistance($player, $units, $distance, 0, $speedPercent);

        // Le retour de securite, depuis la destination vers la base, a sa propre vitesse.
        //
        // **Il se mesure comme il sera mesure.** Le devis prenait la distance entre *coordonnees*,
        // celle du jeu classique, alors que le retour reel prend la distance entre *points* de la
        // carte (`PatrolOrders::safetyReturnCostOf()`). Dans un meme systeme les deux ne coincident
        // pas : le cout et l autonomie annonces pouvaient etre faux, et une reserve insuffisante
        // acceptee au lancement. Un devis doit decrire l ordre qui sera execute, pas un ordre voisin.
        $vitesseRetour = $this->settings->patrolSafetyReturnSpeed();
        $distanceRetour = $this->distanceBetween(
            $to->galaxy,
            $to->system,
            $to->point,
            PatrolDestination::spatialPoint(
                $this->geometry(),
                $home->galaxy,
                $home->system,
                $this->geometry()->stationingPointNear($home->position)
            )
        );
        $coutRetour = $this->fleetMissionService->consumptionOverDistance($player, $units, $distanceRetour, 0, $vitesseRetour);
        $dureeRetour = $this->durationOver($player, $units, $distanceRetour, $vitesseRetour, $to->galaxy === $home->galaxy && $to->system === $home->system);

        $reserveArrivee = $reserve - $cout;
        $autonomie = $this->upkeep->autonomySeconds($units, $reserveArrivee, (float)$coutRetour);

        $devis = new PatrolQuote(
            $to,
            $distance,
            $duree,
            $speedPercent,
            $cout,
            $reserveArrivee,
            $coutRetour,
            $dureeRetour,
            $autonomie,
            $orderVersion
        );

        // **Le refus vient apres le calcul, jamais a la place.** Un joueur a qui l on dit seulement
        // « impossible » ne sait pas de combien il manque ; le devis garde donc ses nombres.
        // **Les deux refus qui suivent sont des regles de stationnement.** Ils protegent une
        // patrouille qu on poserait sans de quoi revenir : elle serait condamnee au secours, et la
        // revue 120 l interdit.
        //
        // Une flotte qui ne reste pas — une attaque, qui frappe et repart — n a pas de reserve du
        // tout : son carburant est preleve sur le corps au depart, comme celui de toute mission.
        // Lui appliquer ces regles la refusait **toujours**, `0 - cout` etant negatif des que le
        // trajet coute quelque chose.
        if (!$fleetWillStation) {
            return $devis;
        }

        if ($reserveArrivee < 0) {
            return $devis->refusedBecause('not_enough_fuel');
        }

        if ($reserveArrivee < $coutRetour) {
            return $devis->refusedBecause('no_return_reserve');
        }

        return $devis;
    }
}
