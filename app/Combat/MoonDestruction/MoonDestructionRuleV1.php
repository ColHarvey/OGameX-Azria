<?php

namespace OGame\Combat\MoonDestruction;

/**
 * La regle de destruction telle que le jeu l'applique aujourd'hui.
 *
 * Elle delegue a `MoonDestructionOdds`, qui est la source unique des formules. Ce chantier deplace
 * **quand** le tirage a lieu et **qui** l'orchestre ; les probabilites, elles, sont celles du jeu et
 * le restent.
 */
final readonly class MoonDestructionRuleV1 implements MoonDestructionRule
{
    /**
     * L'identifiant stable de cette version.
     */
    public const string VERSION = 'moon_destruction_odds_v1';

    public function version(): string
    {
        return self::VERSION;
    }

    public function destructionChance(int $moonDiameter, int $deathstarCount): float
    {
        return MoonDestructionOdds::destructionChance($moonDiameter, $deathstarCount);
    }

    public function deathstarLossChance(int $moonDiameter): float
    {
        return MoonDestructionOdds::deathstarLossChance($moonDiameter);
    }

    public function succeeds(int $roll, float $chance): bool
    {
        return MoonDestructionOdds::succeeds($roll, $chance);
    }
}
