<?php

namespace OGame\Combat\Exceptions;

use RuntimeException;

/**
 * Le mecanisme a repondu quelque chose que sa propre question interdisait.
 *
 * ## Le cas type
 *
 * La matrice demande a l'ordre causal ce qu'il advient d'un missile pendant le ralliement. Le
 * reconciliateur repond `FoundingInitiator` : un missile aurait ouvert le combat. Ou il repond
 * `NotApplicable` : l'evenement ne concernerait pas le combat sur lequel on vient de l'interroger.
 *
 * Les deux sont des contradictions, pas des cas de jeu. Elles viennent d'un evenement confondu avec
 * un autre, d'un identifiant errone, ou d'une reponse fabriquee par un appelant plutot que rendue
 * par le mecanisme.
 *
 * ## Pourquoi lever plutot que choisir
 *
 * Choisir reviendrait a appliquer une regle que personne n'a prononcee — exactement ce que
 * `UnresolvedCombatDecision` existe pour empecher. Un missile annule « par prudence » detruit un
 * bien du joueur ; un missile applique « par prudence » modifie une photographie deja prise. Aucun
 * repli n'est neutre, donc aucun repli n'est pris.
 *
 * Sur un chemin vivant : annuler la transaction, journaliser en critique, ne rien muter.
 */
class ContradictoryDelegatedOutcome extends RuntimeException
{
    /**
     * @param string $mechanism Le mecanisme qui a repondu.
     * @param string $outcome La reponse rendue.
     * @param string $situation La situation, telle que `CombatSituation::describe()` la donne.
     */
    public function __construct(
        public readonly string $mechanism,
        public readonly string $outcome,
        public readonly string $situation,
    ) {
        parent::__construct(
            'Le mecanisme « ' . $mechanism . ' » a repondu « ' . $outcome . ' » pour la situation « '
            . $situation . ' », ce que sa propre question interdit. Aucun repli n est applique : les deux '
            . 'issues possibles modifient le jeu, et aucune n a ete decidee.'
        );
    }
}
