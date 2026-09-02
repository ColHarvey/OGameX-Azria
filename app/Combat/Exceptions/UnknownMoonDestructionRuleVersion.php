<?php

namespace OGame\Combat\Exceptions;

use RuntimeException;

/**
 * Un plan de destruction se reclame d'une regle que le registre ne connait pas.
 *
 * Le refus est explicite, et c'est deliberé : retomber sur la regle courante appliquerait a un plan
 * calcule sous une regle une autre regle que la sienne, et changerait ses chances sans que personne
 * ne l'ait demande.
 */
class UnknownMoonDestructionRuleVersion extends RuntimeException
{
}
