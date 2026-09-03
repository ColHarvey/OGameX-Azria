<?php

namespace OGame\Combat\Services;

/**
 * Ce que l'engagement a fixe : la duree du combat et son echeance.
 */
final readonly class CombatEngagement
{
    /**
     * @param int $seconds La duree retenue, plancher applique.
     * @param int $endsAt L'echeance, comptee depuis l'instant de cloture.
     * @param bool $implausible Vrai si la duree brute depasse le seuil d'alerte technique.
     * @param int $rounds Le nombre de rounds de la bataille.
     */
    public function __construct(
        public int $seconds,
        public int $endsAt,
        public bool $implausible,
        public int $rounds,
    ) {
    }
}
