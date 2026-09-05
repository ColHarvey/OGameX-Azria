<?php

namespace OGame\Combat\Services;

/**
 * Ce qui est du a un joueur apres l'annulation d'un missile : une creance, nommee.
 *
 * La ligne brute de la base n'a pas de type : chaque lecteur devinerait ses colonnes, et un renommage
 * ne se verrait qu'en jeu. Les champs sont donc nommes ici, une fois, et c'est cette forme que la
 * commande d'exploitation et le reglement manipulent.
 */
final readonly class PendingMissileRefund
{
    public function __construct(
        public int $id,
        public int $fleetMissionId,
        public int $combatInstanceId,
        public int $ownerId,
        public int $missiles,
        public string $reason,
        public int $claimedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int)($row['id'] ?? 0),
            (int)($row['fleet_mission_id'] ?? 0),
            (int)($row['combat_instance_id'] ?? 0),
            (int)($row['owner_id'] ?? 0),
            (int)($row['missiles'] ?? 0),
            (string)($row['reason'] ?? ''),
            (int)($row['claimed_at'] ?? 0),
        );
    }
}
