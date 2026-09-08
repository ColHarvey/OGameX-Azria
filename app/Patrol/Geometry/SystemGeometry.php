<?php

namespace OGame\Patrol\Geometry;

use InvalidArgumentException;
use OGame\GameConstants\UniverseConstants;
use OGame\Services\SettingsService;

/**
 * La geometrie de reference d un systeme solaire : ce que le serveur tient pour vrai.
 *
 * ## Pourquoi elle existe
 *
 * Une patrouille se pose n importe ou dans un systeme (revue 120, D1). Pour cela il faut un espace
 * ou « n importe ou » ait un sens que le serveur controle : des coordonnees stables, une grille
 * d arrondi, des bornes, et une distance de jeu qui n emprunte rien aux pixels ni aux orbites
 * animees de la carte. La carte projette cette geometrie et applique sa rotation decorative ; un
 * clic fait l inverse et **c est ici** que le point est arrondi et valide.
 *
 * ## Les regles (proposition R1 du document de parametres, reglables)
 *
 * - Unite : l « unite spatiale » (US). L orbite de la position p a pour rayon `ORBIT_STEP × p`.
 * - Chaque position a un angle fixe : la suite de l angle d or que la carte emploie deja **avant**
 *   sa rotation. Le point de reference d un corps ne bouge donc jamais.
 * - Un point libre est arrondi a la grille (`patrol_grid_units`), a plus de `patrol_star_exclusion_units`
 *   de l etoile et a moins de `patrol_system_radius_units` du centre.
 * - Distance de jeu d un trajet interne : `max(5, ⌈d ÷ patrol_internal_distance_divisor⌉)`, d
 *   euclidienne en US. La revue 121 exige qu un deplacement proche soit plus court qu une
 *   traversee : la constante « 1000 + » de la regle entre positions est donc abandonnee pour les
 *   segments de patrouille, et la courbe se choisit sur des simulations (diviseur 3 propose : une
 *   traversee de 3000 US vaut alors un trajet planete a planete d aujourd hui).
 *
 * Entre systemes et entre galaxies, les formules existantes de `FleetMissionService` s appliquent
 * quel que soit le point de depart dans le systeme : cette classe ne les recopie pas.
 */
final class SystemGeometry
{
    /**
     * Rayon d une orbite par position, en unites spatiales. Structurel : c est l echelle de la carte.
     */
    public const int ORBIT_STEP = 100;

    /**
     * L angle d or en degres, celui de `anglesDeBase()` dans `galaxy-tactical.js`. Les deux cotes
     * doivent produire les memes angles de base : le navigateur dessine, le serveur decide.
     */
    public const float GOLDEN_ANGLE_DEGREES = 137.508;

    public function __construct(
        private readonly int $gridUnits,
        private readonly int $systemRadiusUnits,
        private readonly int $starExclusionUnits,
        private readonly int $distanceDivisor,
    ) {
        if ($gridUnits < 1 || $systemRadiusUnits < 1 || $starExclusionUnits < 0 || $distanceDivisor < 1) {
            throw new InvalidArgumentException('La geometrie de reference exige des bornes strictement positives.');
        }

        // **Une exclusion plus large que le systeme ne laisse aucun point valide.** Les deux bornes
        // viennent de deux reglages independants de l administration : sans ce controle, une saisie
        // malheureuse rendrait tout stationnement impossible en silence, et le refus porterait sur
        // chaque point sans jamais nommer la cause.
        if ($starExclusionUnits >= $systemRadiusUnits) {
            throw new InvalidArgumentException(
                'La geometrie de reference laisse un anneau vide : exclusion ' . $starExclusionUnits
                . ' unites pour un rayon de systeme de ' . $systemRadiusUnits . '.'
            );
        }
    }

    public static function fromSettings(SettingsService $settings): self
    {
        return new self(
            $settings->patrolGridUnits(),
            $settings->patrolSystemRadiusUnits(),
            $settings->patrolStarExclusionUnits(),
            $settings->patrolInternalDistanceDivisor(),
        );
    }

    public function gridUnits(): int
    {
        return $this->gridUnits;
    }

    public function systemRadiusUnits(): int
    {
        return $this->systemRadiusUnits;
    }

    public function starExclusionUnits(): int
    {
        return $this->starExclusionUnits;
    }

    public function distanceDivisor(): int
    {
        return $this->distanceDivisor;
    }

