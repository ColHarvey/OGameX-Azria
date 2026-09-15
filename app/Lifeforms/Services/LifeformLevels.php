<?php

namespace OGame\Lifeforms\Services;

use OGame\Lifeforms\Bonuses\LifeformBonusCache;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Models\Lifeforms\LifeformBuildingLevel;
use OGame\Models\Lifeforms\LifeformQueue;
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
     * Les niveaux **tels qu ils etaient a un instant**, depuis les niveaux portes et la file des travaux.
     *
     * Le meme raisonnement que `CombatEntryCharacteristicsRegistry::researchLevelAt()` pour les recherches
     * ordinaires : la file dit ce que la colonne ne dit pas. Un travail **livre** dont l echeance suit
     * l instant n y etait pas — le niveau redescend sous sa cible ; un travail **non livre** dont l echeance
     * precede l instant y etait — le niveau monte a sa cible. Un niveau pose hors file (administration,
     * raccourci de banc) n a pas d historique : il est pris tel que la planete le porte.
     *
     * Une echeance **egale** a l instant compte comme precedente : une recherche achevee a la seconde d une
     * arrivee la precede, comme dans l ordre causal du combat.
     *
     * @return array<int, int> niveau par identifiant d objet, les zeros retires
     */
    public function levelsAt(int $planetId, LifeformKind $kind, int $at): array
    {
        $niveaux = $this->levelsOf($planetId, $kind);

        $lignes = LifeformQueue::query()
            ->where('planet_id', $planetId)
            ->where('kind', $kind->value)
            ->whereIn('status', ['done', 'running'])
            ->get(['object_id', 'target_level', 'time_end', 'status']);

        foreach ($lignes as $ligne) {
            $objet = (int)$ligne->object_id;
            $cible = (int)$ligne->target_level;
            $precede = (int)$ligne->time_end <= $at;
            $porte = $niveaux[$objet] ?? 0;

            if ($ligne->status === 'done' && !$precede) {
                $niveaux[$objet] = min($porte, $cible - 1);
            } elseif ($ligne->status === 'running' && $precede) {
                $niveaux[$objet] = max($porte, $cible);
            }
        }

        return array_filter($niveaux, static fn (int $niveau): bool => $niveau > 0);
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
