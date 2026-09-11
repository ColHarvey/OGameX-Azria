<?php

namespace OGame\Combat\Support;

/**
 * Ce qu'une premiere passe pressent pour une flotte, et les lignes qu'il faut tenir pour le confirmer.
 *
 * Le plan seul ne suffit pas a decider quoi verrouiller : il nomme le gagnant, pas les faits qui
 * l'ont fait gagner. Les deux voyagent donc ensemble jusqu'a la seconde passe, qui les recompare
 * l'un et l'autre.
 */
final readonly class ForeseenReturn
{
    /**
     * @param array<int, int> $decidingBodyIds Les corps dont l'etat fait pencher le choix, par identifiant croissant.
     * @param array<int, int> $decidingPatrolIds La patrouille qui attend la flotte, si elle en a une : zero ou un.
     */
    public function __construct(
        public ReturnPlan $plan,
        public array $decidingBodyIds,
        public array $decidingPatrolIds = [],
    ) {
    }
}
