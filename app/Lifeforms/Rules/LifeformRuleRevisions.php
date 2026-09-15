<?php

namespace OGame\Lifeforms\Rules;

use OGame\Models\Lifeforms\LifeformRuleRevision;
use OGame\Services\SettingsService;

/**
 * Les revisions datees des vitesses (table `lifeform_rule_revisions`).
 *
 * ## Pourquoi dater les vitesses
 *
 * La demographie s integre par intervalles a taux constants. Si l administrateur passe l economie de
 * x1 a x2 pendant qu un compte est absent, ce compte doit etre avance jusqu au changement a x1, puis
 * a x2 — jamais a x2 sur toute son absence (dossier de Codex, section 12). Il faut donc savoir
 * **quand** chaque vitesse a change : c est cette table, ecrite par l administration a chaque
 * enregistrement qui change une des cinq valeurs.
 *
 * ## Avant la premiere revision
 *
 * Un serveur deja ouvert n a aucune ligne : les vitesses vivantes font foi. Des qu une ligne
 * existe, elle fait foi pour tout instant a partir de sa date d effet, et la plus ancienne pour tout
 * instant anterieur (le mieux que l on sache).
 */
final class LifeformRuleRevisions
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    /**
     * Les vitesses vivantes, telles que les reglages les donnent maintenant.
     */
    public function live(): LifeformSpeeds
    {
        return new LifeformSpeeds(
            (float)$this->settings->economySpeed(),
            (float)$this->settings->researchSpeed(),
            $this->settings->lifeformsBuildSpeedMultiplier(),
            $this->settings->lifeformsResearchSpeedMultiplier(),
            $this->settings->lifeformsDiscoverySpeedMultiplier(),
        );
    }

    /**
     * Ecrit une revision si les vitesses vivantes different de la derniere ecrite. Rend la ligne
     * ecrite, ou null si rien n a change.
     */
    public function recordIfChanged(int $effectiveAt, int|null $changedBy = null, string|null $note = null): LifeformRuleRevision|null
    {
        $vivantes = $this->live();
        $derniere = LifeformRuleRevision::query()->orderByDesc('effective_at')->orderByDesc('id')->first();
        if ($derniere !== null && self::of($derniere)->equals($vivantes)) {
            return null;
        }

        return LifeformRuleRevision::query()->create([
            'effective_at' => $effectiveAt,
            'economy_speed' => $vivantes->economy,
            'research_speed' => $vivantes->research,
            'build_multiplier' => $vivantes->buildMultiplier,
            'research_multiplier' => $vivantes->researchMultiplier,
            'discovery_multiplier' => $vivantes->discoveryMultiplier,
            'changed_by' => $changedBy,
            'note' => $note,
        ]);
    }

    /**
     * Les vitesses en vigueur a un instant.
     */
    public function at(int $instant): LifeformSpeeds
    {
        $enVigueur = LifeformRuleRevision::query()->where('effective_at', '<=', $instant)->latest('effective_at')->orderByDesc('id')->first();
        if ($enVigueur !== null) {
            return self::of($enVigueur);
        }
        $plusAncienne = LifeformRuleRevision::query()->orderBy('effective_at')->orderBy('id')->first();
        if ($plusAncienne !== null) {
            return self::of($plusAncienne);
        }

        return $this->live();
    }

    /**
     * Les instants, strictement entre deux bornes, ou une revision prend effet — la ou un
     * intervalle doit etre coupe.
     *
     * @return array<int, int>
     */
    public function changesBetween(int $from, int $to): array
    {
        if ($to <= $from) {
            return [];
        }
        $instants = LifeformRuleRevision::query()
            ->where('effective_at', '>', $from)
            ->where('effective_at', '<', $to)
            ->oldest('effective_at')
            ->pluck('effective_at')
            ->map(fn ($v) => (int)$v)
            ->all();

        return array_values(array_unique($instants));
    }

    private static function of(LifeformRuleRevision $revision): LifeformSpeeds
    {
        return new LifeformSpeeds(
            (float)$revision->economy_speed,
            (float)$revision->research_speed,
            (float)$revision->build_multiplier,
            (float)$revision->research_multiplier,
            (float)$revision->discovery_multiplier,
        );
    }
}
