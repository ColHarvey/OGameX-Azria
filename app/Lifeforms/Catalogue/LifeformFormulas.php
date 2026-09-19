<?php

namespace OGame\Lifeforms\Catalogue;

use InvalidArgumentException;
use OGame\Models\Resources;

/**
 * Les formules du catalogue des formes de vie, pures et versionnees avec lui.
 *
 * ## Ce qui est verifie contre le jeu, et comment
 *
 * - **Cout d un niveau** : ⌊base × facteur^(N−1) × N⌋ — fichier maitre, calculateur, bibliotheque
 *   Go et ses essais (Secteur residentiel niveau 35 = 120 594 / 34 455 ; Catalyseur niveau 18 =
 *   177 347 025 / 106 408 215 / 17 734 702).
 * - **Energie d un batiment** : ⌊N × base × facteur^N⌋ — Condenseur d antimatiere niveaux 1 a 4 =
 *   9, 18, 28, 38.
 * - **Duree d un batiment** : N × base × facteur^N ÷ ((1 + robots) × 2^nanites) ÷ vitesse, plancher
 *   1 s — Secteur residentiel niveau 23, robots 5, x8 = 25 min 36 s.
 * - **Duree d une technologie** : N × base × facteur^N ÷ vitesse, plancher 1 s — Emissaires niveau 2
 *   a x8 = 6 min.
 * - **Bonus d une technologie** : base × N × facteur^(N−1), en pour cent, multiplie par (1 +
 *   experience) et plafonne — page reelle des bonus : Repaire orbital niveau 9 avec 0,4 %
 *   d experience = 36,14 %.
 * - **Population exigee par un batiment de palier** : base × facteur^(N−1) — Centre de
 *   neurocalibration niveau 1 = 100 000 000 (page reelle).
 * - **Espace de vie** : base × (N + 1) × facteur^N — Secteur residentiel niveau 2 = 922 (page
 *   reelle). La formule ecrite dans le fichier maitre (N^facteur × base ÷ 100) ne correspond pas
 *   au jeu ; un fil officiel de 2022 l avait deja releve.
 *
 * ## Ce qui est une regle Azria
 *
 * Les reductions (cout, duree) s appliquent **apres** l arrondi de la valeur brute, puis sont
 * elles-memes arrondies vers le bas — comme le calculateur communautaire le fait. Un plafond de
 * bonus s applique a la valeur finale, experience comprise.
 */
final class LifeformFormulas
{
    /**
     * Le cout d un niveau, reduit d une fraction (0 a 0,99) apres arrondi.
     */
    public static function cost(LifeformObject $object, int $level, float $reduction = 0.0): Resources
    {
        if ($level < 1) {
            return new Resources(0, 0, 0, 0);
        }
        $reduction = self::fraction($reduction, 0.99);
        $brut = fn (int $base): int => (int)floor((1 - $reduction) * floor($base * ($object->costFactor ** ($level - 1)) * $level));

        return new Resources($brut($object->metal), $brut($object->crystal), $brut($object->deuterium), 0);
    }

    /**
     * L energie que demande un niveau de batiment.
     */
    public static function energy(LifeformObject $object, int $level): int
    {
        if ($level < 1 || $object->energy === 0) {
            return 0;
        }

        return (int)floor($level * $object->energy * ($object->energyFactor ** $level));
    }

    /**
     * La duree de construction d un niveau de batiment, en secondes.
     *
     * `$speed` est la vitesse economique deja multipliee par le coefficient propre aux formes de vie ;
     * `$reduction` la fraction retiree par un batiment de l espece (Megalithe), appliquee apres.
     */
    public static function buildingDuration(LifeformObject $object, int $level, int $robotics, int $nanites, float $speed, float $reduction = 0.0): int
    {
        self::requireSpeed($speed);
        if ($level < 1) {
            return 0;
        }
        $secondes = $level * $object->durationBase * ($object->durationFactor ** $level) / ((1 + $robotics) * (2 ** $nanites)) / $speed;
        $secondes = max(1, floor($secondes));

        return max(1, (int)floor($secondes * (1 - self::fraction($reduction, 0.99))));
    }

