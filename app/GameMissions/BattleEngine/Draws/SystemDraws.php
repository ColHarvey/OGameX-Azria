<?php

namespace OGame\GameMissions\BattleEngine\Draws;

use InvalidArgumentException;

/**
 * Les tirages du jeu : le hasard cryptographique du systeme, aux memes genres et aux memes bornes.
 *
 * Aucun journal : rien a comparer. Les formules sont celles que le moteur PHP avait deja —
 * `array_rand`, `rand(0, 100)`, `random_int(1, 10000)` — donc les memes distributions.
 */
final class SystemDraws implements BattleDraws
{
    public function targetIndex(int $count): int
    {
        if ($count < 1) {
            throw new InvalidArgumentException('A target is drawn among at least one candidate, got ' . $count . '.');
        }

        return random_int(0, $count - 1);
    }

    public function explosionPercent(): int
    {
        return random_int(0, 100);
    }

    public function rapidfireCentipercent(): int
    {
        return random_int(1, 10000);
    }

    public function chanceOutOf(int $bound): int
    {
        if ($bound < 1) {
            throw new InvalidArgumentException('A chance is drawn out of at least one, got ' . $bound . '.');
        }

        return random_int(1, $bound);
    }

    public function forRounds(): BattleDraws
    {
        return $this;
    }

    public function journal(): DrawJournal|null
    {
        return null;
    }
}
