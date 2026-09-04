<?php

namespace OGame\Combat\Exceptions;

use RuntimeException;

/**
 * La destination d'une flotte a change entre le moment ou on l'a choisie et celui ou on la tient.
 *
 * ## Pourquoi s'arreter plutot que suivre
 *
 * Choisir une destination demande de lire des corps qu'on ne tient pas encore : c'est ce qui dit
 * **quelles lignes verrouiller**. Une fois tenues, la decision est reprise ; si elle differe, un
 * corps a bouge entre les deux — transfere, rase, purge.
 *
 * Suivre la nouvelle destination reviendrait a ecrire un plan qu'on n'a jamais verifie sous verrou,
 * et pour lequel on tient peut-etre la mauvaise ligne. L'annulation s'arrete donc : le corps reste
 * tenu, rien n'est ecrit, et le passage suivant repartira de l'etat courant.
 */
class ReturnDestinationMoved extends RuntimeException
{
    public function __construct(
        public readonly int $combatInstanceId,
        public readonly int $fleetMissionId,
        public readonly int|null $chosenBodyId,
        public readonly int|null $lockedBodyId,
    ) {
        parent::__construct(
            'Le combat ' . $combatInstanceId . ' avait choisi le corps ' . ($chosenBodyId ?? 'aucun')
            . ' pour la flotte ' . $fleetMissionId . ', et sous verrou c est le corps '
            . ($lockedBodyId ?? 'aucun') . ' : la destination a bouge, rien n est ecrit.'
        );
    }
}
