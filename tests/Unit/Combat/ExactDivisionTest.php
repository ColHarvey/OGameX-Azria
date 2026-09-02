<?php

namespace Tests\Unit\Combat;

use InvalidArgumentException;
use OGame\Combat\Support\ExactDivision;
use OGame\Combat\Support\ExactRatio;
use Tests\UnitTestCase;

/**
 * Le quotient et le reste, calcules ensemble, sur lesquels reposera la repartition du butin.
 *
 * La primitive n'est pas encore branchee sur l'allocateur : elle est verifiee d'abord, seule, pour
 * que le remplacement des flottants qui suivra ne melange pas deux sources d'erreur possibles.
 *
 * **Les valeurs attendues sont ecrites en dur.** Elles ont ete etablies contre bcmath, mais
 * l'extension n'est exigee ni par `composer.json` ni par le conteneur : faire dependre ces tests
 * d'elle reviendrait a ne rien verifier la ou le code tourne. Le test differentiel qui l'utilise
 * existe en complement, et s'ignore proprement quand elle manque.
 */
class ExactDivisionTest extends UnitTestCase
{
    /**
     * Les cas exacts que la repartition rencontrera.
     *
     * Chaque ligne donne le montant, le poids, le poids total, puis le quotient et le reste
     * attendus. Le tableau couvre le zero, le poids nul, le poids egal au total, les deux moities,
     * les egalites exactes sans reste, un reste egal au total moins un, les valeurs autour de
     * `2^53` — la ou un flottant cesse de distinguer deux entiers voisins — et les valeurs collees
     * a la capacite maximale d'un entier.
     *
     * @return array<string, array{0: int, 1: int, 2: int, 3: int, 4: int}>
     */
    private function exactCases(): array
    {
        $max = PHP_INT_MAX;

        return [
            'zero a repartir' => [0, 3, 7, 0, 0],
            'poids nul' => [1_000, 0, 7, 0, 0],
            'poids egal au total' => [1_000, 7, 7, 1_000, 0],
            'moitie inferieure' => [999, 3, 7, 428, 1],
            'moitie superieure' => [999, 4, 7, 570, 6],
            'reste egal au total moins un' => [1, 6, 7, 0, 6],
            'egalite exacte sans reste' => [1_000, 4, 8, 500, 0],
            'juste sous deux puissance cinquante trois' => [9_007_199_254_740_991, 3, 4, 6_755_399_441_055_743, 1],
            'exactement deux puissance cinquante trois' => [9_007_199_254_740_992, 3, 4, 6_755_399_441_055_744, 0],
            'juste au dessus de deux puissance cinquante trois' => [9_007_199_254_740_993, 3, 4, 6_755_399_441_055_744, 3],
            'le maximum sur lui meme' => [$max, $max, $max, $max, 0],
            'presque le maximum au carre' => [$max - 1, $max - 1, $max, $max - 2, 1],
            'deux fois presque le maximum' => [2, $max - 1, $max, 1, $max - 2],
            'trois fois presque le maximum' => [3, $max - 2, $max, 2, $max - 6],
            'sept fois la moitie inferieure du maximum' => [7, intdiv($max, 2), $max, 3, 4_611_686_018_427_387_900],
            'sept fois la moitie superieure du maximum' => [7, intdiv($max, 2) + 1, $max, 3, 4_611_686_018_427_387_907],
            'un seul sur le maximum' => [1, 1, $max, 0, 1],
        ];
    }

    /**
     * Chaque cas rend exactement le quotient et le reste attendus.
     */
    public function testTheExactCasesTheAllocatorWillMeet(): void
    {
        foreach ($this->exactCases() as $quoi => [$montant, $poids, $total, $quotient, $reste]) {
            $obtenu = ExactRatio::multiplyDivideWithRemainder($montant, $poids, $total);

            $this->assertSame($quotient, $obtenu->quotient, "The quotient of « {$quoi} » is no longer exact.");
            $this->assertSame($reste, $obtenu->remainder, "The remainder of « {$quoi} » is no longer exact.");
            $this->assertSame($total, $obtenu->denominator, "The case « {$quoi} » lost the weight it was divided by.");
        }
    }

    /**
     * Le reste reste dans `[0, poidsTotal[`.
     *
     * Un reste egal au denominateur signifierait une unite entiere non attribuee ; un reste negatif
     * ferait passer un participant derriere ceux qui n'ont rien.
     */
    public function testTheRemainderStaysBelowItsDenominator(): void
    {
        foreach ($this->exactCases() as $quoi => [$montant, $poids, $total]) {
            $obtenu = ExactRatio::multiplyDivideWithRemainder($montant, $poids, $total);

            $this->assertGreaterThanOrEqual(0, $obtenu->remainder, "The remainder of « {$quoi} » went below zero.");
            $this->assertLessThan($total, $obtenu->remainder, "The remainder of « {$quoi} » reached its denominator.");
        }
    }

