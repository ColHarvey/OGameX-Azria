<?php

namespace OGame\Combat\Exceptions;

use RuntimeException;

/**
 * Deux creances de restitution se contredisent sur la meme mission.
 *
 * L'annulation d'un missile est unique : elle nomme un proprietaire et une quantite. Une seconde
 * inscription qui en nommerait d'autres ne peut venir que d'un defaut — deux chemins d'annulation,
 * une quantite relue apres coup — et garder la premiere le cacherait. Le refus le montre.
 */
final class ContradictoryRefundClaim extends RuntimeException
{
    public function __construct(
        public readonly int $fleetMissionId,
        public readonly int $recordedOwnerId,
        public readonly int $recordedMissiles,
        public readonly int $offeredOwnerId,
        public readonly int $offeredMissiles,
    ) {
        parent::__construct(
            'La creance de restitution de la mission ' . $fleetMissionId . ' porte deja ' . $recordedMissiles
            . ' missiles dus au joueur ' . $recordedOwnerId . ' : on en propose ' . $offeredMissiles
            . ' au joueur ' . $offeredOwnerId . '.'
        );
    }
}
