<?php

namespace OGame\Combat\Exceptions;

use RuntimeException;

/**
 * Un plan de destruction de lune persiste n'a pas la forme sous laquelle il a ete ecrit.
 *
 * ## Le defaut que cette exception ferme
 *
 * Les trois relectures du plan — identite de la lune, tentatives, plan entier — **castaient** leurs
 * faits : `(int)$facts['moon_id']`, `(float)$facts['destruction_chance']`. Une chaine numerique, un
 * flottant a la place d'un entier, un booleen, passaient en silence et devenaient des nombres
 * plausibles. Or ce plan entre dans l'empreinte et dans les cles d'idempotence : un fait relu
 * autrement qu'il n'a ete ecrit rend un rejeu different de l'original, sans que rien ne le dise.
 *
 * ## Pourquoi une exception distincte d'`InvalidArgumentException`
 *
 * Meme partage que pour les montants de butin : une faute d'appelant se corrige et se redeploie,
 * une donnee gelee corrompue se constate et se traite. Les confondre priverait le cycle operationnel
 * de la distinction dont il a besoin.
 */
class CorruptedFrozenMoonPlan extends RuntimeException
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
            'Le plan de destruction de lune persiste est inexploitable : ' . $defect . '. Il n est pas '
            . 'converti en silence : ce plan entre dans l empreinte et les cles d idempotence, et un '
            . 'fait relu autrement qu ecrit rendrait un rejeu different de l original. Lu : '
            . self::describe($received) . '.'
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
