<?php

namespace OGame\Combat\Decisions;

use OGame\Combat\Enums\CombatReasonCode;

/**
 * Ce que toute decision de combat sait dire d'elle-meme.
 *
 * Les quatre moments — lancement, rappel, arrivee, selection de la photographie — ne rendent
 * **pas** le meme type de reponse. Une classe unique permettrait des combinaisons qui n'ont aucun
 * sens : refuser un lancement tout en entrant dans la photographie, reporter un rappel apres la
 * resolution, renvoyer une flotte a une origine inexistante. Chaque moment a donc son contrat, et
 * seules des fabriques nommees peuvent en construire les valeurs.
 *
 * Ce qu'ils partagent tient en trois questions : la raison, l'etat de decision, et la question
 * restee ouverte le cas echeant.
 */
interface CombatDecision
{
    /**
     * Pourquoi cette decision, en un code stable et non traduit.
     */
    public function reason(): CombatReasonCode;

    /**
     * Si la regle de cette case est arretee.
     *
     * Faux ne signifie pas « rien a faire » : cela signifie **qu'aucun comportement n'existe** et
     * qu'appeler l'action leve `UnresolvedCombatDecision`.
     */
    public function isResolved(): bool;

    /**
     * La question restee sans reponse, ou null si la case est tranchee.
     */
    public function openQuestion(): string|null;
}
