<?php

namespace OGame\Combat\Exceptions;

use RuntimeException;

/**
 * Un combat porte une regle de manoeuvre de Hamill que ce code ne sait pas lire.
 *
 * Une regle absente ou inconnue ne se remplace pas par la regle courante : la bataille d un combat ouvert
 * sous une autre regle en serait changee, et personne ne saurait dire laquelle a ete jouee.
 */
final class UnknownHamillManoeuvreRule extends RuntimeException
{
}
