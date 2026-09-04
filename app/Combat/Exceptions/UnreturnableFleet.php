<?php

namespace OGame\Combat\Exceptions;

use RuntimeException;

/**
 * Une flotte dont le trajet aller ne se lit pas ne peut pas etre rendue.
 *
 * ## Pourquoi un refus, et pas un retour instantane
 *
 * Le retour dure ce qu'a dure l'aller. Si une mission porte une arrivee anterieure ou egale a son
 * depart — donnee corrompue, reparation a la main, migration incomplete —, la duree calculee serait
 * nulle ou negative : le retour naitrait deja arrive, et serait traite dans la transaction meme qui
 * l'a cree. La flotte se poserait alors sur un corps que personne n'a verrouille.
 *
 * L'annulation s'arrete donc avant de rendre le combat final : le corps reste tenu, rien n'est
 * ecrit, et l'exploitation voit un incident nomme plutot qu'une flotte apparue quelque part.
 */
class UnreturnableFleet extends RuntimeException
{
    public function __construct(
        public readonly int $combatInstanceId,
        public readonly int $fleetMissionId,
        public readonly int $outboundDuration,
    ) {
        parent::__construct(
            'Le combat ' . $combatInstanceId . ' ne peut pas rendre la flotte ' . $fleetMissionId
            . ' : son trajet aller dure ' . $outboundDuration . ' seconde(s), donc le retour naitrait '
            . 'deja arrive. Le corps reste tenu tant que la mission n a pas ete reparee.'
        );
    }
}
