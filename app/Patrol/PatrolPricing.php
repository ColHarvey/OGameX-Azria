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
    ): PatrolQuote {
        $distance = $this->distanceBetween($galaxyFrom, $systemFrom, $from, $to);

        // **Une flotte vide n a ni vitesse ni soif** : les formules du jeu diviseraient par zero.
        // Le refus vient donc avant elles, et le devis ne porte alors aucun nombre invente.
        if ($units->getAmount() === 0) {
            return (new PatrolQuote($to, $distance, 0, $speedPercent, 0, $reserve, 0, 0, null, $orderVersion))
                ->refusedBecause('no_units');
        }

        $duree = $this->fleetMissionService->durationOverDistance($player, $units, $distance, null, $speedPercent);
        $cout = $this->fleetMissionService->consumptionOverDistance($player, $units, $distance, 0, $speedPercent);

        // Le retour de securite, depuis la destination vers la base, a sa propre vitesse.
        $vitesseRetour = $this->settings->patrolSafetyReturnSpeed();
        $distanceRetour = $this->fleetMissionService->distanceBetweenCoordinates($to->coordinate(), $home);
        $coutRetour = $this->fleetMissionService->consumptionOverDistance($player, $units, $distanceRetour, 0, $vitesseRetour);
        $dureeRetour = $this->fleetMissionService->durationOverDistance($player, $units, $distanceRetour, null, $vitesseRetour);

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
        if ($reserveArrivee < 0) {
            return $devis->refusedBecause('not_enough_fuel');
        }

        // **La reserve de retour est protegee avant le depart, pas apres.** Un ordre qui poserait la
        // patrouille sans de quoi revenir la condamnerait au secours ; la revue 120 l interdit.
        if ($reserveArrivee < $coutRetour) {
            return $devis->refusedBecause('no_return_reserve');
        }

        return $devis;
    }
}
