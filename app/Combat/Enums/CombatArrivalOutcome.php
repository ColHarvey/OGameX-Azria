<?php

namespace OGame\Combat\Enums;

/**
 * Ce qu'il advient d'une flotte au moment precis ou elle arrive sur sa cible.
 *
 * Quatre issues, et une seule s'applique a chaque arrivee. Elles sont nommees du point de vue de
 * la flotte, pas de celui du serveur : c'est ce que le joueur lira dans sa vue Flotte.
 */
enum CombatArrivalOutcome: string
{
    /**
     * Rien ne se passait ici : cette arrivee ouvre la fenetre de ralliement.
     *
     * Le corps celeste est verrouille immediatement, et les soixante secondes commencent a
     * courir a partir de cet instant.
     */
    case OpensRally = 'opens_rally';

    /**
     * Une fenetre de ralliement est ouverte et cette flotte y est admise.
     *
     * Elle figurera dans la photo prise a la fermeture, au meme titre que celles arrivees avant
     * elle. C'est le cas des vagues successives, qui sont la raison d'etre de la fenetre.
     */
    case JoinsRally = 'joins_rally';

    /**
     * La flotte fait demi-tour et rentre chez elle.
     *
     * **Il n'y a ni file d'attente ni second combat automatique.** Une attaque arrivee apres la
     * fermeture, ou lancee par un joueur etranger a l'alliance attaquante, est rappelee vers son
     * origine par la mecanique normale de rappel : vaisseaux et fret reviennent, sans second
     * cout de carburant.
     *
     * Une premiere version faisait attendre ces flottes au-dessus de la cible pour ouvrir le
     * ralliement suivant. Elle a ete ecartee : elle transformait une cible en file d'attente
     * ou des flottes s'empilaient sans que leur proprietaire puisse rien en faire, et elle
     * ouvrait autant de failles qu'il y a de facons d'arriver en retard.
     */
    case RecalledToOrigin = 'recalled_to_origin';

    /**
     * La flotte se pose, mais ne combat pas.
     *
     * Le cas des retours et des deploiements arrives apres la fermeture. Ils atteignent bien le
     * corps celeste — les renvoyer serait absurde, ils rentrent chez eux — mais ils ne figurent
     * pas dans la photo et **ne peuvent pas repartir avant la fin du combat**.
     *
     * C'est ce cas qui impose que les pertes soient appliquees comme une **difference sur les
     * unites de la photo**, jamais en remplacant le contenu du corps celeste : sinon ces
     * vaisseaux-la, arrives apres coup, seraient effaces par la resolution.
     */
    case ArrivesWithoutJoining = 'arrives_without_joining';
}
