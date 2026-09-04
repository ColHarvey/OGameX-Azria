<?php

namespace OGame\Combat\Exceptions;

use RuntimeException;

/**
 * Une flotte dont tous les recours de destination sont epuises.
 *
 * ## Pourquoi le corps reste tenu
 *
 * Les recours sont ordonnes et le jeu les prevoit : corps d'origine, planete associee quand une lune
 * a disparu, planete mere du compte. Les epuiser tous signifie que le proprietaire n'a plus aucun
 * corps ou poser sa flotte — un compte efface, une base pirate rasee.
 *
 * Ce n'est pas une raison pour liberer le corps attaque en pretendant que toutes les flottes sont
 * rendues : elles ne le sont pas. L'annulation s'arrete donc avant de rendre le combat final, et
 * l'exploitation traite le cas comme ce qu'il est — une recuperation d'actifs, pas une disparition.
 */
class FleetHasNowhereToReturn extends RuntimeException
{
    public function __construct(
        public readonly int $combatInstanceId,
        public readonly int $fleetMissionId,
        public readonly string|null $reason = null,
    ) {
        parent::__construct(
            'Le combat ' . $combatInstanceId . ' ne peut pas rendre la flotte ' . $fleetMissionId
            . ' : tous les recours de destination sont epuises'
            . ($reason === null ? '' : ' (' . $reason . ')')
            . '. Le corps reste tenu, et les actifs attendent une recuperation.'
        );
    }
}
