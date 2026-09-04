<?php

namespace OGame\Enums;

/**
 * Pourquoi une flotte ne fonde pas d'union, ou n'en rejoint pas une.
 *
 * ## Une raison typee derriere un message joueur
 *
 * Plusieurs refus donnent au joueur le meme message — « cette flotte n'est plus disponible » —, et
 * c'est juste : pour lui, la flotte est partie, engagee ou deja decidee. Mais l'exploitation doit
 * savoir lequel a joue, et un essai qui rougit doit pouvoir le dire sans ambiguite. La raison
 * voyage donc avec l'exception, et le message se derive d'elle.
 */
enum UnionRefusalReason: string
{
    /** Pas une attaque : un transport, une colonisation, une espionne. */
    case NotAnAttack = 'not_an_attack';

    /** La mission est deja traitee : arrivee, resolue, renvoyee. */
    case AlreadyProcessed = 'already_processed';

    /** Le joueur l'a rappelee. */
    case Recalled = 'recalled';

    /** Un retour : il prolonge une mission, il ne part pas. */
    case ReturningFleet = 'returning_fleet';

    /** Deja dans une union. */
    case AlreadyInUnion = 'already_in_union';

    /** L'arrivee l'a rattachee a un combat. */
    case BoundToACombat = 'bound_to_a_combat';

    /** La fermeture l'a inscrite dans une photographie. */
    case EnrolledInACombat = 'enrolled_in_a_combat';

    /** Le combat a deja decide de son mouvement. */
    case MovementAlreadyDecided = 'movement_already_decided';

    /** L'union visee n'existe plus. */
    case UnionNotFound = 'union_not_found';

    /** L'union a atteint sa limite de flottes. */
    case MaxFleetsReached = 'max_fleets_reached';

    /** L'union a atteint sa limite de joueurs. */
    case MaxPlayersReached = 'max_players_reached';

    /** Le rejoignant n'est ni allie ni ami du fondateur. */
    case NotBuddyOrAlly = 'not_buddy_or_ally';

    /** La cible du rejoignant n'est pas celle de l'union. */
    case TargetMismatch = 'target_mismatch';

    /** Le rejoignant arriverait trop tard pour l'union. */
    case ExceedsDelayLimit = 'exceeds_delay_limit';

    /**
     * La cle de traduction du message que le joueur lit.
     */
    public function translationKey(): string
    {
        return match ($this) {
            self::NotAnAttack => 't_acs.error_invalid_mission_type',
            self::AlreadyProcessed,
            self::Recalled,
            self::BoundToACombat,
            self::EnrolledInACombat,
            self::MovementAlreadyDecided => 't_acs.error_mission_not_active',
            self::ReturningFleet => 't_acs.error_returning_fleet',
            self::AlreadyInUnion => 't_acs.error_already_in_union',
            self::UnionNotFound => 't_acs.error_not_found',
            self::MaxFleetsReached => 't_acs.error_max_fleets_reached',
            self::MaxPlayersReached => 't_acs.error_max_players_reached',
            self::NotBuddyOrAlly => 't_acs.error_not_buddy_or_ally',
            self::TargetMismatch => 't_ingame.fleet.err_union_target_mismatch',
            self::ExceedsDelayLimit => 't_acs.error_exceeds_delay_limit',
        };
    }
}
