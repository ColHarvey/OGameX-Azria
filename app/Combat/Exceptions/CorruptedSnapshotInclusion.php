<?php

namespace OGame\Combat\Exceptions;

use RuntimeException;

/**
 * Une inclusion de photographie n'a pas la forme qu'on lui avait donnee.
 *
 * ## Pourquoi lever, et non normaliser

 * Meme motif que la photographie d'alliance et l'ensemble de versions : corriger en silence
 * transforme une corruption de persistance en **decision de jeu**. Une contribution effacee, c'est
 * une flotte qui ne se bat pas ; une contribution ajoutee, ce sont des vaisseaux qui n'existent pas.
 *
 * Un ordre non canonique merite le meme refus, pour une raison moins evidente : deux ecritures du
 * meme fait produiraient deux JSON differents, et le rejeu ne reconnaitrait plus ce qu'il a deja
 * inscrit.
 */
class CorruptedSnapshotInclusion extends RuntimeException
{
    /**
     * @param string $defect Ce qui n'allait pas, en clair.
     * @param mixed $received Ce qui a ete lu, pour l'enquete.
     */
    public function __construct(
        public readonly string $defect,
        public readonly mixed $received = null,
    ) {
        parent::__construct(
            'L inclusion de photographie est inexploitable : ' . $defect . '. Elle n est pas '
            . 'normalisee en silence : une contribution effacee est une flotte qui ne se bat pas, et '
            . 'une contribution ajoutee des vaisseaux qui n existent pas. Lu : '
            . self::describe($received) . '.'
        );
    }

    /**
     * Ce qui a ete lu, sous une forme lisible dans un journal.
     */
    private static function describe(mixed $received): string
    {
        if ($received === null) {
            return 'null';
        }

        $rendu = json_encode($received);

        return $rendu === false ? gettype($received) : $rendu;
    }
}
