<?php

namespace OGame\Combat\Exceptions;

use RuntimeException;

/**
 * Un combat se reclame d'une regle de taux que le registre ne sait plus appliquer.
 *
 * **Aucun repli n'est possible.** Recalculer avec la regle courante changerait le taux d'un combat
 * deja engage, en silence. Le refus signale qu'une implementation a ete retiree alors que des
 * combats s'en reclamaient encore — une decision d'exploitation, pas un accident a rattraper.
 */
class UnknownLootPolicyVersion extends RuntimeException
{
    /**
     * @param string $version
     * @param array<int, string> $known
     * @return self
     */
    public static function because(string $version, array $known): self
    {
        return new self(
            'La regle de taux « ' . $version . ' » est inconnue du registre. Versions reconnues : '
            . (count($known) > 0 ? implode(', ', $known) : 'aucune')
            . '. Un combat calcule sous une regle ne peut pas etre relu sous une autre.'
        );
    }
}
