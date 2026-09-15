<?php

namespace OGame\Military;

use InvalidArgumentException;

/**
 * Le partage entier d un total au prorata de poids, par la methode du plus grand reste.
 *
 * ## Exact, et sans deborder
 *
 * `total * poids` deborde l entier de 64 bits des qu une bataille est grande — les valeurs sont des demi-unites de
 * ressources, et un million de chasseurs en vaut huit milliards. Le quotient et le reste de `(total mod W) * poids / W`
 * se calculent donc par doublements successifs, sans jamais former le produit ; la partie `total div W * poids` ne
 * deborde pas, `poids` ne depassant pas `W`. Aucune division flottante : deux moteurs, deux machines, un seul partage.
 *
 * ## L egalite se departage par la clef
 *
 * Les restes classent les parts ; a reste egal, la clef la plus petite recoit d abord — l ordre est celui de `ksort`,
 * donc celui des clefs de participant (`fleet:12` avant `planet:7`) ou des rangs de round (regle des cumuls, decision
 * du 14 septembre 2026).
 */
final class LargestRemainder
{
    /**
     * Au-dela, doubler un reste ou lui ajouter un terme deborderait.
     */
    private const int LIMIT = 1 << 62;

    /**
     * @param array<int|string, int> $weights Des poids strictement positifs, au moins un.
     * @return array<int|string, int> Une part par clef, dans l ordre des clefs, de somme exactement `total`.
     */
    public static function split(int $total, array $weights): array
    {
        if ($total < 0) {
            throw new InvalidArgumentException('Un total negatif ne se partage pas.');
        }

        if ($weights === []) {
            throw new InvalidArgumentException('Aucun poids : rien ne peut recevoir une part.');
        }

        $somme = 0;

        foreach ($weights as $clef => $poids) {
            if ($poids <= 0) {
                throw new InvalidArgumentException('Le poids de ' . $clef . ' n est pas strictement positif.');
            }

            $somme += $poids;

            if ($somme >= self::LIMIT) {
                throw new InvalidArgumentException('La somme des poids depasse ce que le partage exact sait traiter.');
            }
        }

        ksort($weights);

        $base = intdiv($total, $somme);
        $reste = $total % $somme;
        $parts = [];
        $restes = [];
        $attribue = 0;

        foreach ($weights as $clef => $poids) {
            [$quotient, $residu] = self::divMod($reste, $poids, $somme);
            $parts[$clef] = $base * $poids + $quotient;
            $restes[$clef] = $residu;
            $attribue += $parts[$clef];
        }

        // Ce qui manque encore vaut la somme des residus divisee par W : un entier, une unite par plus grand reste.
        $manque = $total - $attribue;
        $ordre = array_keys($restes);
        usort($ordre, static fn (int|string $a, int|string $b): int => $restes[$b] <=> $restes[$a]);

        foreach ($ordre as $clef) {
            if ($manque === 0) {
                break;
            }

            $parts[$clef]++;
            $manque--;
        }

        return $parts;
    }

    /**
     * Quotient et reste de `(r * w) / W`, exacts, pour `r < W`, `w <= W` et `W < 2^62`.
     *
     * Invariant a chaque pas : `r * prefixe(w) = q * W + reste`, avec `0 <= reste < W`. Doubler puis ajouter un bit ne
     * demande jamais plus d une soustraction, et aucune quantite ne depasse `2W`.
     *
     * @return array{0: int, 1: int}
     */
    private static function divMod(int $r, int $w, int $W): array
    {
        $quotient = 0;
        $reste = 0;

        for ($bit = 62; $bit >= 0; $bit--) {
            $quotient <<= 1;
            $reste <<= 1;

            if ($reste >= $W) {
                $reste -= $W;
                $quotient++;
            }

            if ((($w >> $bit) & 1) === 1) {
                $reste += $r;

                if ($reste >= $W) {
                    $reste -= $W;
                    $quotient++;
                }
            }
        }

        return [$quotient, $reste];
    }
}
