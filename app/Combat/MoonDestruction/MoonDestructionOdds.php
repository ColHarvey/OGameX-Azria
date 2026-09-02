<?php

namespace OGame\Combat\MoonDestruction;

/**
 * Les deux formules de la destruction de lune, et la plage des tirages.
 *
 * ## Ce chantier change le moment, pas la formule
 *
 * Passer la destruction de lune dans le cycle persistant deplace **quand** le tirage a lieu et
 * **qui** l'orchestre. Les probabilites, elles, sont celles du jeu et le restent : les recopier
 * legerement differemment changerait l'equilibre sans que personne ne l'ait decide.
 *
 * Ces methodes sont donc la copie exacte de celles de `MoonDestructionMission`, extraites pour
 * qu'il n'en existe plus qu'une. **La mission devra les adopter au moment du raccordement** ; tant
 * qu'elle garde ses copies privees, deux sources coexistent et peuvent diverger.
 *
 * Le tirage vaut `random_int(1, 100)` et la reussite est `tirage <= chance` — bornes comprises. Un
 * tirage sur `[0, 100)` avec un `<` strict donnerait des resultats voisins mais pas identiques, et
 * la difference se verrait sur les lunes extremes.
 */
final class MoonDestructionOdds
{
    /**
     * La plus petite valeur qu'un tirage peut prendre.
     */
    public const int ROLL_MINIMUM = 1;

    /**
     * La plus grande valeur qu'un tirage peut prendre.
     */
    public const int ROLL_MAXIMUM = 100;

    /**
     * La probabilite, en pourcentage, que la lune soit detruite.
     *
     * @param int $moonDiameter
     * @param int $deathstarCount Les etoiles de la mort **survivantes de cette mission**, jamais le
     *                            total mis en commun.
     * @return float
     */
    public static function destructionChance(int $moonDiameter, int $deathstarCount): float
    {
        $moonDiameter = max(1, $moonDiameter);
        $deathstarCount = max(0, $deathstarCount);
        $destructionChance = (100 - sqrt($moonDiameter)) * sqrt($deathstarCount);

        return max(0, min(100, $destructionChance));
    }

    /**
     * La probabilite, en pourcentage, que la flotte perde toutes ses etoiles de la mort.
     *
     * Un seul tirage pour la flotte entiere, comme aujourd'hui.
     *
     * @param int $moonDiameter
     * @return float
     */
    public static function deathstarLossChance(int $moonDiameter): float
    {
        $moonDiameter = max(1, $moonDiameter);
        $lossChance = sqrt($moonDiameter) / 2;

        return max(0, min(100, $lossChance));
    }

    /**
     * Si un tirage l'emporte sur une probabilite.
     *
     * Enonce une seule fois : la comparaison est `<=`, et l'ecrire a deux endroits finirait par
     * produire un `<` quelque part.
     *
     * @param int $roll
     * @param float $chance
     * @return bool
     */
    public static function succeeds(int $roll, float $chance): bool
    {
        return $roll <= $chance;
    }

    /**
     * Le seuil entier qu'une chance produit reellement.
     *
     * ## Pourquoi un entier alors que la chance est un flottant
     *
     * Le tirage est un **entier** de 1 a 100, et la reussite est `tirage <= chance`. Pour un tirage
     * entier, cette comparaison est exactement equivalente a `tirage <= plancher(chance)` : une
     * chance de 14,14 % et un seuil de 14 selectionnent les memes tirages, du premier au dernier.
     *
     * Le seuil est donc l'information **observable**, et c'est lui qu'il faut persister. Une chance
     * flottante relue apres un aller-retour JSON peut differer du dernier bit ; le seuil, lui, se
     * relit sans perte, et le resultat gele se valide contre lui.
     *
     * La chance canonique reste conservee a part, pour l'audit et l'affichage.
     *
     * @param float $chance
     * @return int
     */
    public static function thresholdFor(float $chance): int
    {
        $seuil = (int)floor($chance);

        return max(0, min(self::ROLL_MAXIMUM, $seuil));
    }

    /**
     * Si un tirage l'emporte sur un seuil entier.
     *
     * Aucun flottant n'intervient : c'est cette comparaison-la que la relecture d'un plan gele
     * refait, sans jamais recalculer une probabilite.
     *
     * @param int $roll
     * @param int $threshold
     * @return bool
     */
    public static function succeedsAgainst(int $roll, int $threshold): bool
    {
        return $roll <= $threshold;
    }
}
