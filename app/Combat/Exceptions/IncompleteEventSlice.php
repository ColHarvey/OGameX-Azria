<?php

namespace OGame\Combat\Exceptions;

use LogicException;

/**
 * Une tranche d'evenements a ete presentee sans etre declaree complete.
 *
 * Une photographie construite sur une tranche partielle est plausible et fausse : il y manque un
 * effet, et rien ne le signale. Le refus est donc immediat.
 */
class IncompleteEventSlice extends LogicException
{
}
