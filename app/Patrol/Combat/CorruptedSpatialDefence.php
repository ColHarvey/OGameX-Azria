<?php

namespace OGame\Patrol\Combat;

use RuntimeException;

/**
 * Un document de defense spatiale que la relecture refuse.
 *
 * Refuser plutot que completer : une photographie a moitie lisible n est pas une photographie,
 * et jouer une bataille sur un effectif reconstitue au jugé donnerait un resultat que personne
 * n a decide. Le refus arrete le reglement ; l exploitation reprend ou annule le combat par les
 * commandes qui existent deja.
 */
final class CorruptedSpatialDefence extends RuntimeException
{
}
