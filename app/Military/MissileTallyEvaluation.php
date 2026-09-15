<?php

namespace OGame\Military;

/**
 * Evalue une frappe de missiles : pure, versionnee, entiere ou en attente.
 *
 * **La regle** : une interception est un « detruit » pour le defenseur seul (la valeur des missiles abattus) ; une
 * frappe est un « detruit » pour l attaquant et un « perdu » pour le defenseur (la valeur des defenses detruites).
 * Les missiles tires et les antimissiles consommes ne comptent jamais : consommation normale.
 *
 * Un PNJ est calcule, jamais credite. Une unite hors catalogue, un participant classe sans compte, deux clefs qui se
 * confondent ou un prix absent laissent la frappe entiere en attente, avec sa raison.
 */
final class MissileTallyEvaluation
{
    public const string UNKNOWN_UNIT_FAMILY = BattleTallyEvaluation::UNKNOWN_UNIT_FAMILY;

    public const string PARTICIPANT_WITHOUT_OWNER = BattleTallyEvaluation::PARTICIPANT_WITHOUT_OWNER;

    public const string INCOHERENT_STRIKE = 'incoherent_strike';

    public function evaluate(MissileTallyFacts $facts, string $version): BattleTallyOutcome
    {
        foreach (['attaquant' => $facts->attacker, 'defenseur' => $facts->defender] as $role => $participant) {
            if ($participant['owner'] === null && !$participant['npc']) {
                return BattleTallyOutcome::pending(self::PARTICIPANT_WITHOUT_OWNER, 'le ' . $role . ' ' . $participant['key'] . ' n a pas de proprietaire');
            }
        }

        if ($facts->attacker['key'] === $facts->defender['key']) {
            return BattleTallyOutcome::pending(self::INCOHERENT_STRIKE, 'l attaquant et le defenseur portent la meme clef ' . $facts->attacker['key']);
        }

        $unites = $facts->destroyed;

        if ($facts->intercepted > 0) {
            $unites[MissileTallyFacts::MISSILE] = $facts->intercepted;
        }

        foreach (array_keys($unites) as $nom) {
            if (MilitaryValue::weightOf($nom, $version) === null) {
                return BattleTallyOutcome::pending(self::UNKNOWN_UNIT_FAMILY, 'l unite ' . $nom . ' n appartient a aucune famille connue de la version ' . $version);
            }

            if (!array_key_exists($nom, $facts->prices)) {
                return BattleTallyOutcome::pending(self::INCOHERENT_STRIKE, 'aucun prix garde pour l unite ' . $nom);
            }
        }

        $detruit = $this->valueOf($facts->destroyed, $facts->prices, $version);
        $intercepte = $facts->intercepted > 0
            ? $this->valueOf([MissileTallyFacts::MISSILE => $facts->intercepted], $facts->prices, $version)
            : 0;

        return BattleTallyOutcome::computed([
            $facts->attacker['key'] => ['owner' => $facts->attacker['owner'], 'npc' => $facts->attacker['npc'], 'destroyed' => $detruit, 'lost' => 0],
            $facts->defender['key'] => ['owner' => $facts->defender['owner'], 'npc' => $facts->defender['npc'], 'destroyed' => $intercepte, 'lost' => $detruit],
        ]);
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
