<?php

namespace OGame\Exceptions;

use Exception;
use OGame\Enums\UnionRefusalReason;

/**
 * Une flotte n'a pas pu fonder ou rejoindre une union, et voici pourquoi — typé.
 *
 * Le message reste celui que le joueur lit, derive de la raison : les appelants qui attrapent
 * `Exception` et affichent `getMessage()` ne changent pas. Ceux qui veulent savoir — le journal, un
 * essai qui rougit — lisent la raison.
 */
class UnionRefused extends Exception
{
    public function __construct(public readonly UnionRefusalReason $reason)
    {
        $message = __($reason->translationKey());

        parent::__construct(is_string($message) ? $message : $reason->translationKey());
    }
}
