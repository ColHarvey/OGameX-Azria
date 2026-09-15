<?php

namespace OGame\Military;

/**
 * Evalue une destruction de lune : pure, versionnee, entiere ou en attente.
 *
 * **Les regles** : les Etoiles perdues dans la catastrophe sont des « perdus » pour leur proprietaire, sans destructeur
 * credite (decision du 14 septembre 2026) ; les unites que la lune emportait sont des « perdus » pour son proprietaire
 * et des « detruits » pour l attaquant dont la tentative l a detruite, sans recompter la bataille prealable
 * (recommandation Codex, appliquee).
 *
 * Un PNJ est calcule, jamais credite. Une unite hors catalogue, un participant classe sans compte, deux clefs qui se
 * confondent, deux tentatives qui detruiraient la meme lune ou un prix absent laissent le fait entier en attente.
 */
final class MoonDestructionTallyEvaluation
{
    public const string UNKNOWN_UNIT_FAMILY = BattleTallyEvaluation::UNKNOWN_UNIT_FAMILY;

    public const string PARTICIPANT_WITHOUT_OWNER = BattleTallyEvaluation::PARTICIPANT_WITHOUT_OWNER;

    public const string INCOHERENT_DESTRUCTION = 'incoherent_destruction';

    public function evaluate(MoonDestructionTallyFacts $facts, string $version): BattleTallyOutcome
    {
        $participants = [$facts->moon['key'] => $facts->moon];
        $destructeurs = 0;

        foreach ($facts->attempts as $tentative) {
            if (isset($participants[$tentative['key']])) {
                return BattleTallyOutcome::pending(self::INCOHERENT_DESTRUCTION, 'deux participants portent la clef ' . $tentative['key']);
            }

            $participants[$tentative['key']] = $tentative;
            $destructeurs += $tentative['destroyed_the_moon'] ? 1 : 0;
        }

        foreach ($participants as $clef => $participant) {
            if ($participant['owner'] === null && !$participant['npc']) {
                return BattleTallyOutcome::pending(self::PARTICIPANT_WITHOUT_OWNER, 'le participant ' . $clef . ' n a pas de proprietaire');
            }
        }

        if ($destructeurs > 1) {
            return BattleTallyOutcome::pending(self::INCOHERENT_DESTRUCTION, 'plusieurs tentatives auraient detruit la meme lune');
        }

        if ($destructeurs === 0 && $facts->destroyedWithMoon !== []) {
            return BattleTallyOutcome::pending(self::INCOHERENT_DESTRUCTION, 'des unites emportees par une lune que personne n a detruite');
        }

        $unites = $facts->destroyedWithMoon;

        foreach ($facts->attempts as $tentative) {
            if ($tentative['deathstars_lost'] > 0) {
                $unites[MoonDestructionTallyFacts::DEATHSTAR] = 1;
            }
        }

        foreach (array_keys($unites) as $nom) {
            if (MilitaryValue::weightOf($nom, $version) === null) {
                return BattleTallyOutcome::pending(self::UNKNOWN_UNIT_FAMILY, 'l unite ' . $nom . ' n appartient a aucune famille connue de la version ' . $version);
            }

            if (!array_key_exists($nom, $facts->prices)) {
                return BattleTallyOutcome::pending(self::INCOHERENT_DESTRUCTION, 'aucun prix garde pour l unite ' . $nom);
            }
        }

        $emporte = $this->valueOf($facts->destroyedWithMoon, $facts->prices, $version);
        $calcule = [
            $facts->moon['key'] => ['owner' => $facts->moon['owner'], 'npc' => $facts->moon['npc'], 'destroyed' => 0, 'lost' => $emporte],
        ];

        foreach ($facts->attempts as $tentative) {
            $calcule[$tentative['key']] = [
                'owner' => $tentative['owner'],
                'npc' => $tentative['npc'],
                'destroyed' => $tentative['destroyed_the_moon'] ? $emporte : 0,
                'lost' => $this->valueOf([MoonDestructionTallyFacts::DEATHSTAR => $tentative['deathstars_lost']], $facts->prices, $version),
            ];
        }

        return BattleTallyOutcome::computed($calcule);
    }

    /**
     * @param array<string, int> $unites
     * @param array<string, int> $prix
     */
    private function valueOf(array $unites, array $prix, string $version): int
    {
        $valeur = 0;

        foreach ($unites as $nom => $nombre) {
            if ($nombre <= 0) {
                continue;
            }

            $valeur += $prix[$nom] * $nombre * (int)MilitaryValue::weightOf($nom, $version);
        }

        return $valeur;
    }
}
