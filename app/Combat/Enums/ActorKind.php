<?php

namespace OGame\Combat\Enums;

/**
 * Qui tient la flotte : un joueur, une faction pilotee par le serveur, ou le serveur lui-meme.
 *
 * **Cette dimension existe parce que « renvoyer a l'origine » n'est pas toujours possible.** Une
 * base pirate utilise une attaque parfaitement ordinaire, mais elle n'a pas toujours de planete
 * ou revenir, et personne n'attend de message dans sa boite de reception. Lui appliquer la regle
 * des joueurs obligerait a lui inventer une origine — c'est-a-dire a mentir dans les donnees pour
 * satisfaire une regle qui ne la concerne pas.
 *
 * Le compte systeme historique — Arakis — releve du troisieme cas : il existe, il possede une
 * planete, mais aucune mecanique de jeu ne s'applique a lui.
 *
 * **Cette enumeration reste descriptive.** Elle ne dit pas si une flotte peut rentrer chez
 * elle : un pirate peut avoir une origine parfaitement valide, et un joueur peut avoir perdu
 * la sienne entre-temps. Cette capacite-la est un fait a etablir, porte par le contexte —
 * voir `ReturnPlan`.
 */
enum ActorKind: string
{
    /**
     * Une personne. Recoit des messages, possede des planetes, subit les regles du jeu.
     */
    case Player = 'player';

    /**
     * Une faction pilotee par le serveur : les bases pirates.
     */
    case Npc = 'npc';

    /**
     * Le serveur lui-meme, pour ce qui n'appartient a personne.
     */
    case System = 'system';
}
