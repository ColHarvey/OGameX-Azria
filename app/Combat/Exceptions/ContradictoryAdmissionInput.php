<?php

namespace OGame\Combat\Exceptions;

use RuntimeException;

/**
 * Un selecteur d'admission a recu une candidate que la matrice ne lui delegue jamais.
 *
 * ## Pourquoi ce n'est pas un refus
 *
 * La matrice ne delegue au selecteur attaquant que les allers `Attack`, `AcsAttack` et
 * `MoonDestruction` ; au selecteur defensif, que les allers `AcsDefend`. Un retour, ou un genre qui
 * n'ouvre aucun combat, ne peut pas arriver la sur un chemin sain.
 *
 * Le refuser avec `NoCombatEffect` serait pourtant tentant : la phrase est vraie, le joueur lirait
 * quelque chose de sense, et sa flotte repartirait. **C'est exactement ce qui rend ce refus
 * dangereux** — un defaut d'integration disparaitrait derriere un message anodin, et personne ne
 * saurait que la matrice et le selecteur ne s'accordent plus.
 *
 * Une entree contradictoire s'arrete donc et se journalise, comme `UnresolvedCombatDecision` : sur
 * un chemin vivant, annuler la transaction et alerter, jamais continuer.
 */
class ContradictoryAdmissionInput extends RuntimeException
{
    /**
     * @param string $selector Le selecteur qui a recu l'entree.
     * @param string $shape La forme recue — genre et sens de vol.
     * @param string $subject Ce qui l'a apportee, pour retrouver la ligne.
     */
    public function __construct(
        public readonly string $selector,
        public readonly string $shape,
        public readonly string $subject,
    ) {
        parent::__construct(
            'Le « ' . $selector . ' » a recu « ' . $shape . ' » (' . $subject . '), une forme que la '
            . 'matrice ne lui delegue jamais. Ce n est pas un refus a montrer au joueur : la matrice et '
            . 'le selecteur ne s accordent plus, et le masquer sous une raison anodine rendrait le '
            . 'defaut invisible.'
        );
    }
}
