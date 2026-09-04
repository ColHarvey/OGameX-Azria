<?php

namespace OGame\GameMissions\Models;

use OGame\Models\Enums\PlanetType;
use OGame\Models\Planet\Coordinate;

/**
 * Le corps ou une mission retour se posera, decide par l'appelant et ecrit tel quel.
 *
 * ## Pourquoi la creation du retour ne relit rien
 *
 * Une destination de repli est une **decision**, prise sous verrou par celui qui sait pourquoi la
 * flotte rentre : identite du corps, son type, ses coordonnees et son proprietaire. La relire au
 * moment d'ecrire la mission exposerait la flotte a atterrir ailleurs — un corps transfere, une lune
 * rasee entre la decision et l'insertion — sans que rien ne le signale.
 *
 * Cet objet porte donc la decision entiere, et `GameMission::startReturn()` l'ecrit sans la
 * questionner. C'est le pendant, cote jeu, de `ReturnPlan` cote combat : le premier decrit ce qui a
 * ete decide, le second pourquoi.
 */
final readonly class ResolvedReturnDestination
{
    public function __construct(
        public int $bodyId,
        public PlanetType $type,
        public Coordinate $coordinate,
        public int $ownerId,
    ) {
    }
}
