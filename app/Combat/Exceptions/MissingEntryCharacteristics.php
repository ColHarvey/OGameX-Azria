<?php

namespace OGame\Combat\Exceptions;

use RuntimeException;

/**
 * Un participant d un combat gele a l entree dont les caracteristiques ne sont pas inscrites.
 *
 * La cloture inscrit tout participant admis qui n a pas ete vu a son arrivee : a la composition de la
 * bataille, une ligne manquante n est donc pas un retard, c est une contradiction. Relire le joueur
 * vivant a sa place ferait tirer la flotte avec des niveaux acquis apres son entree — exactement ce que
 * le gel existe pour empecher.
 */
final class MissingEntryCharacteristics extends RuntimeException
{
}
