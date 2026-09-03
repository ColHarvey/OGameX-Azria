<?php

namespace OGame\Combat\Exceptions;

use RuntimeException;

/**
 * Les faits d'application photographies a la cloture ne se relisent pas tels qu'ils ont ete ecrits.
 *
 * Ces faits — classe des joueurs, niveau de chantier spatial, seuils de champ d'epaves — decident
 * de ce que l'application ecrit. Les relire autrement qu'ecrits, ou se rabattre sur le monde
 * courant quand l'un manque, ferait dependre l'issue d'une bataille de ce qui a change pendant
 * qu'elle durait.
 */
class CorruptedFrozenApplicationContext extends RuntimeException
{
    /**
     * @param string $defect Ce qui ne va pas, en nommant le champ.
     * @param mixed $received Ce qui a ete lu, pour l'enquete.
     */
    public function __construct(
        public readonly string $defect,
        public readonly mixed $received = null,
    ) {
        parent::__construct(
            'Le contexte d application gele est inexploitable : ' . $defect . '. Il n est pas '
            . 'complete depuis le monde courant : ces faits decident de ce qui est ecrit, et les '
            . 'relire vivants ferait dependre une bataille de ce qui a change pendant qu elle durait. '
            . 'Lu : ' . self::describe($received) . '.'
        );
    }

    private static function describe(mixed $received): string
    {
        if (is_array($received)) {
            $encode = json_encode($received);

            return is_string($encode) ? mb_strimwidth($encode, 0, 200, '…') : 'structure non encodable';
        }

        if (is_scalar($received) || $received === null) {
            return var_export($received, true);
        }

        return get_debug_type($received);
    }
}
