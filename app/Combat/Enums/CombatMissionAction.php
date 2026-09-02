<?php

namespace OGame\Combat\Enums;

/**
 * Ce que le combat persistant fait d'une mission, a chacun des quatre moments.
 *
 * Les noms sont ceux du domaine, pas ceux de l'affichage : ils disent ce qui arrive a la flotte,
 * et la couche de presentation traduira. Aucune action ne porte de texte joueur — c'est le role
 * du `CombatReasonCode` qui l'accompagne.
 */
enum CombatMissionAction: string
{
    /**
     * Le lancement est refuse. La flotte ne part pas, rien n'est preleve.
     */
    case RefuseLaunch = 'refuse_launch';

    /**
     * La flotte rejoint le camp attaquant du ralliement en cours.
     */
    case JoinAttack = 'join_attack';

    /**
     * La flotte rejoint le camp defenseur du ralliement en cours.
     */
    case JoinDefence = 'join_defence';

    /**
     * La flotte fait demi-tour vers son origine, par la mecanique normale de rappel.
     *
     * Reserve aux acteurs qui **ont** une origine ou revenir. Voir `CancelWithoutImpact` pour les
     * autres.
     */
    case ReturnToOrigin = 'return_to_origin';

    /**
     * La flotte se pose, mais hors de la photographie et sans pouvoir repartir avant la fin.
     *
     * Le cas des retours et deploiements personnels arrives apres la fermeture : ce sont les
     * vaisseaux du proprietaire qui rentrent chez lui. Les renvoyer serait absurde.
     */
    case LandOutsideSnapshot = 'land_outside_snapshot';

    /**
     * L'effet est reporte apres la resolution du combat.
     *
     * Un missile prevu apres la fermeture ne peut ni modifier une defense deja photographiee ni
     * disparaitre sans raison : il attend que la bataille soit reglee, puis frappe ce qui reste.
     */
    case DeferImpact = 'defer_impact';

    /**
     * L'evenement attend la fin de la resolution avant d'etre rejoue.
     *
     * Distinct de `DeferImpact` : il ne s'agit pas de reporter un effet de jeu, mais de ne pas
     * toucher une cible pendant qu'un processus applique un resultat. L'evenement sera repris tel
     * quel une fois la transaction close.
     */
    case DeferUntilResolved = 'defer_until_resolved';

    /**
     * La flotte disparait sans effet, avec une trace dans le journal.
     *
     * Pour les acteurs pilotes par le serveur, qui n'ont pas toujours de planete de retour. Leur
     * inventer une origine reviendrait a mentir dans les donnees pour satisfaire une regle qui ne
     * les concerne pas ; les faire disparaitre en silence rendrait tout incident invisible.
     */
    case CancelWithoutImpact = 'cancel_without_impact';

    /**
     * Le combat n'a rien a dire : la mission suit son cours ordinaire.
     */
    case AllowNormally = 'allow_normally';
}
