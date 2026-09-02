<?php

namespace OGame\Combat\MoonDestruction;

use InvalidArgumentException;

/**
 * Une mission de destruction admise dans le combat commun, telle qu'on la gele.
 *
 * ## Pourquoi les etoiles de la mort sont comptees ici
 *
 * Elles sont **survivantes**, et propres a cette mission. Mettre en commun les survivantes de
 * plusieurs missions ferait grimper la probabilite de chacune : trois missions d'une etoile
 * tireraient comme une mission de trois, alors que le jeu ne l'a jamais permis.
 *
 * ## Pourquoi l'heure d'arrivee planifiee est conservee
 *
 * Elle donne l'ordre des tentatives, et elle doit venir de la mission, pas de l'ordre ou la base a
 * rendu les lignes. Deux lectures dans un ordre different doivent produire le meme plan.
 */
final readonly class MoonDestructionCandidate
{
    /**
     * @param int $fleetMissionId La mission admise.
     * @param int $scheduledArrivalAt Son arrivee planifiee, en secondes.
     * @param int $survivingDeathstars Ses etoiles de la mort survivantes, jamais celles des autres.
     */
    public function __construct(
        public int $fleetMissionId,
        public int $scheduledArrivalAt,
        public int $survivingDeathstars,
    ) {
        if ($fleetMissionId < 1) {
            throw new InvalidArgumentException(
                'Une mission de destruction sans identifiant ne peut pas etre gelee : la cle d idempotence '
                . 'serait la meme pour toutes, et un rejeu appliquerait la tentative d une autre.'
            );
        }

        if ($survivingDeathstars < 0) {
            throw new InvalidArgumentException(
                'Un nombre negatif d etoiles de la mort survivantes ne veut rien dire.'
            );
        }
    }
}
