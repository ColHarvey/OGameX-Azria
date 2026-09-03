<?php

namespace OGame\Combat\Exceptions;

use RuntimeException;

/**
 * Les parts de butin reparties ne font pas la somme du butin applique.
 *
 * ## Pourquoi c'est une contradiction, et non un plafonnement
 *
 * L'allocateur plafonne chaque part par la place restante de sa flotte : si les soutes ne suffisent
 * pas, la somme des parts est plus petite que le montant demande. Ce comportement est juste pour le
 * **potentiel** — le moteur ramene alors le butin a ce qui tient, et l'excedent reste sur la cible.
 *
 * Mais le butin **applique** est, par construction, au plus egal au potentiel, lui-meme deja ramene
 * a ce que les soutes portent. Une somme des parts inferieure a l'applique ne peut donc pas venir
 * d'un manque de place : elle vient de faits geles qui ne se correspondent plus — des capacites
 * d'un autre resultat, un allocateur d'une autre version, un montant qui n'a pas ete plafonne.
 *
 * Continuer distribuerait moins que ce qui a ete debite au defenseur, et la difference n'irait a
 * personne. Il n'y a pas de branche juste : le reglement s'arrete.
 */
class ContradictoryLootShares extends RuntimeException
{
    /**
     * @param string $component La ressource concernee.
     * @param int $applied Ce qui devait etre reparti.
     * @param int $shared Ce que les parts totalisent.
     */
    public function __construct(
        public readonly string $component,
        public readonly int $applied,
        public readonly int $shared,
    ) {
        parent::__construct(
            'La repartition du ' . $component . ' totalise ' . $shared . ' unites pour ' . $applied
            . ' appliquees. L applique ne depasse jamais ce que les soutes portent : cet ecart ne '
            . 'vient pas d un manque de place mais de faits geles qui ne se correspondent plus. '
            . 'Continuer debiterait au defenseur ce que personne ne recevrait.'
        );
    }
}
