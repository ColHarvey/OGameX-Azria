<?php

namespace OGame\Combat\Enums;

/**
 * Ce qu'une demande de relevement a reellement fait a la reservation.
 *
 * **Une borne plus basse n'est pas une faute.** Le taux pondere par le fret peut baisser pendant
 * le ralliement : une immense flotte sans Decouvreur qui rejoint une attaque ouverte par un
 * Decouvreur ramene le taux de 75 % vers 50 %. La nouvelle borne est alors inferieure a celle
 * deja retenue, et c'est normal.
 *
 * Elle est simplement **ignoree** : la reservation garde le maximum qu'elle a connu, et ne libere
 * jamais rien avant le rapport. Laisser le solde disponible remonter reviendrait a annoncer au
 * defenseur que la composition adverse a change — un oracle de plus.
 *
 * Le resultat est rendu pour le journal d'audit, pas pour etre teste par l'appelant : il n'a rien
 * a decider selon la reponse.
 */
enum ReservationRaise: string
{
    /**
     * La borne a monte sur au moins une ressource.
     */
    case Raised = 'raised';

    /**
     * La borne proposee etait couverte : rien n'a bouge, et rien n'a ete libere.
     */
    case Unchanged = 'unchanged';
}
