<?php

namespace OGame\Military\Exceptions;

use RuntimeException;

/**
 * Une unité qu'aucune famille militaire ne sait pondérer.
 *
 * Elle n'arrête jamais un règlement de bataille : l'appelant la prévoit, met l'événement **en attente** avec ses
 * unités, et ne crédite rien. Inventer un poids écrirait un score faux dans un classement public.
 */
final class UnknownMilitaryUnit extends RuntimeException
{
    public function __construct(public readonly string $machineName)
    {
        parent::__construct('No military weighting family knows the unit ' . $machineName . '.');
    }
}
