<?php

namespace OGame\Combat\Application;

use Illuminate\Support\Facades\Date;
use OGame\Combat\Exceptions\MissingHeldFleetCargo;
use OGame\Enums\CharacterClass;
use OGame\Models\FleetMission;
use OGame\Models\Resources;
use OGame\Services\CharacterClassService;
use OGame\Services\Npc\NpcThreatService;
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

    /**
     * La cargaison du renfort, lue maintenant.
     *
     * C'est le comportement d'origine du chemin instantane, et il y est juste : la bataille vient
     * d'etre calculee, et rien n'a pu toucher ces colonnes entre-temps.
     *
     * **Une mission introuvable n'est pas une flotte vide.** Rendre zero inscrivait la clef attendue
     * avec des zeros dans la photographie, et le controle de couverture passait : il prouve que
     * toutes les clefs de l'effectif y figurent, pas que la source vivante de chacune existait. Une
     * cargaison reelle disparaissait ainsi sans trace. Le refus arrete la cloture avant toute
     * ecriture.
     */
    public function heldFleetCargo(int $fleetMissionId): Resources
    {
        $mission = FleetMission::query()->whereKey($fleetMissionId)->first();

        if (!$mission instanceof FleetMission) {
            throw MissingHeldFleetCargo::because($fleetMissionId);
        }

        return new Resources((float)$mission->metal, (float)$mission->crystal, (float)$mission->deuterium, 0);
    }

    public function wreckFieldMinResourcesLoss(): int
    {
        return $this->settings->wreckFieldMinResourcesLoss();
    }

    public function wreckFieldMinFleetPercentage(): int
    {
        return $this->settings->wreckFieldMinFleetPercentage();
    }

    public function npcMotiveAgainst(PlayerService $defender): string|null
    {
        // Lu a l'arrivee et non au depart : il ne peut avoir change entre-temps que si le joueur
        // a de nouveau provoque la faction pendant le vol, auquel cas le motif recent est le plus
        // juste des deux.
        return resolve(NpcThreatService::class)->lastMotiveOf($defender);
    }

    public function npcNarrativeVariation(int $variations): int
    {
        return random_int(1, $variations);
    }

    public function debrisFieldFromShips(): int
    {
        return $this->settings->debrisFieldFromShips();
    }

    public function wreckFieldLifetimeHours(): int
    {
        return $this->settings->wreckFieldLifetimeHours();
    }

    public function applicationInstant(): int
    {
        // Le chemin instantane applique maintenant : rien ne separe le calcul de l'ecriture.
        return (int)Date::now()->timestamp;
    }
}
