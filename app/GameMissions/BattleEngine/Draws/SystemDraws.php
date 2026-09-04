<?php

namespace OGame\GameMissions\BattleEngine\Draws;

/**
 * Les tirages du jeu : le hasard cryptographique du systeme, un entier de trente-deux bits a la fois.
 */
final class SystemDraws implements BattleDraws
{
    public function next(): int
    {
        return random_int(0, 0xFFFFFFFF);
    }

    public function forRounds(): BattleDraws
    {
        return $this;
    }
}
