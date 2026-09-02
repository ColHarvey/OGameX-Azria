<?php

namespace OGame\Combat\Support;

use InvalidArgumentException;

/**
 * Le calcul exact de `floor(multiplicateur x numerateur / denominateur)`, sans debordement.
 *
 * ## Pourquoi ne pas ecrire simplement la multiplication
 *
 * `intdiv(2500 * $fret, $total)` est juste tant que `2500 * $fret` tient dans un entier. Au-dela
 * de trois mille sept cents milliards, PHP promeut le produit en **flottant** : le resultat
 * devient approximatif, et deux ordres d'addition differents peuvent alors donner deux taux
 * differents. C'est exactement ce que la ponderation par le fret devait rendre impossible.
 *
 * Ce chantier a deja rencontre des grandeurs de combat qui depassent la capacite d'un entier.
 *
 * ## Pourquoi pas bcmath
 *
 * L'extension est presente sur le poste de developpement, mais **ni `composer.json` ni le
 * conteneur ne l'exigent**. S'en servir ferait dependre le taux de pillage d'une extension qui
 * pourrait manquer en production — et le defaut ne se verrait qu'au premier tres gros combat.
 *
 * ## La methode
 *
 * Une division longue binaire, dite du paysan russe. Le multiplicateur est parcouru bit a bit, en
 * maintenant un reste toujours inferieur au denominateur.
 *
 * **Aucune valeur trop grande n'est jamais formee**, pas meme temporairement. Le doublement du
 * reste et son addition passent par un calcul modulaire qui compare avant d'additionner : sans
 * cela, un denominateur superieur a la moitie de la capacite d'un entier ferait deborder
 * `2 x reste`, et le resultat basculerait en flottant sans le moindre avertissement.
 *
 * Le calcul est donc exact jusqu'a `PHP_INT_MAX` inclus.
 */
final class ExactRatio
{
    /**
     * `floor($multiplier * $numerator / $divisor)`, exactement.
     *
     * @param int $multiplier Facteur, positif ou nul.
     * @param int $numerator Numerateur, positif ou nul.
     * @param int $divisor Denominateur, strictement positif.
     * @return int
     */
    public static function floorOfProductOverDivisor(int $multiplier, int $numerator, int $divisor): int
    {
        if ($multiplier < 0 || $numerator < 0) {
            throw new InvalidArgumentException('Ce calcul ne travaille que sur des grandeurs positives ou nulles.');
        }

        if ($divisor <= 0) {
            throw new InvalidArgumentException('Le denominateur doit etre strictement positif.');
        }

        return self::longDivision($multiplier, $numerator, $divisor)[0];
    }

    /**
     * La division longue elle-meme : quotient et reste, en un seul parcours.
     *
     * Extraite pour que `floorOfProductOverDivisor()` et `multiplyDivideWithRemainder()` partagent
     * exactement le meme calcul. Deux implementations voisines finiraient par diverger d une unite,
     * et cette unite ferait echouer un reglage.
     *
     * @param int $multiplier
     * @param int $numerator
     * @param int $divisor
     * @return array{0: int, 1: int} Le quotient, puis le reste.
     */
    private static function longDivision(int $multiplier, int $numerator, int $divisor): array
    {
        if ($multiplier === 0 || $numerator === 0) {
            return [0, 0];
        }

        $entier = intdiv($numerator, $divisor);
        $reste = $numerator % $divisor;

        if ($entier > 0 && $multiplier > intdiv(PHP_INT_MAX, $entier)) {
            throw new InvalidArgumentException(
                'Le resultat depasserait la capacite d un entier : ce calcul rend un quotient, pas une approximation.'
            );
        }

        $resultat = $multiplier * $entier;

        if ($reste === 0) {
            return [$resultat, 0];
        }

        // Division longue : on parcourt les bits du multiplicateur du plus fort au plus faible, en
        // gardant `$accumulateur` strictement inferieur au denominateur.
        //
        // **Ni le doublement ni l'addition ne sont ecrits tels quels.** `2 * $accumulateur`
        // deborderait des que le denominateur depasse la moitie de la capacite d'un entier, et le
        // resultat basculerait en flottant sans le moindre avertissement. Les deux operations
        // passent donc par un calcul modulaire qui ne forme jamais la valeur trop grande.
        $accumulateur = 0;
        $partielle = 0;

        for ($bit = self::highestBitOf($multiplier); $bit >= 0; $bit--) {
            $partielle *= 2;

            [$accumulateur, $retenue] = self::addModulo($accumulateur, $accumulateur, $divisor);
            $partielle += $retenue;

            if ((($multiplier >> $bit) & 1) === 1) {
                [$accumulateur, $retenue] = self::addModulo($accumulateur, $reste, $divisor);
                $partielle += $retenue;
            }
        }

        return [$resultat + $partielle, $accumulateur];
    }

