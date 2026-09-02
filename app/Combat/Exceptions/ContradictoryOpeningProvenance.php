<?php

namespace OGame\Combat\Exceptions;

use LogicException;

/**
 * La provenance de l'etat d'ouverture se contredit avec l'evenement relu.
 *
 * Savoir que « mission:42 a deja ete appliquee » ne suffit pas : il faut savoir **quel** effet de
 * mission:42 est present. Quand le recu et la relecture ne decrivent pas le meme effet, le meme
 * genre, le meme corps ou une chronologie possible, ce n'est pas une issue a trancher mais un
 * defaut. L'admettre en silence ferait passer une corruption pour une regle de jeu.
 */
class ContradictoryOpeningProvenance extends LogicException
{
}
