<?php

namespace OGame\Combat\Enums;

/**
 * Les sortes d'evenements qui peuvent affecter un combat, et leur ordre de departage.
 *
 * **Cette enumeration existe pour rendre le classement total.** Departager deux evenements de la
 * meme seconde par leur seul identifiant de source ne suffit pas : un missile portant le numero
 * douze et une construction portant le numero douze sont deux choses differentes, et rien ne
 * dirait laquelle vient en premier. Les identifiants viennent de tables distinctes, donc leurs
 * espaces se recouvrent.
 *
 * L'ordre ci-dessous est **arbitraire mais stable**, et c'est tout ce qu'on lui demande. Il ne
 * sert qu'a rendre reproductible le classement de deux evenements simultanes : rejouer les mêmes
 * evenements dans un autre ordre de traitement doit donner le meme resultat logique.
 *
 * Le rang commence a un. Zero est reserve aux **barrieres**, qui ne sont pas des evenements et
 * doivent se placer avant tout ce qui porte la meme seconde — c'est la convention qui fait qu'une
 * arrivee prevue pile a la fermeture lui est posterieure.
 */
enum CombatEventType: string
{
    /**
     * L'arrivee d'une flotte.
     */
    case FleetArrival = 'fleet_arrival';

    /**
     * L'impact d'un missile.
     */
    case MissileImpact = 'missile_impact';

    /**
     * La fin d'une construction ou d'une recherche.
     */
    case QueueCompletion = 'queue_completion';

    /**
     * L'achevement d'une recherche qui change les caracteristiques de combat.
     *
     * Elle ne pose aucune flotte et modifie pourtant la photographie : armes, boucliers,
     * protection. Engagee avant l'ouverture et terminee avant la fermeture, elle compte ; commencee
     * apres l'ouverture, elle ne modifie pas ce combat.
     */
    case ResearchCompletion = 'research_completion';

    /**
     * Le rang de cette sorte dans le departage.
     *
     * Toujours strictement positif : zero appartient aux barrieres.
     */
    public function rank(): int
    {
        return match ($this) {
            self::FleetArrival => 1,
            self::MissileImpact => 2,
            self::QueueCompletion => 3,

            // **Ajoute apres les trois autres, deliberement.** Les changer de place
            // modifierait l'ordre de deux effets simultanes deja en production ; ce rang-ci est
            // additif et ne touche a rien. Si l'achevement d'une recherche doit precer un
            // chantier a la meme seconde, c'est une decision de jeu a prendre separement.
            self::ResearchCompletion => 4,
        };
    }
}