    /**
     * La part proportionnelle d'un participant : quotient **et** reste, du meme calcul.
     *
     * `quotient = floor($amount x $weight / $totalWeight)` et
     * `remainder = ($amount x $weight) mod $totalWeight`.
     *
     * **Les deux ensemble, jamais l'un puis l'autre.** Reconstruire le reste par
     * `($amount * $weight) % $totalWeight` reformerait le produit que cette classe existe pour
     * eviter, et ce produit deborde des que le butin et le fret sont grands.
     *
     * Un poids total nul n'est pas traite ici : c'est a l'appelant de constater qu'il n'y a rien a
     * repartir. Inventer un resultat pour une division par zero masquerait un etat qui merite
     * d'etre vu.
     *
     * @param int $amount Le montant a repartir, positif ou nul.
     * @param int $weight Le poids de ce participant, entre zero et le poids total.
     * @param int $totalWeight Le poids total, strictement positif.
     * @return ExactDivision
     */
    public static function multiplyDivideWithRemainder(int $amount, int $weight, int $totalWeight): ExactDivision
    {
        if ($amount < 0 || $weight < 0) {
            throw new InvalidArgumentException('Un montant et un poids sont positifs ou nuls.');
        }

        if ($totalWeight <= 0) {
            throw new InvalidArgumentException(
                'Le poids total doit etre strictement positif : c est a l appelant de constater qu il n y a rien a repartir.'
            );
        }

        if ($weight > $totalWeight) {
            throw new InvalidArgumentException(
                'Le poids d un participant (' . $weight . ') ne peut pas depasser le poids total (' . $totalWeight . ') : '
                . 'sa part depasserait le montant a repartir.'
            );
        }

        [$quotient, $reste] = self::longDivision($amount, $weight, $totalWeight);

        return new ExactDivision($quotient, $reste, $totalWeight);
    }

    /**
     * `($a + $b) mod $divisor`, avec la retenue, sans jamais former `$a + $b`.
     *
     * Les deux operandes sont strictement inferieurs au denominateur. Leur somme peut donc
     * atteindre presque le double de celui-ci et deborder si le denominateur est grand.
     *
     * La comparaison `$a < $divisor - $b` tranche sans risque : `$divisor - $b` est positif et
     * plus petit que le denominateur, donc calculable. Quand elle est fausse, le resultat vaut
     * `$a - ($divisor - $b)`, qui est la meme chose que `$a + $b - $divisor` sans jamais avoir
     * ecrit la somme.
     *
     * @param int $a
     * @param int $b
     * @param int $divisor
     * @return array{0: int, 1: int} La somme modulaire, et 1 si elle a debordee le denominateur.
     */
    private static function addModulo(int $a, int $b, int $divisor): array
    {
        if ($a < $divisor - $b) {
            return [$a + $b, 0];
        }

        return [$a - ($divisor - $b), 1];
    }

    /**
     * Le rang du bit de poids fort.
     *
     * @param int $value Strictement positif.
     * @return int
     */
    private static function highestBitOf(int $value): int
    {
        $rang = 0;

        while (($value >> ($rang + 1)) !== 0) {
            $rang++;
        }

        return $rang;
    }
}
