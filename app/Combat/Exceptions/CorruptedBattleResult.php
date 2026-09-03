<?php

namespace OGame\Combat\Exceptions;

use RuntimeException;

/**
 * Un resultat de bataille persiste qui ne se relit pas tel qu'il a ete ecrit.
 *
 * Le resultat est ce qu'un combat durable rejoue des heures apres son calcul : le regler sur un
 * resultat relu autrement — un entier devenu chaine, une unite inconnue, un champ manquant —
 * serait regler une autre bataille. Le refus nomme le champ pour que l'enquete commence la.
 */
class CorruptedBattleResult extends RuntimeException
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
            'Le resultat de bataille persiste est inexploitable : ' . $defect . '. Il n est pas '
            . 'converti en silence : c est lui que le reglement rejoue, et un resultat relu autrement '
            . 'qu ecrit reglerait une autre bataille. Lu : ' . self::describe($received) . '.'
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
