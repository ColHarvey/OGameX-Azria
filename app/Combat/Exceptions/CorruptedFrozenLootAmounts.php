<?php

namespace OGame\Combat\Exceptions;

use RuntimeException;

/**
 * Des montants de butin persistes n'ont pas la forme sous laquelle ils ont ete ecrits.
 *
 * ## Pourquoi une exception distincte d'`InvalidArgumentException`
 *
 * Les deux disent « ces montants ne sont pas valides », mais elles ne s'adressent pas au meme
 * lecteur et n'appellent pas la meme suite :
 *
 *     InvalidArgumentException      une faute d appelant, corrigee dans le code
 *     CorruptedFrozenLootAmounts    une donnee gelee illisible, qui demande une decision d exploitation
 *
 * Un combat dont le butin persiste est corrompu ne se rejoue pas « au mieux » : le montant debite au
 * defenseur, celui charge dans les soutes et celui ecrit au rapport doivent etre le meme, et une
 * lecture approximative les separerait sans que personne ne le voie.
 *
 * Les confondre priverait le cycle operationnel de la distinction dont il a besoin : un bogue se
 * corrige et se redeploie, une donnee corrompue se constate et se traite.
 */
class CorruptedFrozenLootAmounts extends RuntimeException
{
    /**
     * @param string $defect Ce qui n'allait pas, en clair.
     * @param mixed $received Ce qui a ete lu, pour l'enquete.
     */
    public function __construct(
        public readonly string $defect,
        public readonly mixed $received = null,
    ) {
        parent::__construct(
            'Des montants de butin persistes sont inexploitables : ' . $defect . '. Ils ne sont pas '
            . 'convertis en silence : le montant debite, celui charge en soute et celui ecrit au '
            . 'rapport doivent etre le meme. Lu : ' . self::describe($received) . '.'
        );
    }

    /**
     * Ce qui a ete lu, sous une forme lisible dans un journal.
     */
    private static function describe(mixed $received): string
    {
        if ($received === null) {
            return 'null';
        }

        $rendu = json_encode($received);

        return $rendu === false ? gettype($received) : $rendu;
    }
}
