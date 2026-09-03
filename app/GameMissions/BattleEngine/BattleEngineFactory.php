<?php

namespace OGame\GameMissions\BattleEngine;

use OGame\Combat\Support\LootContext;
use OGame\GameMissions\BattleEngine\Models\AttackerFleet;
use OGame\GameMissions\BattleEngine\Models\DefenderFleet;
use OGame\Services\PlanetService;
use OGame\Services\SettingsService;

/**
 * Le moteur de bataille configure, construit a un seul endroit.
 *
 * Quatre missions portaient chacune le meme `switch` sur le reglage `battle_engine`, et le combat
 * durable en aurait ete la cinquieme copie. Le choix vit ici : `php` donne le moteur PHP, tout le
 * reste — `rust`, et toute valeur inconnue — le moteur Rust, comme chaque copie le faisait.
 */
final class BattleEngineFactory
{
    /**
     * @param array<int, AttackerFleet> $attackers
     * @param array<int, DefenderFleet> $defenders
     */
    public static function configured(
        SettingsService $settings,
        array $attackers,
        PlanetService $target,
        array $defenders,
        LootContext $lootContext,
    ): BattleEngine {
        return match ($settings->battleEngine()) {
            'php' => new PhpBattleEngine($attackers, $target, $defenders, $settings, $lootContext),
            default => new RustBattleEngine($attackers, $target, $defenders, $settings, $lootContext),
        };
    }
}
