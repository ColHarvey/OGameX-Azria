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

    /**
     * Le camp attaquant decidera : la flotte y est admise, ou elle repart.
     *
     * **Une decision, pas un trou.** L'admission ne se lit pas dans une situation : elle depend de
     * faits persistes et collectifs — la liste figee a l'ouverture, l'alliance de l'initiateur, les
     * budgets du camp. La matrice ne peut que nommer le mecanisme qui tranche, et exiger qu'il le
     * fasse sous verrou : deux workers ne doivent jamais prendre ensemble la derniere place.
     */
    case SelectByAttackAdmission = 'select_by_attack_admission';

    /**
     * Le camp defenseur decidera, avec ses propres budgets.
     *
     * Distinct de l'admission attaquante : les deux camps ont des listes et des limites separees,
     * et une Defense ACS refusee repart au lieu de stationner.
     */
    case SelectByDefenceAdmission = 'select_by_defence_admission';

    /**
     * L'ordre des evenements decidera, pas l'etat courant de la cible.
     *
     * Un recycleur prevu avant la creation de nouveaux debris ne doit pas les recolter au seul
     * motif que son worker etait en retard. Un missile engage apres l'ouverture malgre le verrou
     * est une anomalie a signaler, pas une admission silencieuse.
     */
    case SelectByEventOrder = 'select_by_event_order';

    /**
     * La flotte n'a nulle part ou se poser, et porte des actifs a preserver.
     *
     * **Distincte de `CancelWithoutImpact`**, et la distinction n'est pas cosmetique : annuler une
     * flotte de joueur chargee ferait disparaitre ses vaisseaux et sa cargaison. Le cas ne devrait
     * pas se produire — la planete mere garantit normalement une destination —, et c'est justement
     * pourquoi il compte : s'il survient, c'est une corruption ou un etat administratif.
     *
     * Le traitement conserve la mission, les unites et la cargaison, empeche le rejeu automatique
     * infini, place l'operation en quarantaine et leve une alerte critique persistante.
     */
    case RequiresAssetRecovery = 'requires_asset_recovery';

    /**
     * Cette cible ne releve pas du verrou d'un corps celeste.
     *
     * L'espace profond n'en porte aucun. Le dire explicitement evite qu'une egalite de coordonnees
     * fasse heriter un champ de debris ou une position vide du verrou d'une planete.
     */
    case OutsideMatrixDomain = 'outside_matrix_domain';
}
