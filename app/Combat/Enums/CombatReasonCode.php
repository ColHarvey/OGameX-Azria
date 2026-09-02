<?php

namespace OGame\Combat\Enums;

/**
 * Pourquoi le combat a decide ce qu'il a decide.
 *
 * **Un code stable, jamais une cle de traduction.** Le domaine dit ce qui s'est passe ; la couche
 * d'affichage choisit comment le dire, et dans quelle langue. Melanger les deux ferait de la
 * traduction une decision metier : renommer une cle deviendrait un changement de regle, et une
 * langue manquante, un bogue de combat.
 *
 * Ces codes servent aussi aux journaux et a l'audit, ou une phrase traduite ne vaut rien.
 */
enum CombatReasonCode: string
{
    /**
     * Le corps celeste de depart est engage dans un combat : rien n'en part.
     */
    case OriginCombatLocked = 'origin_combat_locked';

    /**
     * La cible est engagee dans un combat : rien de nouveau ne s'y dirige.
     */
    case TargetCombatLocked = 'target_combat_locked';

    /**
     * La fenetre de ralliement etait deja fermee a l'heure prevue de l'arrivee.
     */
    case RallyClosed = 'rally_closed';

    /**
     * Le proprietaire de la flotte n'appartient pas a l'alliance de l'attaquant initial.
     */
    case AllianceNotEligible = 'alliance_not_eligible';

    /**
     * Le camp a atteint son plafond de flottes.
     */
    case FleetLimitReached = 'fleet_limit_reached';

    /**
     * Le camp a atteint son plafond de joueurs distincts.
     */
    case PlayerLimitReached = 'player_limit_reached';

    /**
     * Personne ne prete main-forte a une faction pilotee par le serveur.
     */
    case NpcSideNotReinforceable = 'npc_side_not_reinforceable';

    /**
     * La flotte est deja engagee : il est trop tard pour la retirer.
     */
    case AlreadyEngaged = 'already_engaged';

    /**
     * Un resultat est en cours d'application : rien ne doit toucher la cible maintenant.
     */
    case ResolutionInProgress = 'resolution_in_progress';

    /**
     * Ces vaisseaux rentrent chez eux : ils se posent, mais hors de la photographie.
     */
    case OwnFleetComingHome = 'own_fleet_coming_home';

    /**
     * Le combat n'a rien a dire sur cette mission.
     */
    case NoCombatEffect = 'no_combat_effect';

    /**
     * La regle de cette case n'est pas encore arretee.
     *
     * **Ce n'est pas une valeur de repli.** Elle nomme une decision qui reste a prendre, et un
     * test veille a ce qu'aucune case ne quitte cette liste sans qu'une regle ait ete ecrite.
     */
    case Undecided = 'undecided';

    /**
     * La position visee par une colonisation a cesse d'etre libre pendant le vol.
     */
    case PositionNoLongerFree = 'position_no_longer_free';

    /**
     * Le plan de retour resolu ne designe aucun corps ou se poser.
     *
     * Le dernier recours, apres le corps d'origine, la planete associee et la planete mere.
     */
    case NoReturnDestination = 'no_return_destination';
}
