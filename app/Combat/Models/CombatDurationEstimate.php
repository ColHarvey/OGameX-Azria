<?php

namespace OGame\Combat\Models;

/**
 * La duree calculee d'un combat, et tout ce qui a servi a l'obtenir.
 *
 * Le coefficient de rythme et le minimum sont conserves dans le resultat : c'est ce que
 * « configurable et visible » veut dire. Une duree qu'on ne peut pas expliquer ne se calibre
 * pas.
 */
class CombatDurationEstimate
{
    /**
     * @param int $seconds Duree finale exploitable, en secondes.
     * @param float $rawSeconds Duree brute, jamais rabotee. Peut depasser ce qu'un entier contient.
     * @param bool $implausible Vrai si la duree brute depasse le seuil d'alerte technique.
     * @param float $totalWork Somme du travail de tous les rounds.
     * @param float $rate Coefficient de rythme applique : travail par seconde.
     * @param int $minimumSeconds Duree minimale configuree.
     * @param bool $minimumApplied Vrai si le calcul brut etait plus court que ce minimum.
     * @param bool $instant Vrai pour une bataille sans round : rien a faire durer.
     * @param array<int, CombatRoundWork> $rounds Detail par round, dans l'ordre.
     */
    public function __construct(
        public readonly int $seconds,
        public readonly float $rawSeconds,
        public readonly bool $implausible,
        public readonly float $totalWork,
        public readonly float $rate,
        public readonly int $minimumSeconds,
        public readonly bool $minimumApplied,
        public readonly bool $instant,
        public readonly array $rounds,
    ) {
    }
}