    /**
     * L'identite de la division, verifiee directement sur les petites valeurs.
     *
     * `montant x poids = quotient x poidsTotal + reste`. C'est la definition meme du couple rendu,
     * et elle ne suppose aucune valeur attendue ecrite a la main. Le balayage est exhaustif tant
     * que le produit tient sans risque dans un entier.
     */
    public function testTheDivisionIdentityHoldsOnSmallValues(): void
    {
        $verifies = 0;

        for ($total = 1; $total <= 12; $total++) {
            for ($poids = 0; $poids <= $total; $poids++) {
                for ($montant = 0; $montant <= 40; $montant++) {
                    $obtenu = ExactRatio::multiplyDivideWithRemainder($montant, $poids, $total);

                    $this->assertSame(
                        $montant * $poids,
                        $obtenu->quotient * $total + $obtenu->remainder,
                        "The identity fails for {$montant} x {$poids} / {$total}."
                    );

                    $this->assertGreaterThanOrEqual(0, $obtenu->remainder);
                    $this->assertLessThan($total, $obtenu->remainder);

                    $verifies++;
                }
            }
        }

        $this->assertSame(3_690, $verifies, 'The sweep no longer covers what it claims to cover.');
    }

    /**
     * Le quotient est celui de l'autre point d'entree, pas un calcul voisin.
     *
     * Les deux methodes partagent la meme division longue. Ce test existe pour que ce partage soit
     * constate plutot que suppose : deux implementations voisines finiraient par diverger d'une
     * unite, et cette unite ferait echouer un reglage de reservation.
     */
    public function testTheQuotientMatchesTheFloorOnlyEntryPoint(): void
    {
        foreach ($this->exactCases() as $quoi => [$montant, $poids, $total]) {
            $this->assertSame(
                ExactRatio::floorOfProductOverDivisor($montant, $poids, $total),
                ExactRatio::multiplyDivideWithRemainder($montant, $poids, $total)->quotient,
                "The two entry points disagree on « {$quoi} »."
            );
        }
    }

    /**
     * Une part ne depasse jamais le montant a repartir.
     *
     * C'est ce qui rend le debordement impossible : le poids etant borne par le poids total, le
     * quotient est borne par le montant, qui tient deja dans un entier.
     */
    public function testAShareNeverExceedsTheAmountShared(): void
    {
        foreach ($this->exactCases() as $quoi => [$montant, $poids, $total]) {
            $this->assertLessThanOrEqual(
                $montant,
                ExactRatio::multiplyDivideWithRemainder($montant, $poids, $total)->quotient,
                "The share « {$quoi} » exceeded the amount being shared."
            );
        }
    }

    /**
     * Les entrees hors domaine sont refusees, jamais converties.
     *
     * Le poids total nul en fait partie : c'est a l'appelant de constater qu'il n'y a rien a
     * repartir. Inventer un resultat masquerait un etat qui merite d'etre vu.
     */
    public function testOutOfDomainInputsAreRefused(): void
    {
        $refus = [
            'montant negatif' => static fn (): ExactDivision => ExactRatio::multiplyDivideWithRemainder(-1, 5, 10),
            'poids negatif' => static fn (): ExactDivision => ExactRatio::multiplyDivideWithRemainder(10, -1, 10),
            'poids total nul' => static fn (): ExactDivision => ExactRatio::multiplyDivideWithRemainder(10, 0, 0),
            'poids total negatif' => static fn (): ExactDivision => ExactRatio::multiplyDivideWithRemainder(10, 5, -10),
            'poids superieur au total' => static fn (): ExactDivision => ExactRatio::multiplyDivideWithRemainder(10, 11, 10),
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
     * Deux restes ne se comparent qu'a denominateur egal.
     *
     * Trois cinquiemes passerait pour plus petit que quatre neuviemes si l'on comparait les seuls
     * numerateurs. A l'interieur d'une passe d'allocation, tous les participants partagent le meme
     * poids total ; entre deux passes, non.
     */
    public function testRemaindersAreOnlyComparableWithinOnePass(): void
    {
        $premier = ExactRatio::multiplyDivideWithRemainder(100, 3, 7);
        $second = ExactRatio::multiplyDivideWithRemainder(100, 4, 7);
        $autrePasse = ExactRatio::multiplyDivideWithRemainder(100, 4, 9);

        $this->assertTrue($premier->isComparableWith($second), 'Two shares of one pass must be comparable.');
        $this->assertFalse($premier->isComparableWith($autrePasse), 'Shares of two different passes must not be comparable.');
        $this->assertTrue($autrePasse->isComparableWith($autrePasse), 'A share must be comparable with itself.');
    }

    /**
     * Comparaison differentielle avec bcmath, en supplement.
     *
     * **Ignoree proprement quand l'extension manque**, puisque le code de production ne l'exige
     * pas. Le tirage se concentre autour des frontieres dangereuses plutot qu'uniformement : un
     * tirage uniforme ne rencontre presque jamais les valeurs collees a la limite d'un entier.
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

        foreach ([1_000_000, $max] as $echelle) {
            for ($tirage = 0; $tirage < 500; $tirage++) {
                $total = $echelle === $max ? $max - $suivant(1_000) : $suivant(1_000_000) + 1;
                $poids = max(0, $total - $suivant(1_000));
                $montant = $echelle === $max ? $suivant(3_000) + 1 : $suivant(1_000_000);

                $produit = bcmul((string)$montant, (string)$poids);
                $obtenu = ExactRatio::multiplyDivideWithRemainder($montant, $poids, $total);

                $this->assertSame(
                    (int)bcdiv($produit, (string)$total, 0),
                    $obtenu->quotient,
                    "The oracle disagrees on the quotient of {$montant} x {$poids} / {$total}."
                );

                $this->assertSame(
                    (int)bcmod($produit, (string)$total),
                    $obtenu->remainder,
                    "The oracle disagrees on the remainder of {$montant} x {$poids} / {$total}."
                );

                $cas++;
            }
        }

        $this->assertSame(1_000, $cas);
    }
}
