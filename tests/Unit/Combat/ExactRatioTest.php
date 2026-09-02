<?php

namespace Tests\Unit\Combat;

use InvalidArgumentException;
use OGame\Combat\Support\ExactRatio;
use Tests\UnitTestCase;

/**
 * La division exacte sur laquelle repose le taux de pillage.
 *
 * **Les valeurs attendues sont ecrites en dur.** Elles ont d'abord ete etablies contre bcmath,
 * mais l'extension n'est exigee ni par `composer.json` ni par le conteneur : faire dependre ces
 * tests d'elle reviendrait a ne rien verifier la ou le code tourne. Le test differentiel qui
 * l'utilise existe en complement, et s'ignore proprement quand elle manque.
 */
class ExactRatioTest extends UnitTestCase
{
    /**
     * Le bonus de pillage, en points de base.
     */
    private const int BONUS = 2_500;

    /**
     * Les cinq frontieres d'arrondi de `cargo_weighted_v1`.
     *
     * L'arrondi vers le bas au centieme de pour-cent est une **regle**, pas un accident : la borne
     * reservee et le butin reel doivent partager exactement le meme, sans quoi un ecart d'un point
     * de base ferait echouer le reglage.
     */
    public function testTheFiveRoundingBoundaries(): void
    {
        $attendus = [
            'juste sous le premier point de base' => [1, 2_501, 0],
            'exactement a la frontiere' => [1, 2_500, 1],
            'partage egal' => [1, 2, 1_250],
            'presque tout' => [9_999, 10_000, 2_499],
            'exactement tout' => [1, 1, 2_500],
        ];

        foreach ($attendus as $quoi => [$numerateur, $denominateur, $attendu]) {
            $this->assertSame(
                $attendu,
                ExactRatio::floorOfProductOverDivisor(self::BONUS, $numerateur, $denominateur),
                "The boundary « {$quoi} » no longer rounds as cargo_weighted_v1 requires."
            );
        }
    }

    /**
     * Les cinq cas proches de la capacite maximale d'un entier.
     *
     * **La ou une multiplication ordinaire basculerait en flottant.** `2500 * $numerateur` deborde
     * des trois mille sept cents milliards ; le resultat deviendrait approximatif, et deux ordres
     * d'agregation differents donneraient deux taux differents.
     */
    public function testTheFiveCasesNearTheIntegerLimit(): void
    {
        $max = PHP_INT_MAX;

        $attendus = [
            'numerateur egal au denominateur' => [$max, $max, 2_500],
            'un de moins que le denominateur' => [$max - 1, $max, 2_499],
            'moitie inferieure' => [intdiv($max, 2), $max, 1_249],
            'moitie superieure' => [intdiv($max, 2) + 1, $max, 1_250],
            'un seul sur le maximum' => [1, $max, 0],
        ];

        foreach ($attendus as $quoi => [$numerateur, $denominateur, $attendu]) {
            $obtenu = ExactRatio::floorOfProductOverDivisor(self::BONUS, $numerateur, $denominateur);

            $this->assertSame($attendu, $obtenu, "The case « {$quoi} » is no longer exact near the integer limit.");
        }
    }

    /**
     * Les valeurs de l'ordre de quatre millions de milliards, atteignables par le domaine.
     *
     * Cette echelle n'est pas theorique : la capacite de fret cumulee d'attaques groupees peut
     * l'approcher, et c'est precisement au-dela qu'une multiplication ordinaire cesse d'etre juste.
     */
    public function testTheValuesAroundFourQuadrillion(): void
    {
        $enorme = 4_000_000_000_000_000;

        $this->assertSame(2_500, ExactRatio::floorOfProductOverDivisor(self::BONUS, $enorme, $enorme));
        $this->assertSame(1_250, ExactRatio::floorOfProductOverDivisor(self::BONUS, intdiv($enorme, 2), $enorme));
        $this->assertSame(250, ExactRatio::floorOfProductOverDivisor(self::BONUS, intdiv($enorme, 10), $enorme));
    }

