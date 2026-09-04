<?php

namespace OGame\Combat\Exceptions;

use LogicException;

/**
 * Une chronologie deja ecrite pour ce combat et cette version ne dit pas la meme chose.
 *
 * Un rejeu du meme resultat gele produit exactement les memes evenements : c'est le contrat de la
 * regle de presentation. Si les lignes en base different de la projection — un evenement en plus,
 * un instant qui bouge, une quantite qui change —, alors soit le resultat a change apres avoir ete
 * gele, soit la regle n'est plus deterministe. Les deux sont des defauts, et aucun ne se repare en
 * gardant la premiere ligne ou en ecrasant la seconde.
 */
final class ContradictoryPresentationTimeline extends LogicException
{
    public static function forCombat(int $combatInstanceId, string $version, string $difference): self
    {
        return new self(sprintf(
            'La chronologie du combat %d (version %s) est deja ecrite et ne correspond pas a la projection du resultat gele : %s.',
            $combatInstanceId,
            $version,
            $difference
        ));
    }
}
