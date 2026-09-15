<?php

namespace OGame\Lifeforms\Services;

use OGame\Lifeforms\Bonuses\LifeformBonusCache;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Models\Lifeforms\LifeformBuildingLevel;
use OGame\Models\Lifeforms\LifeformTechnologyLevel;

/**
 * Les niveaux des objets de formes de vie d une planete : une ligne par niveau non nul, l absence
 * de ligne vaut zero.
 */
final class LifeformLevels
{
    /**
     * @return array<int, int> niveau par identifiant d objet
     */
    public function buildingLevelsOf(int $planetId): array
    {
        return LifeformBuildingLevel::query()->where('planet_id', $planetId)->pluck('level', 'object_id')->map(fn ($v) => (int)$v)->all();
    }

    /**
     * @return array<int, int> niveau par identifiant d objet
     */
    public function technologyLevelsOf(int $planetId): array
    {
        return LifeformTechnologyLevel::query()->where('planet_id', $planetId)->pluck('level', 'object_id')->map(fn ($v) => (int)$v)->all();
    }

    /**
     * @return array<int, int>
     */
    public function levelsOf(int $planetId, LifeformKind $kind): array
    {
        return $kind === LifeformKind::Building ? $this->buildingLevelsOf($planetId) : $this->technologyLevelsOf($planetId);
    }

    public function levelOf(int $planetId, LifeformKind $kind, int $objectId): int
    {
        return $this->levelsOf($planetId, $kind)[$objectId] ?? 0;
    }

    public function setLevel(int $planetId, LifeformKind $kind, int $objectId, int $level): void
    {
        $modele = $kind === LifeformKind::Building ? LifeformBuildingLevel::query() : LifeformTechnologyLevel::query();
        $modele->updateOrCreate(['planet_id' => $planetId, 'object_id' => $objectId], ['level' => max(0, $level)]);
        LifeformBonusCache::invalidate();
    }
}