    /**
     * Le resultat reste dans ses bornes et croit avec le numerateur.
     *
     * Deux proprietes que n'importe quelle erreur d'arithmetique casserait, et qui ne dependent
     * d'aucune valeur attendue ecrite a la main.
     */
    public function testTheResultStaysWithinItsBoundsAndGrowsWithTheNumerator(): void
    {
        $denominateur = 1_000_000;
        $precedent = -1;

        foreach ([0, 1, 399, 400, 100_000, 499_999, 500_000, 999_999, 1_000_000] as $numerateur) {
            $obtenu = ExactRatio::floorOfProductOverDivisor(self::BONUS, $numerateur, $denominateur);

            $this->assertGreaterThanOrEqual(0, $obtenu, 'The result went below zero.');
            $this->assertLessThanOrEqual(self::BONUS, $obtenu, 'The result exceeded the maximum bonus.');
            $this->assertGreaterThanOrEqual($precedent, $obtenu, 'The result decreased while the numerator grew.');

            $precedent = $obtenu;
        }

        $this->assertSame(self::BONUS, $precedent, 'A numerator equal to the denominator must yield the whole bonus.');
    }

    /**
     * Zero donne zero, par le numerateur comme par le multiplicateur.
     */
    public function testZeroYieldsZero(): void
    {
        $this->assertSame(0, ExactRatio::floorOfProductOverDivisor(self::BONUS, 0, 1_000));
        $this->assertSame(0, ExactRatio::floorOfProductOverDivisor(0, 1_000, 1_000));
    }

    /**
     * Les entrees hors domaine sont refusees, jamais converties.
     */
    public function testOutOfDomainInputsAreRefused(): void
    {
        $refus = [
            'multiplicateur negatif' => static fn (): int => ExactRatio::floorOfProductOverDivisor(-1, 10, 10),
            'numerateur negatif' => static fn (): int => ExactRatio::floorOfProductOverDivisor(10, -1, 10),
            'denominateur nul' => static fn (): int => ExactRatio::floorOfProductOverDivisor(10, 10, 0),
            'denominateur negatif' => static fn (): int => ExactRatio::floorOfProductOverDivisor(10, 10, -5),
        ];

        foreach ($refus as $quoi => $tentative) {
            try {
                $tentative();
                $this->fail("The input « {$quoi} » was accepted.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Le meme resultat quel que soit le chemin qui a produit les entrees.
     *
     * La commutativite des sommes ne vaut que si le calcul qui les consomme est exact. Ici, deux
     * decompositions du meme total doivent rendre le meme taux.
     */
    public function testTheSameInputsYieldTheSameResultWhateverThePath(): void
    {
        $total = 3_000_000;

        // Une part de 1 200 000, atteinte par deux chemins d'agregation differents.
        $part = 500_000 + 700_000;
        $autrePart = 700_000 + 300_000 + 200_000;

        $this->assertSame($part, $autrePart);
        $this->assertSame(
            ExactRatio::floorOfProductOverDivisor(self::BONUS, $part, $total),
            ExactRatio::floorOfProductOverDivisor(self::BONUS, $autrePart, $total),
        );
    }

    /**
     * Comparaison differentielle avec bcmath, en supplement.
     *
     * **Ignore proprement quand l'extension manque**, puisque le code de production ne l'exige
     * pas. Les valeurs qui comptent sont ecrites en dur dans les tests ci-dessus ; celui-ci
     * balaie plus large, autour des frontieres dangereuses plutot qu'uniformement — un tirage
     * uniforme ne les rencontre presque jamais.
     */
    public function testItAgreesWithAnArbitraryPrecisionOracle(): void
    {
        if (!extension_loaded('bcmath')) {
            $this->markTestSkipped('bcmath is absent here; the values that matter are asserted literally in the other tests.');
        }

        $max = PHP_INT_MAX;
        $etat = 20260902;
        $suivant = static function (int $borne) use (&$etat): int {
            $etat = (1103515245 * $etat + 12345) % 2147483648;

            return intdiv($etat, 65536) % $borne;
        };

        $cas = 0;

        // Deux familles : de petites valeurs, et des valeurs concentrees juste sous la limite.
        foreach ([1_000_000, $max] as $echelle) {
            for ($tirage = 0; $tirage < 500; $tirage++) {
                $denominateur = $echelle === $max ? $max - $suivant(1_000) : $suivant(1_000_000) + 1;
                $numerateur = $denominateur - $suivant(1_000);
                $numerateur = max(0, $numerateur);
                $multiplicateur = $suivant(3_000) + 1;

                $attendu = (int)bcdiv(bcmul((string)$multiplicateur, (string)$numerateur), (string)$denominateur, 0);
                $obtenu = ExactRatio::floorOfProductOverDivisor($multiplicateur, $numerateur, $denominateur);

                $this->assertSame(
                    $attendu,
                    $obtenu,
                    "Disagreement with the oracle for {$multiplicateur} x {$numerateur} / {$denominateur}."
                );

                $cas++;
            }
        }

        $this->assertSame(1_000, $cas);
    }
}
