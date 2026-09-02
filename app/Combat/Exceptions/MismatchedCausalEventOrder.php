<?php

namespace OGame\Combat\Exceptions;

use LogicException;

/**
 * Deux cles d'effet construites sous des versions d'ordre differentes ont ete comparees.
 *
 * Les rangs de genre ne veulent pas dire la meme chose d'une version a l'autre : comparer un rang 2
 * de v1 a un rang 2 de v2 revient a comparer un chantier a un missile en croyant les mesurer sur la
 * meme echelle. Le resultat serait plausible et faux.
 *
 * Un combat ouvert sous une version se rejoue sous cette version-la, entierement.
 */
class MismatchedCausalEventOrder extends LogicException
{
}
