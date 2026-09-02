<?php

namespace OGame\Combat\Exceptions;

use LogicException;

/**
 * Deux lectures du meme evenement portent des contenus differents.
 *
 * Choisir l'une des deux serait arbitraire, et la photographie dependrait de celle qu'on a gardee.
 * Le desaccord revele que quelque chose a change entre deux lectures qui se croyaient equivalentes.
 */
class ContradictoryCausalEvent extends LogicException
{
}
