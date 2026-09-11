<?php

namespace OGame\GameMissions\Models;

/**
 * Class MissionPossibleStatus.
 *
 * This class is used to represent the possible status of a mission.
 *
 * @package OGame\GameMissions\Models
 */
class MissionPossibleStatus
{
    /**
     * @param bool $possible Whether the mission is possible.
     * @param string $error The error message if the mission is not possible.
     * @param bool $explainToThePlayer Whether the reason must reach the player instead of staying silent.
     */
    public function __construct(
        public bool $possible,
        public string $error = '',
        /*
         * **Ce refus doit-il etre dit, ou peut-il se taire ?**
         *
         * La plupart des refus sont hors sujet : sur une planete habitee, la colonisation est
         * refusee, et l annoncer serait du bruit. Une **protection** est autre chose — une regle du
         * jeu, sur une mission que le joueur essayait d employer. La taire lui laisse un bouton
         * grise sans explication, et il conclut que le jeu est casse.
         *
         * Le drapeau est **explicite** et vaut faux par defaut : deduire de « le message n est pas
         * vide » qu il faut l afficher aurait rouvert d un coup tous les refus muets, et personne
         * ne l aurait vu avant la production.
         */
        public bool $explainToThePlayer = false,
    ) {
    }
}
