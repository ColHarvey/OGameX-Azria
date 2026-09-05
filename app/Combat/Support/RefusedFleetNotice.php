<?php

namespace OGame\Combat\Support;

use OGame\Combat\Enums\CombatOutboxKind;
use OGame\Combat\Enums\CombatReasonCode;
use OGame\Combat\Exceptions\ContradictoryRefusalNotice;
use OGame\Models\CombatInstance;
use OGame\Models\CombatOutboxMessage;
use OGame\Models\FleetMission;

/**
 * L'avis de refus, ecrit une fois et derive toujours de la meme decision.
 *
 * ## Deux ecrivains, un contenu
 *
 * La fermeture ecrit l'avis d'une candidate refusee ; le renvoi l'ecrit a son tour — pour une
 * flotte jamais jugee, il est le premier. Chacun composait son propre contenu, et un
 * `firstOrCreate()` faisait gagner la premiere ligne en silence. Ici les deux passent par la meme
 * fabrique, et une ligne existante qui differe de ce que la decision donne arrete la transaction.
 *
 * ## Ce qui est canonique, et ce qui ne l'est pas
 *
 * La raison, le corps vise, ses coordonnees et l'instant ou l'avis devient lisible decoulent de la
 * decision : ce sont les faits, et ils sont compares. La taille du groupe — « ta vague de cinq est
 * repartie entiere » — n'est connue que de la fermeture, qui seule voit le groupe ; c'est une
 * facon de raconter, pas un fait du mouvement, et elle n'entre pas dans la comparaison.
 *
 * L'avis devient lisible a l'instant ou la flotte repart : une notification ne doit pas se lire
 * avant que sa decision existe, ni raconter un depart qui n'a pas encore eu lieu.
 */
final class RefusedFleetNotice
{
    /**
     * Ecrit l'avis, ou verifie que celui qui existe raconte la meme decision.
     *
     * @param int $decidedAt L'instant canonique de la decision : la fermeture, ou l'arrivee physique.
     * @param int|null $groupFleets La taille du groupe refuse, quand l'ecrivain la connait.
     *
     * @throws ContradictoryRefusalNotice Si un avis existe deja et ne dit pas la meme chose.
     */
    public static function write(
        CombatInstance $combat,
        FleetMission $mission,
        CombatReasonCode $reason,
        int $decidedAt,
        int|null $groupFleets = null,
    ): void {
        $lisibleDes = ReturnOrder::departureInstant($decidedAt, $mission);

        $canonique = [
            // **Le destinataire est fige ici, avec le fait.** Un corps ou une flotte peut changer de
            // mains entre la decision et la livraison ; l'avis appartient a qui l'a subie.
            'recipient_id' => (int)$mission->user_id,
            'reason' => $reason->value,
            'target_body_id' => (int)$combat->target_planet_id,
            'galaxy' => (int)$combat->galaxy,
            'system' => (int)$combat->system,
            'position' => (int)$combat->position,
        ];

        $avis = CombatOutboxMessage::query()->firstOrCreate(
            [
                'combat_instance_id' => $combat->id,
                'participant_key' => CombatParticipantKey::forFleet($mission->id),
                'kind' => CombatOutboxKind::RallyRefused->value,
            ],
            [
                'payload' => $canonique + ($groupFleets === null ? [] : ['group_fleets' => $groupFleets]),
                'available_at' => $lisibleDes,
            ]
        );

        if ($avis->wasRecentlyCreated) {
            return;
        }

        $inscrit = $avis->payload ?? [];

        foreach ($canonique as $champ => $valeur) {
            if ((string)($inscrit[$champ] ?? '') !== (string)$valeur) {
                throw new ContradictoryRefusalNotice($mission->id, $champ, (string)($inscrit[$champ] ?? ''), (string)$valeur);
            }
        }

        if ((int)$avis->available_at !== $lisibleDes) {
            throw new ContradictoryRefusalNotice($mission->id, 'available_at', (string)$avis->available_at, (string)$lisibleDes);
        }

        // **La taille du groupe : comparee quand l'ecrivain la connait, preservee quand il ne la
        // connait pas.** Ce n'est pas un fait du mouvement, mais c'est un contenu que le joueur lit :
        // une fermeture rejouee avec une autre taille ne raconte plus le meme refus, et un
        // `firstOrCreate()` n'a pas a l'arbitrer. Le consommateur, lui, ne la connait pas — il ne
        // pretend pas la revalider, et la laisse telle que la fermeture l'a ecrite.
        if ($groupFleets !== null && (string)($inscrit['group_fleets'] ?? '') !== (string)$groupFleets) {
            throw new ContradictoryRefusalNotice($mission->id, 'group_fleets', (string)($inscrit['group_fleets'] ?? ''), (string)$groupFleets);
        }
    }
}
