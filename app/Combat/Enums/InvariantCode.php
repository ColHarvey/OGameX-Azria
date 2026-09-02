<?php

namespace OGame\Combat\Enums;

/**
 * Une situation qui revele un defaut, et non une issue de jeu.
 *
 * Un `CombatReasonCode` explique a un joueur pourquoi sa flotte est repartie. Un code d'invariant
 * n'explique rien a personne : il dit que les donnees se contredisent, et il appelle une alerte,
 * pas un message. Les tenir separes empeche qu'un defaut interne se retrouve traduit et affiche
 * comme une regle du jeu.
 */
enum InvariantCode: string
{
    /**
     * La situation elle-meme ne peut pas se produire.
     *
     * Un missile n'a pas d'etape de retour. Dans une enumeration la case se range ; sur un chemin
     * vivant elle leve, parce qu'elle revele un defaut en amont.
     */
    case SituationCannotOccur = 'situation_cannot_occur';

    /**
     * La cible n'est pas un corps celeste : aucun verrou de combat ne la couvre.
     *
     * L'espace profond n'en porte aucun. Une entree qui presenterait un etat de combat pour une
     * telle cible confondrait deux domaines.
     */
    case NotACelestialBodyTarget = 'not_a_celestial_body_target';

    /**
     * Une flotte porte des actifs et n'a plus aucune destination.
     *
     * Les recours ordonnes du jeu — corps d'origine, planete associee, planete mere — sont epuises.
     * Ce n'est pas une regle de jeu mais un defaut : une alerte, pas un message de rapport.
     */
    case AssetsWithoutDestination = 'assets_without_destination';
}
