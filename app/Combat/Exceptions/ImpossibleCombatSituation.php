<?php

namespace OGame\Combat\Exceptions;

use LogicException;

/**
 * Une situation qui ne peut pas se produire a ete construite a partir de donnees reelles.
 *
 * Un missile n'a pas d'etape de retour ; une expedition ne rencontre pas l'etat de combat d'un
 * corps celeste. La matrice les classe `StructurallyNotApplicable` quand elle enumere ses cases,
 * ce qui est une facon de dire « cette case n'existe pas ». Mais si un chemin **vivant** en
 * fabrique une, ce n'est plus une case a classer : c'est que les donnees se contredisent.
 *
 * Laisser passer une telle entree la ferait traiter comme une arrivee ordinaire, et le defaut qui
 * l'a produite resterait invisible.
 */
class ImpossibleCombatSituation extends LogicException
{
}
