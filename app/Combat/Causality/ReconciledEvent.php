<?php

namespace OGame\Combat\Causality;

/**
 * Un evenement et ce que la reconciliation en a decide.
 *
 * La raison accompagne toujours l'issue : un audit qui lirait « hors photographie » sans savoir si
 * c'est l'engagement ou l'effet qui etait trop tard ne pourrait pas verifier la regle.
 */
final readonly class ReconciledEvent
{
    /**
     * @param CausalEvent $event L'evenement, tel qu'il a ete relu.
     * @param CausalAdmission $admission Ce qu'il advient de lui.
     * @param string $because Pourquoi, en une phrase destinee a l'audit.
     */
    public function __construct(
        public CausalEvent $event,
        public CausalAdmission $admission,
        public string $because,
    ) {
    }
}
