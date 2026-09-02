<?php

namespace OGame\Combat\Exceptions;

use RuntimeException;

/**
 * Deux incidents se reclament de la meme occurrence, et ne disent pas la meme chose.
 *
 * L'identite d'une occurrence promet quelque chose : le meme moment fonctionnel, le meme sujet, la
 * meme ressource. Deux contenus differents sous cette identite signifient que l'identite ne
 * distingue plus ce qu'elle devrait, ou qu'un diagnostic a ete construit ailleurs qu'a son point de
 * detection.
 *
 * **Refuser plutot que choisir.** Garder l'un des deux effacerait un incident reel ; les garder tous
 * les deux sous une meme identite rendrait la deduplication arbitraire.
 */
class ContradictoryResourceDiagnostic extends RuntimeException
{
    /**
     * @param string $identity
     * @param string $first
     * @param string $second
     * @return self
     */
    public static function because(string $identity, string $first, string $second): self
    {
        return new self(
            'Deux diagnostics portent l identite « ' . $identity . ' » avec des contenus differents ('
            . $first . ' contre ' . $second . '). Une identite d occurrence doit designer un seul '
            . 'incident : soit elle ne distingue pas assez, soit un diagnostic a ete reconstruit '
            . 'ailleurs qu a son point de detection.'
        );
    }
}