    /**
     * Le point de reference d une position orbitale (1 a 16), sur son orbite, a son angle de base.
     */
    public function bodyPoint(int $position): SpatialPoint
    {
        if ($position < UniverseConstants::MIN_PLANET_POSITION || $position > UniverseConstants::EXPEDITION_POSITION) {
            throw new InvalidArgumentException('Position orbitale inconnue : ' . $position);
        }

        $radius = self::ORBIT_STEP * $position;
        $angle = deg2rad($position * self::GOLDEN_ANGLE_DEGREES - 90.0);

        return new SpatialPoint((int)round($radius * cos($angle)), (int)round($radius * sin($angle)));
    }

    /**
     * Le point ou une patrouille se pose au voisinage d une orbite.
     *
     * `bodyPoint()` rend l **adresse** d un corps, a son angle exact : elle ne tombe pas sur la
     * grille, et c est voulu — un corps n est pas un point choisi par un joueur, et son adresse ne
     * doit pas bouger quand la grille change. Un stationnement, lui, est un point libre : il obeit a
     * la grille et aux bornes. Cette methode fait le pont, et `refusalOf()` l accepte toujours.
     */
    public function stationingPointNear(int $position): SpatialPoint
    {
        $point = $this->bodyPoint($position);

        return $this->snap((float)$point->x, (float)$point->y);
    }

    /**
     * Le point de la grille le plus proche d un couple quelconque.
     */
    public function snap(float $x, float $y): SpatialPoint
    {
        return new SpatialPoint(
            (int)round($x / $this->gridUnits) * $this->gridUnits,
            (int)round($y / $this->gridUnits) * $this->gridUnits,
        );
    }

    public function isOnGrid(SpatialPoint $point): bool
    {
        return $point->x % $this->gridUnits === 0 && $point->y % $this->gridUnits === 0;
    }

    /**
     * Pourquoi ce point n est pas un point de stationnement valide, ou `null` s il l est.
     *
     * Les motifs sont des clefs de `t_ingame.patrol.*`, pour que le refus soit traduit la ou il
     * est montre.
     */
    public function refusalOf(SpatialPoint $point): string|null
    {
        if (!$this->isOnGrid($point)) {
            return 'point_off_grid';
        }

        $norm = $point->norm();

        if ($norm < $this->starExclusionUnits) {
            return 'point_in_star';
        }

        if ($norm > $this->systemRadiusUnits) {
            return 'point_outside_system';
        }

        return null;
    }

    public function isValid(SpatialPoint $point): bool
    {
        return $this->refusalOf($point) === null;
    }

    /**
     * La distance euclidienne entre deux points, en unites spatiales.
     */
    public function euclid(SpatialPoint $a, SpatialPoint $b): float
    {
        $dx = (float)($b->x - $a->x);
        $dy = (float)($b->y - $a->y);

        return sqrt($dx * $dx + $dy * $dy);
    }

    /**
     * La distance de jeu d un trajet interne entre deux points du **meme** systeme.
     *
     * `max(5, ⌈d ÷ diviseur⌉)` : proportionnelle a la distance reelle, jamais sous les 5 de deux
     * coordonnees egales. Les formules de duree et de consommation du jeu la recoivent telle quelle.
     */
    public function gameDistanceWithinSystem(SpatialPoint $a, SpatialPoint $b): int
    {
        return max(5, (int)ceil($this->euclid($a, $b) / $this->distanceDivisor));
    }

    /**
     * L orbite la plus proche d un point (1 a 16), pour les lecteurs qui ne connaissent que des
     * positions : la boite d evenements, les messages, les anciens rendus.
     */
    public function orbitIndexOf(SpatialPoint $point): int
    {
        $index = (int)round($point->norm() / self::ORBIT_STEP);

        return max(UniverseConstants::MIN_PLANET_POSITION, min(UniverseConstants::EXPEDITION_POSITION, $index));
    }

    /**
     * Le point d un segment a une fraction de son parcours, arrondi a la grille.
     *
     * C est la position reelle d une flotte en vol dans son systeme a un instant donne : le point
     * de depart d une manoeuvre (revue 120, D4), jamais « la position la plus proche ».
     */
    public function along(SpatialPoint $from, SpatialPoint $to, float $fraction): SpatialPoint
    {
        $fraction = max(0.0, min(1.0, $fraction));

        return $this->snap(
            $from->x + ($to->x - $from->x) * $fraction,
            $from->y + ($to->y - $from->y) * $fraction,
        );
    }
}
