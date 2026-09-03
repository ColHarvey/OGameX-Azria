<?php

namespace OGame\Combat\Application;

use OGame\Enums\CharacterClass;
use OGame\Services\CharacterClassService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;

/**
 * Les faits d'application lus dans le monde courant.
 *
 * C'est le contexte du chemin instantane, et c'est le comportement qui existait avant qu'il porte un
 * nom : quelques millisecondes separent le calcul de l'application, et rien n'a pu bouger entre les
 * deux. Le combat durable, lui, en fige une photographie a la cloture — voir
 * `FrozenCombatApplicationContext`.
 */
final class LiveCombatApplicationContext implements CombatApplicationContext
{
    public function __construct(
        private CharacterClassService $classes,
        private SettingsService $settings,
    ) {
    }

    public function isGeneral(PlayerService $player): bool
    {
        return $this->classes->isGeneral($player->getUser());
    }

    public function reaperDebrisCollectionPercentage(PlayerService $player): float
    {
        return $this->classes->getReaperDebrisCollectionPercentage($player->getUser());
    }

    public function characterClassOf(PlayerService $player): CharacterClass|null
    {
        return $this->classes->getCharacterClass($player->getUser());
    }

    public function spaceDockLevelFor(PlanetService $originBody): int
    {
        // Une lune emprunte le chantier spatial de sa planete : c'est la ou les vaisseaux se
        // reparent. Le plancher a un est celui du calcul d'origine.
        $porteur = $originBody->isMoon() ? $originBody->planet() : $originBody;

        return max(1, $porteur->getObjectLevel('space_dock'));
    }

    public function wreckFieldMinResourcesLoss(): int
    {
        return $this->settings->wreckFieldMinResourcesLoss();
    }

    public function wreckFieldMinFleetPercentage(): int
    {
        return $this->settings->wreckFieldMinFleetPercentage();
    }
}
