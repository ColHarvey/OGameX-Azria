<?php

namespace OGame\Combat\Decisions;

use RuntimeException;

/**
 * Une case de la matrice a ete atteinte alors que sa regle n'a jamais ete arretee.
 *
 * **C'est une panne volontaire, et elle vaut mieux que l'alternative.** Une premiere version
 * faisait rendre « autoriser normalement » aux cases indecises. Cela paraissait inoffensif : en
 * realite, une mission oubliee se serait executee **normalement**, modifiant le jeu sous une
 * regle que personne n'avait approuvee. C'est l'inverse exact du garde-fou recherche.
 *
 * Une case non tranchee n'a donc aucun comportement executable. Si elle atteint un orchestrateur,
 * il doit lever cette exception **avant toute mutation**, annuler la transaction et journaliser
 * en critique la case manquante.
 *
 * Un test exigera zero decision indecise avant que la fonctionnalite ne soit activee : tant
 * qu'il en reste, le systeme n'est pas pret, et ce n'est pas negociable au cas par cas.
 */
class UnresolvedCombatDecision extends RuntimeException
{
    /**
     * @param string $question La case manquante, formulee comme une question.
     */
    public function __construct(public readonly string $question)
    {
        parent::__construct(
            'Regle de combat non arretee : ' . $question . '. '
            . 'Aucune valeur par defaut n est appliquee — la decision doit etre prise et ecrite.'
        );
    }
}
