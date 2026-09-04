<?php

namespace OGame\Combat\Enums;

/**
 * Ce qu'une flotte doit faire, une fois le combat prononce sur son sort.
 *
 * ## Un seul cas, et c'est voulu
 *
 * Une disposition dit un **mouvement a executer**, pas un etat a constater. Le seul mouvement que le
 * combat impose aujourd'hui est le demi-tour : une flotte refusee a l'admission, ou arrivee apres la
 * fermeture, repart a son origine. Tout le reste — combattre, stationner, rentrer normalement — est
 * ce que la flotte faisait deja, et n'a pas a etre ecrit.
 *
 * Ce genre existe malgre son cas unique parce qu'il nomme la question : « que doit faire cette
 * flotte ? ». Une colonne booleenne « doit rentrer » repondrait a la meme question aujourd'hui, et
 * mentirait le jour ou une seconde reponse apparaitrait.
 *
 * `FleetDispositionHasWriterTest` verifie qu'aucun cas ne subsiste sans ecrivain : un genre que
 * personne n'ecrit est un genre que personne ne lit correctement.
 */
enum FleetDispositionKind: string
{
    /**
     * La flotte repart a son origine, avec la raison que le combat a prononcee.
     */
    case ReturnToOrigin = 'return_to_origin';
}
