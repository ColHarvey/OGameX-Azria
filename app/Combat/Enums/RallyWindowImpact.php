<?php

namespace OGame\Combat\Enums;

/**
 * Si un participant retient la fenetre de ralliement ouverte jusqu'a son arrivee.
 *
 * **Une enumeration plutot qu'un booleen, et porte par la seule selection collective.** Un
 * booleen libre se serait retrouve sur chaque resultat, et rien n'aurait empeche de le poser sur
 * une candidate refusee — qui aurait alors garde la cible verrouillee sans jamais se battre.
 *
 * Etre admissible ne suffit pas : il faut avoir survecu a la selection et aux limites de son
 * camp. C'est pourquoi cette valeur ne se construit que sur un `SnapshotDecision::include()`
 * portant une flotte combattante.
 */
enum RallyWindowImpact: string
{
    /**
     * La fenetre reste ouverte jusqu'a l'arrivee de ce participant.
     */
    case Extend = 'extend';

    /**
     * Ce participant ne fait pas attendre la bataille.
     */
    case None = 'none';
}
