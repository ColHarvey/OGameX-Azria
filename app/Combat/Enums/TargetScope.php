<?php

namespace OGame\Combat\Enums;

/**
 * Ce qu'une mission vise reellement, et non ou elle se rend.
 *
 * ## Pourquoi une egalite de coordonnees ne suffit jamais
 *
 * Un champ de debris, une position vide et l'espace profond peuvent partager les coordonnees
 * d'une planete assiegee. Laisser le verrou du corps celeste se propager par simple egalite de
 * coordonnees interdirait de recycler pendant un combat, refuserait une colonisation sur une
 * position qui n'a rien a voir, et ferait dependre une expedition de l'etat d'une planete qu'elle
 * ne visite pas.
 *
 * **Planete et lune restent deux corps separes.** Elles partagent leurs coordonnees et rien
 * d'autre : un combat sur la lune ne verrouille pas la planete.
 */
enum TargetScope: string
{
    /**
     * Une planete ou une lune : le seul cas ou le verrou de combat s'applique.
     */
    case CelestialBody = 'celestial_body';

    /**
     * Un champ de debris. Il occupe des coordonnees, il n'herite d'aucun verrou.
     *
     * Le combat le concerne quand meme, mais par l'ordre des evenements et non par le verrou :
     * un recycleur prevu avant la creation de nouveaux debris ne doit pas les recolter au seul
     * motif que son traitement a ete retarde.
     */
    case DebrisField = 'debris_field';

    /**
     * Une position libre, visee par une colonisation.
     *
     * Elle peut avoir cesse d'etre libre pendant le vol : la mission echoue alors par ses propres
     * regles. Elle ne cree jamais de colonie sur un corps verrouille.
     */
    case EmptyPosition = 'empty_position';

    /**
     * L'espace profond. Aucun corps celeste, donc aucun verrou possible.
     */
    case DeepSpace = 'deep_space';

    /**
     * Le plan de retour resolu ne designe aucun corps.
     *
     * `FlightLeg::Return` ne suffit pas a garantir qu'une flotte se pose quelque part : le corps
     * d'origine peut avoir disparu pendant le vol. Le jeu prevoit les recours, et ils sont ordonnes
     * — corps d'origine, planete associee, planete mere —, mais leur epuisement est possible pour
     * un acteur qui n'a plus rien. C'est le plan resolu sous verrou qui le dit, jamais le genre de
     * mission ni l'etape de vol.
     */
    case NoDestination = 'no_destination';
}
