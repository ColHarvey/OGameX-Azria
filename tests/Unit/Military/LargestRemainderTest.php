<?php

namespace Tests\Unit\Military;

use InvalidArgumentException;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Military\LargestRemainder;
use Tests\TestCase;

/**
 * Le partage au plus grand reste : exact, de somme egale au total, departage par la clef, et sans deborder.
 */
final class LargestRemainderTest extends TestCase
{
    public function testTheSharesAddUpToTheTotalAndTheLargestRemaindersGetTheLeftover(): void
    {
        // 41 entre 200 et 600 : 10,25 et 30,75 — le reste va a la plus grande partie fractionnaire.
        $this->assertSame([CombatParticipantKey::forFleet(1) => 10, CombatParticipantKey::forFleet(2) => 31], LargestRemainder::split(41, [CombatParticipantKey::forFleet(1) => 200, CombatParticipantKey::forFleet(2) => 600]));

        $this->assertSame(['a' => 10, 'b' => 30], LargestRemainder::split(40, ['a' => 200, 'b' => 600]), 'Un partage exact recoit une unite de trop.');
    }

    public function testATieIsSettledByTheSmallestKeyFirst(): void
    {
        // 10 entre trois poids egaux : 3,33 chacun, un reste, a la plus petite clef.
        $this->assertSame(['a' => 4, 'b' => 3, 'c' => 3], LargestRemainder::split(10, ['c' => 1, 'a' => 1, 'b' => 1]));

        // Des rangs de round : l egalite va au rang le plus petit, dans l ordre numerique et non lexicographique.
        $this->assertSame([0 => 1, 1 => 1, 2 => 1, 9 => 1, 10 => 0], LargestRemainder::split(4, [10 => 1, 9 => 1, 2 => 1, 1 => 1, 0 => 1]));
    }

    public function testAZeroTotalGivesEveryKeyNothing(): void
    {
        $this->assertSame(['a' => 0, 'b' => 0], LargestRemainder::split(0, ['a' => 5, 'b' => 7]));
    }

    public function testItIsExactWhereANaiveProductWouldOverflow(): void
    {
        $total = 4_000_000_000_003;
        $poids = ['x' => 3_000_000_000_001, 'y' => 2_999_999_999_999, 'z' => 1];
        $somme = 6_000_000_000_001;

        $parts = LargestRemainder::split($total, $poids);

        $this->assertSame($total, array_sum($parts), 'Les parts ne font pas le total.');

        // Le temoin calcule chaque part en arithmetique decimale sur chaines, independante du code eprouve.
        $planchers = [];
        $restes = [];

        foreach ($poids as $clef => $p) {
            $produit = self::multiply((string)$total, (string)$p);
            [$planchers[$clef], $restes[$clef]] = self::divideBy($produit, $somme);
        }

        arsort($restes);
        $manque = $total - array_sum($planchers);
        $attendu = $planchers;

        foreach (array_keys($restes) as $clef) {
            if ($manque === 0) {
                break;
            }

            $attendu[$clef]++;
            $manque--;
        }

        ksort($attendu);
        $this->assertSame($attendu, $parts, 'Le partage exact ne rend pas les parts que la division decimale donne.');
        $this->assertGreaterThan(PHP_INT_MAX / $poids['x'], $total, 'Premisse : le produit total x poids deborderait bien un entier de 64 bits.');
    }

    /**
     * **Des residus exactement egaux, que seule la clef departage.** Avec des poids de 1, 4 et 1 mille milliards et un
     * reste pair, les trois residus exacts sont identiques : la part de plus va aux clefs les plus petites. Un partage
     * qui passerait par un produit flottant obtiendrait des residus bruites de quelques milliards — et donnerait le
     * reste a une clef au hasard des arrondis. Chaque cas ci-dessous a son propre bruit.
     */
    public function testEqualRemaindersOnLargeNumbersAreSettledByTheKeyNotByFloatingNoise(): void
    {
        $poids = ['a' => 1_000_000_000_000, 'b' => 4_000_000_000_000, 'c' => 1_000_000_000_000];
        $somme = 6_000_000_000_000;

        foreach ([2_000_000_000_002, 2_000_000_000_004, 4_000_000_000_002, 4_000_000_000_004, 2_000_000_000_008, 4_000_000_000_010, 8_000_000_000_002, 10_000_000_000_004] as $total) {
            $residus = ['a' => 0, 'b' => 0, 'c' => 0];
            $planchers = ['a' => 0, 'b' => 0, 'c' => 0];

            foreach ($poids as $clef => $p) {
                [$planchers[$clef], $residus[$clef]] = self::divideBy(self::multiply((string)$total, (string)$p), $somme);
            }

            $this->assertCount(1, array_unique($residus), 'Premisse : les residus exacts du cas ' . $total . ' ne sont pas tous egaux.');

            $manque = $total - array_sum($planchers);
            $attendu = $planchers;

            foreach (['a', 'b', 'c'] as $clef) {
                if ($manque === 0) {
                    break;
                }

                $attendu[$clef]++;
                $manque--;
            }

            $this->assertSame($attendu, LargestRemainder::split($total, $poids), 'Le cas ' . $total . ' ne departage pas des residus egaux par la clef.');
        }
    }

    public function testInvalidInputsAreRefused(): void
    {
        foreach ([
            [-1, ['a' => 1]],
            [5, []],
            [5, ['a' => 0]],
            [5, ['a' => -3, 'b' => 4]],
            [5, ['a' => 1 << 62]],
        ] as [$total, $poids]) {
            try {
                LargestRemainder::split($total, $poids);
                $this->fail('Un partage invalide a ete accepte : ' . json_encode([$total, $poids]));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Le produit de deux entiers decimaux positifs ecrits en chaines.
     */
    private static function multiply(string $a, string $b): string
    {
        $resultat = array_fill(0, strlen($a) + strlen($b), 0);

        for ($i = strlen($a) - 1; $i >= 0; $i--) {
            for ($j = strlen($b) - 1; $j >= 0; $j--) {
                $position = $i + $j + 1;
                $somme = $resultat[$position] + (int)$a[$i] * (int)$b[$j];
                $resultat[$position] = $somme % 10;
                $resultat[$position - 1] += intdiv($somme, 10);
            }
        }

        return ltrim(implode('', $resultat), '0') ?: '0';
    }

    /**
     * Quotient et reste d un entier decimal en chaine par un entier.
     *
     * @return array{0: int, 1: int}
     */
    private static function divideBy(string $a, int $diviseur): array
    {
        $quotient = 0;
        $reste = 0;

        for ($i = 0, $n = strlen($a); $i < $n; $i++) {
            $reste = $reste * 10 + (int)$a[$i];
            $quotient = $quotient * 10 + intdiv($reste, $diviseur);
            $reste %= $diviseur;
        }

        return [$quotient, $reste];
    }
}
