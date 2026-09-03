<?php

namespace OGame\Combat\Exceptions;

use RuntimeException;

/**
 * Le combat, sa bataille figee et ses participants ne parlent pas de la meme chose.
 *
 * Le reglement lit tout depuis l'instance : le resultat vient de sa colonne, l'effectif de ses
 * participants. Ces trois faits ne peuvent diverger que si l'un d'eux a ete altere entre la
 * cloture et l'echeance — une ligne de participant effacee, une bataille reecrite, une mission
 * disparue. Appliquer quand meme ferait un retour a une flotte qui n'a pas combattu, ou en
 * oublierait une qui l'a fait.
 *
 * C'est un refus d'exploitation : le combat reste applicable une fois la contradiction levee.
 */
class MismatchedCombatIdentity extends RuntimeException
{
    /**
     * @param string $defect Ce qui ne concorde pas, en nommant les deux cotes.
     */
    public function __construct(public readonly string $defect)
    {
        parent::__construct(
            'Le combat et sa bataille figee ne concordent pas : ' . $defect . '. Le reglement '
            . 's arrete plutot que d appliquer un resultat qui ne decrit pas les flottes inscrites.'
        );
    }
}
