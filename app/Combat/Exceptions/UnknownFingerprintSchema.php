<?php

namespace OGame\Combat\Exceptions;

use RuntimeException;

/**
 * Un contexte relu porte une version de schema d'empreinte que ce code ne connait pas.
 *
 * **Distincte de la version de regle, parce que ce sont deux questions differentes.** Une regle
 * inconnue veut dire : je ne sais pas quel taux appliquer. Un schema inconnu veut dire : je ne sais
 * pas quels faits ont ete photographies, ni dans quel ordre — donc je ne peux ni verifier
 * l'empreinte, ni affirmer que la composition est la bonne.
 *
 * Les confondre ferait chercher au mauvais endroit : on soupconnerait la formule alors que c'est le
 * format qui a change.
 */
class UnknownFingerprintSchema extends RuntimeException
{
    /**
     * @param int $found
     * @param int $supported
     * @return self
     */
    public static function because(int $found, int $supported): self
    {
        return new self(
            'Le contexte a ete photographie sous le schema d empreinte ' . $found . ', et ce code connait le '
            . 'schema ' . $supported . '. Les faits ne sont pas comparables : leur composition ou leur ordre a '
            . 'change entre les deux.'
        );
    }
}
