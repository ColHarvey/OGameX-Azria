<?php

namespace OGame\Combat\MoonDestruction;

/**
 * Ce qu'une mission de destruction obtient, une fois le combat commun resolu.
 *
 * ## Une tentative par mission, pas une par flotte mise en commun
 *
 * Toutes les flottes admises se battent dans **un seul** combat. Mais l'effet special reste attache
 * a la mission qui le portait : une attaque ordinaire embarquant des etoiles de la mort ne detruit
 * jamais la lune, et chaque mission de destruction admise obtient au plus une tentative, avec ses
 * propres survivantes.
 *
 * ## Trois issues ne consomment aucun tirage
 *
 * C'est une garantie et pas un detail : une mission sautee qui tirerait quand meme decalerait la
 * suite du hasard, et deux rejeux du meme plan ne donneraient plus le meme resultat.
 */
enum MoonDestructionOutcome: string
{
    /**
     * La tentative a eu lieu et la lune a ete detruite.
     */
    case MoonDestroyed = 'moon_destroyed';

    /**
     * La tentative a eu lieu et la lune a tenu.
     */
    case AttemptFailed = 'attempt_failed';

    /**
     * Une tentative precedente avait deja detruit la lune : celle-ci ne tire pas.
     */
    case TargetAlreadyDestroyed = 'target_already_destroyed';

    /**
     * Aucune etoile de la mort de **cette** mission n'a survecu au combat.
     */
    case NoSurvivingDeathstar = 'no_surviving_deathstar';

    /**
     * Le camp attaquant n'a pas remporte le combat commun.
     */
    case AttackSideDidNotWin = 'attack_side_did_not_win';

    /**
     * Si cette issue a consomme les deux tirages.
     */
    public function consumedADraw(): bool
    {
        return match ($this) {
            self::MoonDestroyed, self::AttemptFailed => true,
            self::TargetAlreadyDestroyed, self::NoSurvivingDeathstar, self::AttackSideDidNotWin => false,
        };
    }
}
