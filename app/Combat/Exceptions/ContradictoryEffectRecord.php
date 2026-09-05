<?php

namespace OGame\Combat\Exceptions;

use RuntimeException;

/**
 * Deux mesures d'un meme effet ne concordent pas : le registre refuse d'arbitrer.
 *
 * Un effet gouverne n'est applique qu'une fois, sous la porte, et son delta est ecrit dans la meme
 * transaction. Une seconde ecriture sous la meme identite avec un autre delta ne peut venir que d'un
 * defaut — deux chemins qui appliquent, une mesure prise hors verrou — et garder le premier le
 * cacherait. Le refus le montre.
 */
final class ContradictoryEffectRecord extends RuntimeException
{
    /**
     * @param array<string, int> $recorded
     * @param array<string, int> $offered
     */
    public function __construct(
        public readonly int $combatInstanceId,
        public readonly string $eventIdentity,
        public readonly array $recorded,
        public readonly array $offered,
    ) {
        parent::__construct(
            'Le registre des effets du combat ' . $combatInstanceId . ' porte deja un delta different pour '
            . $eventIdentity . ' : inscrit ' . json_encode($recorded) . ', propose ' . json_encode($offered) . '.'
        );
    }
}
