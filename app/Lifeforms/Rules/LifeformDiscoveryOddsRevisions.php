<?php

namespace OGame\Lifeforms\Rules;

use OGame\Lifeforms\Discovery\LifeformDiscoveryOdds;
use OGame\Models\Lifeforms\LifeformDiscoveryOddsRevision;
use OGame\Services\SettingsService;

/**
 * Les revisions datees des cotes d artefacts (table `lifeform_discovery_odds_revisions`, journal §159).
 *
 * Le patron de `LifeformRuleRevisions` : l administration ecrit une ligne a chaque enregistrement qui
 * change une cote, datee et signee. Personne ne relit ces lignes pour decider : un vol porte sa propre
 * photographie (`lifeform_discoveries.odds`). Elles disent qui a change quoi, et quand.
 */
final class LifeformDiscoveryOddsRevisions
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    /**
     * Ecrit une revision si les cotes vivantes different de la derniere ecrite — ou si aucune n a encore
     * ete ecrite et que les cotes vivantes ne sont pas celles de depart. Rend la ligne, ou null.
     */
    public function recordIfChanged(int $effectiveAt, int|null $changedBy = null, string|null $note = null): LifeformDiscoveryOddsRevision|null
    {
        $vivantes = $this->settings->lifeformDiscoveryOdds();
        $derniere = LifeformDiscoveryOddsRevision::query()->orderByDesc('effective_at')->orderByDesc('id')->first();
        $reference = $derniere === null ? LifeformDiscoveryOdds::defaults() : self::of($derniere);
        if ($reference->equals($vivantes)) {
            return null;
        }

        return LifeformDiscoveryOddsRevision::query()->create($vivantes->toStorage() + [
            'effective_at' => $effectiveAt,
            'changed_by' => $changedBy,
            'note' => $note,
        ]);
    }

    private static function of(LifeformDiscoveryOddsRevision $revision): LifeformDiscoveryOdds
    {
        return new LifeformDiscoveryOdds(
            (int)$revision->artifact_chance,
            (int)$revision->small,
            (int)$revision->medium,
            (int)$revision->large,
            (int)$revision->medium_chance,
            (int)$revision->large_chance,
        );
    }
}
