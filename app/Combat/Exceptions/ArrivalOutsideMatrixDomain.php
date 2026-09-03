<?php

namespace OGame\Combat\Exceptions;

use OGame\Combat\Enums\InvariantCode;
use RuntimeException;

/**
 * Cette arrivee n'est pas du ressort du consommateur de corps celestes.
 *
 * ## Deux causes, et elles ne demandent pas la meme chose
 *
 *     SituationCannotOccur     -> un defaut : un missile qui rentre n'existe pas
 *     NotACelestialBodyTarget  -> un aiguillage : l'espace profond ne porte aucun verrou
 *
 * La seconde n'est pas une anomalie. Une expedition arrive bel et bien quelque part ; simplement,
 * ce n'est pas un corps celeste, et le combat persistant n'a rien a y dire. Elle leve tout de meme,
 * et c'est voulu : rendre un resultat « neutre » laisserait un appelant croire que le cas a ete
 * traite, alors qu'il doit etre **route ailleurs**.
 *
 * Le code d'invariant permet de distinguer les deux sans lire le message.
 */
class ArrivalOutsideMatrixDomain extends RuntimeException
{
    /**
     * @param InvariantCode $invariant Ce qui place cette arrivee hors du domaine.
     * @param string $situation La situation, telle que `CombatSituation::describe()` la donne.
     */
    public function __construct(
        public readonly InvariantCode $invariant,
        public readonly string $situation,
    ) {
        parent::__construct(
            'La situation « ' . $situation . ' » sort du domaine de la matrice des corps celestes ('
            . $invariant->value . '). Elle doit etre routee vers le mecanisme qui la concerne, ou corrigee '
            . 'la ou elle a ete produite.'
        );
    }
}
