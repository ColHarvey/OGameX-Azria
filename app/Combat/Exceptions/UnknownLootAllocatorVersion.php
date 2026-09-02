<?php

namespace OGame\Combat\Exceptions;

use RuntimeException;

/**
 * Un combat se reclame d'une regle de pillage que le registre ne sait plus appliquer.
 *
 * **Aucun repli n'est possible.** Appliquer la version courante a un combat calcule sous une autre
 * changerait son resultat, en silence, longtemps apres que les joueurs se soient engages. Le refus
 * est donc la seule reponse honnete : il signale qu'une implementation a ete retiree alors que des
 * combats s'en reclamaient encore.
 */
class UnknownLootAllocatorVersion extends RuntimeException
{
    /**
     * @param string $version
     * @param array<int, string> $known
     * @return self
     */
    public static function because(string $version, array $known): self
    {
        return new self(
            'La regle de pillage « ' . $version . ' » est inconnue du registre. Versions reconnues : '
            . (count($known) > 0 ? implode(', ', $known) : 'aucune')
            . '. Un combat calcule sous une regle ne peut pas etre rejoue sous une autre.'
        );
    }
}
