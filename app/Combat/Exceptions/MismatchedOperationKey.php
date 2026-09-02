<?php

namespace OGame\Combat\Exceptions;

use OGame\Combat\Support\OperationKey;
use RuntimeException;

/**
 * Deux lots de diagnostics venus d'operations differentes ont failli fusionner.
 *
 * `phase|sujet|ressource` n'est unique qu'a l'interieur d'une resolution : `attacker_reaper||metal`
 * designe le meme incident dans deux combats differents. Fusionner deux operations confondrait donc
 * des incidents sans rapport, et le journal en perdrait la moitie.
 *
 * **La protection est structurelle, pas conventionnelle.** Chaque source est scellee sous sa cle
 * avant toute fusion ; fusionner d'abord puis sceller aurait deja detruit l'information qui permet
 * de detecter l'erreur.
 */
class MismatchedOperationKey extends RuntimeException
{
    /**
     * @param OperationKey $expected
     * @param OperationKey $found
     * @return self
     */
    public static function because(OperationKey $expected, OperationKey $found): self
    {
        return new self(
            'Fusion refusee entre les operations « ' . $expected->asString() . ' » et « ' . $found->asString()
            . ' » : leurs identites locales se recouvrent, et les melanger confondrait des incidents sans '
            . 'rapport.'
        );
    }
}
