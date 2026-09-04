<?php

namespace OGame\GameMissions\BattleEngine\Draws;

use InvalidArgumentException;

/**
 * Un xorshift sur trente-deux bits, a partir d'une graine non nulle.
 *
 * Trois decalages et trois ou-exclusifs, sans multiplication : PHP le calcule exactement avec un
 * masque, et le moteur Rust l'ecrit en cinq lignes identiques (`Xorshift32` dans `lib.rs`). La
 * graine zero est refusee : elle laisserait le generateur a zero pour toujours.
 */
final class Xorshift32 implements RawDraws
{
    private int $state;

    public function __construct(private readonly int $seed)
    {
        if ($seed < 1 || $seed > 0xFFFFFFFF) {
            throw new InvalidArgumentException('A seed is a non-zero thirty-two bit integer, got ' . $seed . '.');
        }

        $this->state = $seed;
    }

    public function seed(): int
    {
        return $this->seed;
    }

    public function next(): int
    {
        $x = $this->state;
        $x ^= ($x << 13) & 0xFFFFFFFF;
        $x ^= $x >> 17;
        $x ^= ($x << 5) & 0xFFFFFFFF;
        $this->state = $x & 0xFFFFFFFF;

        return $this->state;
    }
}
