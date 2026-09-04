<?php

namespace OGame\GameMissions\BattleEngine\Draws;

use InvalidArgumentException;

/**
 * Des tirages rejouables : un xorshift sur trente-deux bits, a partir d'une graine, avec journal.
 *
 * ## Pourquoi celui-la
 *
 * Il tient en trois decalages et trois ou-exclusifs sur un entier de trente-deux bits, sans
 * multiplication : PHP le calcule exactement avec un masque, et le moteur Rust l'ecrit en cinq
 * lignes identiques (`SeededDraws` dans `lib.rs`). Deux moteurs nourris de la meme graine tirent
 * exactement la meme suite ; le journal dit s'ils l'ont consommee de la meme facon.
 *
 * La graine zero est refusee : elle laisserait le generateur a zero pour toujours.
 */
final class SeededDraws implements BattleDraws
{
    private int $state;

    private readonly DrawJournal $journal;

    public function __construct(private readonly int $seed)
    {
        if ($seed < 1 || $seed > 0xFFFFFFFF) {
            throw new InvalidArgumentException('A seed is a non-zero thirty-two bit integer, got ' . $seed . '.');
        }

        $this->state = $seed;
        $this->journal = new DrawJournal();
    }

    /**
     * La graine, telle que le moteur Rust doit la recevoir pour tirer la meme suite.
     */
    public function seed(): int
    {
        return $this->seed;
    }

    public function targetIndex(int $count): int
    {
        if ($count < 1) {
            throw new InvalidArgumentException('A target is drawn among at least one candidate, got ' . $count . '.');
        }

        $valeur = $this->next() % $count;
        $this->journal->record('target', $count, $valeur);

        return $valeur;
    }

    public function explosionPercent(): int
    {
        $valeur = $this->next() % 101;
        $this->journal->record('explosion', 101, $valeur);

        return $valeur;
    }

    public function rapidfireCentipercent(): int
    {
        $valeur = 1 + $this->next() % 10000;
        $this->journal->record('rapidfire', 10000, $valeur);

        return $valeur;
    }

    public function chanceOutOf(int $bound): int
    {
        if ($bound < 1) {
            throw new InvalidArgumentException('A chance is drawn out of at least one, got ' . $bound . '.');
        }

        $valeur = 1 + $this->next() % $bound;
        $this->journal->record('chance', $bound, $valeur);

        return $valeur;
    }

    public function forRounds(): BattleDraws
    {
        return new self($this->seed);
    }

    public function journal(): DrawJournal
    {
        return $this->journal;
    }

    /**
     * Le prochain entier brut de trente-deux bits — le xorshift lui-meme.
     */
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
