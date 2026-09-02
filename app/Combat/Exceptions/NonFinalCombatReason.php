<?php

namespace OGame\Combat\Exceptions;

use LogicException;

/**
 * On a demande sa raison finale a une decision qui n'en a pas encore.
 *
 * Une decision qui delegue — a un selecteur d'admission, a l'ordre des evenements — porte une
 * **exigence**, pas une raison. Une decision qui constate une contradiction porte un **code
 * d'invariant**. Ni l'un ni l'autre n'est destine a un joueur.
 *
 * C'est ici que se ferme la regle « aucun code d'attente ne survit dans un
 * `FinalArrivalResolution` » : la question ne rend pas un code plausible, elle echoue.
 */
class NonFinalCombatReason extends LogicException
{
}
