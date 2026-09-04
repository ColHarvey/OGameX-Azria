<?php

namespace OGame\Combat\Exceptions;

use RuntimeException;

/**
 * L'avis de refus deja ecrit pour cette flotte ne dit pas ce que la decision dit.
 *
 * ## Pourquoi l'avis ne peut pas avoir sa propre verite
 *
 * La fermeture ecrit l'avis d'une refusee ; le renvoi, plus tard, l'ecrit aussi. Deux ecrivains
 * pour une meme ligne, et un `firstOrCreate()` entre les deux : « la premiere ligne a raison »
 * reapparaissait dans la boite d'envoi apres avoir ete retire du registre des dispositions.
 *
 * L'avis **raconte** la disposition ; il ne la remplace pas. Les deux ecrivains derivent donc le
 * meme contenu canonique de la meme decision, et une ligne existante qui en differe arrete la
 * transaction plutot que d'etre gardee en silence.
 */
class ContradictoryRefusalNotice extends RuntimeException
{
    public function __construct(
        public readonly int $fleetMissionId,
        public readonly string $champ,
        public readonly string $inscrit,
        public readonly string $derive,
    ) {
        parent::__construct(
            'L avis de refus de la flotte ' . $fleetMissionId . ' porte deja ' . $champ . ' = « ' . $inscrit
            . ' », et la decision donne « ' . $derive . ' » : l avis ne raconte plus la disposition, rien n est ecrit.'
        );
    }
}
