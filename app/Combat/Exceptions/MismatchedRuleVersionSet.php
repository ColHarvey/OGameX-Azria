<?php

namespace OGame\Combat\Exceptions;

use RuntimeException;

/**
 * Deux combats gouvernes par des versions differentes ont ete compares.
 *
 * ## Pourquoi lever, et non rendre `false`
 *
 * « Ces deux ensembles different » invite a continuer : l'appelant lit le booleen, prend une branche
 * et poursuit. Or il n'y a pas de branche juste. Un combat photographie sous une version et regle
 * sous une autre n'a pas de resultat reproductible — ce n'est pas une divergence a arbitrer, c'est
 * une comparaison qui n'aurait pas du etre tentee.
 *
 * Le message porte les deux ensembles : une enquete a besoin de savoir laquelle des quatre regles a
 * bouge, et quand.
 */
class MismatchedRuleVersionSet extends RuntimeException
{
    /**
     * @param array<string, string> $expected Les versions du combat.
     * @param array<string, string> $received Celles qu'on lui a presentees.
     */
    public function __construct(
        public readonly array $expected,
        public readonly array $received,
    ) {
        parent::__construct(
            'Deux ensembles de versions de regle ont ete compares : « ' . self::describe($expected)
            . ' » et « ' . self::describe($received) . ' ». Un combat garde les regles sous lesquelles '
            . 'il a commence ; comparer deux ensembles differents n a pas de reponse juste.'
        );
    }

    /**
     * Un ensemble, sous une forme lisible dans un journal.
     *
     * @param array<string, string> $set
     */
    private static function describe(array $set): string
    {
        $morceaux = [];

        foreach ($set as $regle => $version) {
            $morceaux[] = $regle . '=' . $version;
        }

        return implode(', ', $morceaux);
    }
}
