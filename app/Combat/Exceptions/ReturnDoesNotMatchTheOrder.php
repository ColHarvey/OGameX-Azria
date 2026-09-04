<?php

namespace OGame\Combat\Exceptions;

use RuntimeException;

/**
 * Le retour que le genre de mission a cree ne correspond pas a l'ordre qu'il a recu.
 *
 * ## Pourquoi « un enfant existe » ne prouvait rien
 *
 * Le protocole comptait les retours de la mission apres l'appel et acceptait le total un. Un
 * enfant preexistant et une fermeture qui ne fait rien passaient ; un enfant unique avec une autre
 * destination, un autre depart ou des actifs amputes passait aussi, puisque seul le parent etait
 * compare. Le commentaire promettait plus que le code.
 *
 * Le protocole photographie donc les retours avant l'appel, exige **exactement un nouveau**, le
 * relit dans la transaction et le compare a l'ordre : parent, proprietaire, genre, destination
 * complete, depart, arrivee, unites et ressources. Une difference arrete tout — rien n'est marque,
 * rien n'est consomme.
 */
class ReturnDoesNotMatchTheOrder extends RuntimeException
{
    public function __construct(
        public readonly int $fleetMissionId,
        public readonly string $ecart,
    ) {
        parent::__construct(
            'Le retour cree pour la flotte ' . $fleetMissionId . ' ne correspond pas a l ordre recu : ' . $ecart
            . '. Rien n est marque, rien n est consomme.'
        );
    }
}
