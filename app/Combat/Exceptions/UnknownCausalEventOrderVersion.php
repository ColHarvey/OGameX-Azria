<?php

namespace OGame\Combat\Exceptions;

use RuntimeException;

/**
 * Un combat se reclame d'un ordre causal que le registre ne connait pas.
 *
 * Le refus est explicite : retomber sur la version courante reordonnerait les effets simultanes
 * d'un combat deja ouvert. Une defense achevee cesserait d'etre detruite par le missile de la meme
 * seconde, ou l'inverse — sans que personne ne l'ait demande.
 */
class UnknownCausalEventOrderVersion extends RuntimeException
{
}
