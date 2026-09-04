<?php

namespace OGame\GameMissions\BattleEngine\Draws;

use InvalidArgumentException;

/**
 * Des tirages rejouables : un xorshift sur trente-deux bits, a partir d'une graine.
 *
 * ## Pourquoi celui-la
 *
 * Il tient en trois decalages et trois ou-exclusifs sur un entier de trente-deux bits, sans
 * multiplication : PHP le calcule exactement avec un masque, et le moteur Rust l'ecrit en cinq
 * lignes identiques (`SeededDraws` dans `lib.rs`). Deux moteurs nourris de la meme graine tirent
 * exactement la meme suite — c'est tout ce qu'un banc de parite demande, et ce n'est pas un hasard
 * de jeu : en jeu, la source est `SystemDraws`.
 *
 * La graine zero est refusee : elle laisserait le generateur a zero pour toujours.
 */
final class SeededDraws implements BattleDraws
{
    private int $state;

    public function __construct(private readonly int $seed)
    {
        if ($seed < 1 || $seed > 0xFFFFFFFF) {
            throw new InvalidArgumentException('A seed is a non-zero thirty-two bit integer, got ' . $seed . '.');
        }

        $this->state = $seed;
    }

    /**
     * La graine, telle que le moteur Rust doit la recevoir pour tirer la meme suite.
     */
    public function seed(): int
    {
        return $this->seed;
    }

    public function forRounds(): BattleDraws
    {
        return new self($this->seed);
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
