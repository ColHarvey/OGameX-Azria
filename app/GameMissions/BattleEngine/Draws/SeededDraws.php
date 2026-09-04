<?php

namespace OGame\GameMissions\BattleEngine\Draws;

use InvalidArgumentException;

/**
 * Des tirages rejouables : une suite brute (un xorshift a graine, ou une suite dictee par un
 * essai), bornee **par rejet**, avec journal.
 *
 * ## Pourquoi le rejet, et non le modulo
 *
 * Un entier uniforme sur 2^32 valeurs, reduit par `% N`, n'est uniforme que si N divise 2^32 : ni
 * treize cibles, ni 101, ni 10 000 ne le font. Le biais est petit, mais il est la, et deux moteurs
 * qui le partagent ne prouvent pas « la meme distribution » — ils prouvent le meme biais. Ici la
 * plus grande borne `L`, multiple de N et au plus egale a 2^32, est calculee ; un tirage brut
 * `>= L` est rejete et un autre est tire ; le premier tirage sous `L` est reduit par `% N`.
 * Le moteur Rust ecrit exactement le meme algorithme (`bounded` dans `lib.rs`).
 *
 * ## Deux compteurs
 *
 * Le journal n'inscrit que le resultat semantique — genre, borne, valeur — et compte a part les
 * tirages bruts consommes, rejets compris. Le banc de parite compare les deux : meme bande
 * semantique, meme nombre de tirages bruts.
 */
final class SeededDraws implements BattleDraws
{
    private const int RANGE = 0x100000000;

    private readonly RawDraws $raw;

    private readonly DrawJournal $journal;

    /**
     * @param int|RawDraws $seedOrRaw Une graine pour le xorshift, ou une suite brute dictee.
     */
    public function __construct(int|RawDraws $seedOrRaw)
    {
        $this->raw = $seedOrRaw instanceof RawDraws ? $seedOrRaw : new Xorshift32($seedOrRaw);
        $this->journal = new DrawJournal();
    }

    /**
     * La graine, telle que le moteur Rust doit la recevoir pour tirer la meme suite — ou null
     * quand la suite brute est dictee par un essai.
     */
    public function seed(): int|null
    {
        return $this->raw instanceof Xorshift32 ? $this->raw->seed() : null;
    }

    public function targetIndex(int $count): int
    {
        if ($count < 1) {
            throw new InvalidArgumentException('A target is drawn among at least one candidate, got ' . $count . '.');
        }

        $valeur = $this->bounded($count);
        $this->journal->record('target', $count, $valeur);

        return $valeur;
    }

    public function explosionPercent(): int
    {
        $valeur = $this->bounded(101);
        $this->journal->record('explosion', 101, $valeur);

        return $valeur;
    }

    public function rapidfireCentipercent(): int
    {
        $valeur = 1 + $this->bounded(10000);
        $this->journal->record('rapidfire', 10000, $valeur);

        return $valeur;
    }

    public function chanceOutOf(int $bound): int
    {
        if ($bound < 1) {
            throw new InvalidArgumentException('A chance is drawn out of at least one, got ' . $bound . '.');
        }

        $valeur = 1 + $this->bounded($bound);
        $this->journal->record('chance', $bound, $valeur);

        return $valeur;
    }

    public function forRounds(): BattleDraws
    {
        $graine = $this->seed();

        if ($graine === null) {
            throw new InvalidArgumentException('A dictated raw sequence cannot start afresh: only a seed can.');
        }

        return new self($graine);
    }

    public function journal(): DrawJournal
    {
        return $this->journal;
    }

    /**
     * Un entier uniforme de 0 a `$bound - 1`, par rejet — le meme algorithme que Rust.
     */
    private function bounded(int $bound): int
    {
        $limite = self::RANGE - (self::RANGE % $bound);

        do {
            $brut = $this->raw->next();
            $this->journal->recordRawDraw();
        } while ($brut >= $limite);

        return $brut % $bound;
    }
}