    /**
     * La duree de recherche d un niveau de technologie, en secondes.
     *
     * `$speed` est la vitesse composee (economie × recherche × coefficient des formes de vie) ;
     * `$reduction` la fraction retiree par le batiment de recherche de l espece, appliquee apres.
     */
    public static function technologyDuration(LifeformObject $object, int $level, float $speed, float $reduction = 0.0): int
    {
        self::requireSpeed($speed);
        if ($level < 1) {
            return 0;
        }
        $secondes = $level * $object->durationBase * ($object->durationFactor ** $level) / $speed;
        $secondes = max(1, floor($secondes));

        return max(1, (int)floor($secondes * (1 - self::fraction($reduction, 0.99))));
    }

    /**
     * La part qu un pour cent de formes de vie ajoute a une valeur de base entiere, tronquee a l unite.
     */
    public static function partOf(int $base, float $percent): int
    {
        // En entiers : 5 000 × 4,6 / 100 vaut 229,99999999999997 en flottant, et floor() rendait 229 (audit des bonus, journal
        // §164). Le pour cent est deja exact au millionieme ; la part se calcule en millioniemes, puis se tronque.
        return intdiv($base * (int)round($percent * 1_000_000), 100_000_000);
    }

    /**
     * Le bonus d une technologie a un niveau, en pour cent, experience comprise et plafonne.
     */
    public static function technologyBonusPercent(LifeformBonus $bonus, int $level, float $experienceFraction = 0.0): float
    {
        if ($level < 1) {
            return 0.0;
        }
        $valeur = $bonus->base * $level * ($bonus->factor ** ($level - 1)) * (1 + max(0.0, $experienceFraction));

        return self::capped($bonus, $valeur);
    }

    /**
     * Le bonus lineaire d un batiment (facteur 1), en pour cent, plafonne : Gratte-ciel, Silo, Bouclier...
     */
    public static function buildingBonusPercent(LifeformBonus $bonus, int $level): float
    {
        if ($level < 1) {
            return 0.0;
        }
        if (abs($bonus->factor - 1.0) > 1e-9) {
            throw new InvalidArgumentException("Le bonus $bonus->code n est pas lineaire (facteur $bonus->factor) : il n a pas de pourcentage par niveau.");
        }

        return self::capped($bonus, $bonus->base * $level);
    }

    /**
     * L espace de vie qu offre le logement d une espece a un niveau : base × (N + 1) × facteur^N.
     */
    public static function livingSpace(LifeformBonus $bonus, int $level): int
    {
        $level = max(0, $level);

        return (int)floor($bonus->base * ($level + 1) * ($bonus->factor ** $level));
    }

    /**
     * Une quantite qui n existe qu avec le batiment : base × N × facteur^(N−1) (nourriture produite,
     * individus formes par un batiment de palier).
     */
    public static function quantityFromLevelOne(LifeformBonus $bonus, int $level): float
    {
        if ($level < 1) {
            return 0.0;
        }

        return $bonus->base * $level * ($bonus->factor ** ($level - 1));
    }

    /**
     * La population que la planete doit porter pour construire ce niveau d un batiment de palier.
     */
    public static function populationRequired(LifeformObject $object, int $level): float
    {
        if (!$object->requiresPopulation() || $level < 1) {
            return 0.0;
        }

        return (float)$object->populationBase * ((float)$object->populationFactor ** ($level - 1));
    }

    private static function capped(LifeformBonus $bonus, float $percent): float
    {
        if ($bonus->max === null) {
            return $percent;
        }

        return min($percent, $bonus->max * 100);
    }

    private static function fraction(float $reduction, float $plafond): float
    {
        if ($reduction < 0.0) {
            throw new InvalidArgumentException("Une reduction ne peut pas etre negative ($reduction).");
        }

        return min($reduction, $plafond);
    }

    private static function requireSpeed(float $speed): void
    {
        if ($speed <= 0.0 || !is_finite($speed)) {
            throw new InvalidArgumentException("La vitesse doit etre un nombre fini strictement positif ($speed).");
        }
    }
}
