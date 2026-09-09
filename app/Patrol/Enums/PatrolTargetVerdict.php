<?php

namespace OGame\Patrol\Enums;

/**
 * Ce qu une attaque trouve a l emplacement qu elle avait gele au lancement.
 *
 * ## Pourquoi trois issues et non deux
 *
 * Les deux dernieres menent au meme geste — la flotte arrive sur du vide et rentre (revue 117 :
 * aucune poursuite) — mais elles ne disent pas la meme chose au joueur, et un essai qui les
 * confond ne verrait pas une patrouille substituee a une autre.
 */
enum PatrolTargetVerdict: string
{
    /**
     * La patrouille gelee est encore posee a l emplacement gele : le combat peut s ouvrir.
     */
    case Present = 'present';

    /**
     * Elle n existe plus, ou n est plus posee — rentree, terminee, repartie en vol, ou ses unites
     * sont parties attaquer ailleurs. Il n y a rien a combattre a ce point.
     */
    case Gone = 'gone';

    /**
     * Elle existe et est posee, mais **ailleurs**. L attaque ne la suit pas.
     */
    case Moved = 'moved';

    /**
     * Le combat peut-il s ouvrir sur cette issue ?
     */
    public function opensTheCombat(): bool
    {
        return $this === self::Present;
    }
}
