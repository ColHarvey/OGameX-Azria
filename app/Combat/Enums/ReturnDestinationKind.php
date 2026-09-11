<?php

namespace OGame\Combat\Enums;

/**
 * La nature de la destination retenue pour une flotte renvoyee.
 *
 * Ces quatre cas sont **ordonnes** : on essaie le corps d'origine, puis la planete associee, puis
 * la planete mere, et on ne conclut a l'impossibilite qu'en dernier. Les nommer separement plutot
 * que de ne garder qu'un identifiant permet aux rapports et aux journaux de dire *pourquoi* la
 * flotte s'est posee ailleurs que d'ou elle etait partie — ce qu'un joueur voudra savoir si sa
 * lune a ete detruite pendant le vol.
 */
enum ReturnDestinationKind: string
{
    /**
     * Le corps de depart, toujours en place.
     */
    case OriginalBody = 'original_body';

    /**
     * La planete des memes coordonnees, la lune de depart ayant ete detruite.
     */
    case AssociatedPlanet = 'associated_planet';

    /**
     * La planete mere, le corps de depart ayant disparu.
     */
    case Homeworld = 'homeworld';

    /**
     * Aucune destination : la flotte ne rentre pas.
     */
    /**
     * La flotte revient **au point de l espace d ou elle est partie** : sa patrouille l y attend.
     *
     * C est le seul genre qui ne designe **aucun corps celeste**. Une patrouille est un poste : elle
     * frappe et retourne a son guet (decision de Keven, 11 septembre 2026). Les invariants de ce
     * genre ne sont pas plus laches que ceux des autres — ils portent sur une autre chose : une
     * patrouille vivante, du proprietaire de la flotte, exactement a ce point.
     */
    case PatrolPoint = 'patrol_point';

    case None = 'none';
}
