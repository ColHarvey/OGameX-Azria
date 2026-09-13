<?php

namespace OGame\Combat\Exceptions;

use RuntimeException;

/**
 * Un combat dont la regle de composition des unites manque ou n est pas connue.
 *
 * La cloture ne compose une bataille que sous la regle ecrite avec le combat. Supposer l une ou l autre
 * ferait tirer des flottes avec des caracteristiques qu aucune regle ne leur a donnees.
 */
final class UnknownUnitCharacteristicsRule extends RuntimeException
{
}
