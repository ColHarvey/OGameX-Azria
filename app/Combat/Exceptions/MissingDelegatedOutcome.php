<?php

namespace OGame\Combat\Exceptions;

use RuntimeException;

/**
 * La matrice a delegue, et personne n'a repondu.
 *
 * ## Pourquoi c'est une panne, et pas un defaut a combler
 *
 * Trois des quatre categories de cases ouvertes sont des **directives** : la matrice a decide, et
 * ce qu'elle a decide est de deleguer a un mecanisme nomme. La delegation ne vaut comme decision
 * que si son consommateur existe et traite exhaustivement ses resultats ; sans cela, elle n'est
 * qu'un trou sous un autre nom.
 *
 * Le consommateur exige donc la reponse au moment ou il en a besoin. Une valeur par defaut
 * — « admis », « hors photographie » — ferait tourner le jeu sous une regle que personne n'a
 * prononcee, et le defaut resterait invisible jusqu'a ce qu'un joueur le paie.
 *
 * Sur un chemin vivant, cette exception doit annuler la transaction et etre journalisee en
 * critique, comme `UnresolvedCombatDecision`.
 */
class MissingDelegatedOutcome extends RuntimeException
{
    /**
     * @param string $mechanism Le mecanisme dont la reponse manque.
     * @param string $situation La situation, telle que `CombatSituation::describe()` la donne.
     */
    public function __construct(
        public readonly string $mechanism,
        public readonly string $situation,
    ) {
        parent::__construct(
            'La matrice a delegue a « ' . $mechanism . ' » pour la situation « ' . $situation . ' », et aucune '
            . 'reponse n a ete fournie. Aucune valeur par defaut n est appliquee : la reponse doit venir du '
            . 'mecanisme, sous verrou, avant que l arrivee soit resolue.'
        );
    }
}
