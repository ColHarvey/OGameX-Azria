<?php

namespace OGame\Combat\Exceptions;

use RuntimeException;

/**
 * La mission relue sous verrou est liee a un combat ou une union que la porte ne tient pas.
 *
 * ## Pourquoi ne pas simplement prendre le verrou manquant
 *
 * La porte calcule ce qu'elle doit tenir a partir du modele recu, **avant** de relire la mission :
 * c'est l'ordre global — barriere, instance, union, mission — qui l'impose. Si une jointure ou un
 * rattachement a change le lien entre-temps, la porte tient l'ancienne union ou l'ancien combat et
 * s'apprete a decider sur le nouveau, jamais verrouille.
 *
 * Acquerir le verrou manquant a ce moment-la le prendrait **apres** la mission, dans le sens
 * inverse de l'ordre global : deux transactions pourraient alors s'attendre mutuellement. La seule
 * issue sure est de tout relacher et de recommencer depuis la barriere, avec les liens a jour.
 *
 * La porte le fait elle-meme un nombre borne de fois ; cette exception ne sort que si les liens
 * changent plus vite qu'elle ne les rattrape.
 */
class MovementLocksOutdated extends RuntimeException
{
    public function __construct(
        public readonly int $fleetMissionId,
        public readonly string $lien,
        public readonly int|null $tenu,
        public readonly int|null $courant,
    ) {
        parent::__construct(
            'La mission ' . $fleetMissionId . ' est liee a ' . $lien . ' ' . ($courant ?? 'aucun')
            . ' alors que la porte tient ' . ($tenu ?? 'aucun')
            . ' : le lien a change pendant la prise des verrous, tout est relache.'
        );
    }
}
