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

    /**
     * Le mot courant du generateur : tout ce qu il faut pour reprendre la suite ou elle en est.
     *
     * **Un seul entier de trente-deux bits.** C est ce qui rend la bataille progressive possible sans
     * rejouer les rounds passes : une etape enregistre ce mot, la suivante repart de la, et la suite
     * de tirages devient independante du decoupage.
     */
    public function state(): int
    {
        return $this->state;
    }

    /**
     * La meme suite, reprise a un mot deja atteint.
     *
     * La graine voyage avec, et elle n est pas decorative : c est elle que le moteur Rust recoit, et
     * c est par elle que deux moteurs se reconnaissent. Le mot, lui, dit ou l on en est.
     *
     * **Zero est refuse ici comme il l est a la graine**, et pour la meme raison : un xorshift dont
     * l etat vaut zero y reste pour toujours. Un tel mot ne peut pas venir d une suite reelle ; le
     * lire, c est lire une donnee corrompue.
     */
    public static function resumedAt(int $seed, int $state): self
    {
        if ($state < 1 || $state > 0xFFFFFFFF) {
            throw new InvalidArgumentException('A resumed state is a non-zero thirty-two bit integer, got ' . $state . '.');
        }

        $suite = new self($seed);
        $suite->state = $state;

        return $suite;
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
