<?php

namespace OGame\GameMissions\BattleEngine\Draws;

/**
 * Une suite brute d'entiers de trente-deux bits, uniformes sur 0..2^32-1.
 *
 * C'est la matiere premiere d'une source a graine : `SeededDraws` en tire des valeurs bornees
 * **par rejet** — jamais par un simple modulo, qui n'est uniforme que si la borne divise 2^32.
 * Le jeu en a une (`Xorshift32`) ; un essai peut en dicter une pour forcer un rejet et verifier
 * que PHP et Rust consomment exactement les memes tirages bruts.
 */
interface RawDraws
{
    /**
     * Le prochain entier brut, de 0 a 4 294 967 295.
     */
    public function next(): int;
}
