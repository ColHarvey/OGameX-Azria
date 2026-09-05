<?php

namespace OGame\Combat\Exceptions;

use RuntimeException;

/**
 * Un combat dont l'etat d'ouverture manque ou ne porte plus son empreinte.
 *
 * La fermeture ne construit sa photographie que depuis cet etat. Le relire dans le monde vivant a sa
 * place donnerait une photographie plausible et fausse — c'est exactement ce que le raccordement
 * causal interdit. Le refus est explicite, et il arrete la fermeture.
 */
final class MissingOpeningState extends RuntimeException
{
}
