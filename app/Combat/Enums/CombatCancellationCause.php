<?php

namespace OGame\Combat\Enums;

/**
 * Les seules raisons pour lesquelles un combat peut etre annule.
 *
 * L'etat `Cancelled` n'est accessible que depuis `Pending` — la fenetre entre l'arrivee de la
 * flotte et le premier round. Cette fenetre est courte, mais elle existe, et **aucun joueur ne
 * doit pouvoir s'y engouffrer** : le resultat y est deja calcule, donc deja connaissable par
 * qui saurait le lire. Un rappel accepte a ce moment-la reviendrait a effacer une bataille
 * perdue d'avance.
 *
 * D'ou cette enumeration : l'annulation exige une cause, et **aucune cause n'est a la main d'un
 * joueur**. Ce n'est pas un commentaire qu'on peut oublier de lire, c'est une valeur qu'il faut
 * fournir — et il n'en existe aucune qui convienne a un rappel.
 *
 * Le rappel reste possible **avant l'arrivee**, quand aucun combat n'existe encore. C'est la
 * regle du jeu, et elle n'est pas affaiblie ici : elle est simplement bornee au moment ou elle
 * a du sens.
 */
enum CombatCancellationCause: string
{
    /**
     * Le corps celeste vise n'existe plus : detruit, abandonne, ou libere entre-temps.
     */
    case TargetDisappeared = 'target_disappeared';

    /**
     * Le compte attaquant a disparu : suppression, ou bannissement avec effacement.
     */
    case AttackerRemoved = 'attacker_removed';

    /**
     * Un administrateur annule explicitement, en connaissance de cause.
     */
    case AdministrativeDecision = 'administrative_decision';

    /**
     * Le combat n'a pas pu demarrer : donnees incoherentes au moment de la photo.
     *
     * Vaut mieux qu'un combat qui tourne sur un etat qu'on ne sait pas decrire.
     */
    case InconsistentSnapshot = 'inconsistent_snapshot';

    /**
     * Get whether a player can bring this cause about by their own action.
     *
     * Aucune ne le peut, et c'est le point. Cette methode existe pour que la regle soit
     * verifiable par un test plutot que confiee a la vigilance de la prochaine personne.
     */
    public function isPlayerInitiated(): bool
    {
        return false;
    }
}
