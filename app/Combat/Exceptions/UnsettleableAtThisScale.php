<?php

namespace OGame\Combat\Exceptions;

use RuntimeException;

/**
 * Les montants de ce combat depassent ce que le stockage sait rendre a l'unite pres.
 *
 * ## Pourquoi c'est un refus, et non une approximation
 *
 * Les soldes des corps celestes et les cargaisons des missions sont stockes en **colonnes
 * flottantes** — une decision du depot amont, prise pour accepter de tres grandes fortunes. Un
 * flottant double distingue chaque entier jusqu'a 2^53 ; au-dela, deux montants voisins deviennent
 * le meme nombre.
 *
 * Le combat durable promet une comptabilite exacte : ce qui est debite a la cible est exactement ce
 * qui est embarque, exactement ce que le rapport raconte. Au-dela de 2^53, **aucune ecriture ne
 * peut tenir cette promesse** : ni un vecteur entier interne, ni un plan de repartition, parce que
 * la perte survient dans la colonne elle-meme. Distribuer « a peu pres » l'ecart reellement stocke
 * serait rendre a l'un ce qu'on prend a l'autre sans le dire.
 *
 * Le reglement s'arrete donc, et le combat part en quarantaine avec cette raison. C'est une sortie
 * d'exploitation : le combat reste applicable le jour ou le stockage saura porter ces montants.
 *
 * La frontiere de conversion a deja constate la degradation et l'a dite — c'est ce diagnostic-la
 * qui declenche ce refus, pas une nouvelle mesure.
 */
class UnsettleableAtThisScale extends RuntimeException
{
    public function __construct(public readonly int $combatInstanceId, public readonly string $where)
    {
        parent::__construct(
            'Le combat ' . $combatInstanceId . ' porte des montants que le stockage ne distingue plus '
            . 'a l unite pres (' . $where . '). Les soldes et les cargaisons vivent en colonnes '
            . 'flottantes : au-dela de deux puissance cinquante-trois, debiter exactement ce qui est '
            . 'embarque n est pas possible, et approcher reviendrait a prendre a l un ce qu on rend a '
            . 'l autre sans le dire. Le reglement s arrete plutot que de promettre une exactitude qu il '
            . 'ne peut pas tenir.'
        );
    }
}
