<?php

namespace OGame\Combat\Presentation;

use OGame\Models\CombatInstance;
use OGame\Models\CombatParticipant;
use OGame\Models\CombatPresentationEvent;

/**
 * Lit le fil d'un combat pour un joueur, a un instant : seulement le passe, seulement le sien.
 *
 * ## Deux filtres, tous deux cote serveur
 *
 * **Le passe seulement.** Un evenement dont `visible_at` n'est pas atteint n'est pas rendu — il
 * n'est pas rendu puis cache par l'interface, il ne quitte pas la base. Livrer le resultat cache au
 * navigateur en comptant sur lui pour le masquer reviendrait a l'annoncer.
 *
 * **Les siens seulement.** Un joueur voit les pertes de ses propres flottes ; le proprietaire de la
 * cible voit celles de sa garnison, inscrite sous la clef du corps. Les pertes des allies et de
 * l'adversaire restent cachees jusqu'au rapport final. L'appartenance vient des inscriptions au
 * combat — la clef de participant y est liee a son joueur — jamais des modeles vivants.
 *
 * ## Incremental
 *
 * `$afterSequence` reprend apres le dernier rang deja rendu : un rechargement, un travailleur en
 * retard ou une double livraison ne dupliquent ni ne reordonnent un evenement, puisque le rang est
 * stable et que l'ordre de lecture est le sien.
 */
final class CombatPresentationTimelineReader
{
    /**
     * Les evenements devenus visibles pour ce joueur, dans l'ordre, apres le rang donne.
     *
     * @return array<int, PresentationEvent>
     */
    public function visibleTo(CombatInstance $combat, int $playerId, int $now, int $afterSequence = 0): array
    {
        $version = $combat->presentation_version;

        if (!is_string($version) || $version === '') {
            return [];
        }

        $siens = CombatParticipant::query()
            ->where('combat_instance_id', $combat->id)
            ->where('player_id', $playerId)
            ->pluck('participant_key')
            ->all();

        // **Seules les inscriptions decident.** La garnison y figure sous la clef du corps, avec le
        // joueur photographie a la cloture : un corps supprime, restaure ou reattribue ensuite ne
        // retire l'acces a personne et ne le donne a personne.
        if ($siens === []) {
            return [];
        }

        $evenements = [];

        $lignes = CombatPresentationEvent::query()
            ->where('combat_instance_id', $combat->id)
            ->where('version', $version)
            ->where('visible_at', '<=', $now)
            ->where('sequence', '>', $afterSequence)
            ->whereIn('participant_key', $siens)
            ->orderBy('sequence')
            ->get();

        foreach ($lignes as $ligne) {
            $evenements[] = new PresentationEvent(
                (int)$ligne->sequence,
                (int)$ligne->visible_at,
                (string)$ligne->participant_key,
                (string)$ligne->side,
                (string)$ligne->unit,
                (int)$ligne->amount
            );
        }

        return $evenements;
    }
}
