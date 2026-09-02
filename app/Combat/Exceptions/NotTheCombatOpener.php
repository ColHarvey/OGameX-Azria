<?php

namespace OGame\Combat\Exceptions;

use OGame\Combat\Enums\CombatMissionKind;
use RuntimeException;

/**
 * Une mission s'est presentee comme l'initiatrice d'un combat sans l'etre.
 *
 * **Refuser bruyamment est ici le comportement sur.** Laisser passer donnerait a une flotte
 * ordinaire les privileges de l'initiateur : franchir le verrou causal, echapper aux limites du
 * camp, entrer dans la photographie sans condition. Un tel ecart ne se verrait qu'au resultat du
 * combat, quand il serait trop tard.
 *
 * Chaque cause a sa fabrique, pour que le journal dise **laquelle** des identites ne correspondait
 * pas. Un message generique obligerait a rejouer la situation pour comprendre.
 */
class NotTheCombatOpener extends RuntimeException
{
    /**
     * L'evenement n'est pas celui enregistre comme initiateur.
     *
     * Le cas d'une seconde arrivee qui se declarerait initiatrice, ou d'un payload perime.
     */
    public static function becauseTheEventIsNotTheRecordedOpener(int $combatInstanceId): self
    {
        return new self(
            'Cet evenement n est pas l initiateur enregistre du combat ' . $combatInstanceId . '. '
            . 'Une seule mission ouvre un combat, et c est la base qui le dit — jamais le payload d une tache.'
        );
    }

    /**
     * La mission vise un autre corps celeste que celui verrouille.
     *
     * Une planete et sa lune partagent leurs coordonnees mais sont deux cibles distinctes.
     */
    public static function becauseTheTargetDiffers(string $claimed, string $locked): self
    {
        return new self(
            'La mission vise « ' . $claimed .' » alors que le combat verrouille « ' . $locked . ' ». '
            . 'Une planete et sa lune sont deux cibles differentes.'
        );
    }

    /**
     * Une flotte qui rentre chez elle n'ouvre pas de bataille.
     */
    public static function becauseAReturnNeverOpensACombat(): self
    {
        return new self('Une flotte sur sa branche retour n ouvre jamais un combat : elle rentre chez elle.');
    }

    /**
     * Ce genre de mission n'ouvre pas de combat.
     *
     * Transport, recyclage, missile, Defense ACS : aucune ne dispute la possession du corps
     * celeste.
     */
    public static function becauseThisMissionKindCannotOpenACombat(CombatMissionKind $kind): self
    {
        return new self(
            'Une mission de type « ' . $kind->value . ' » ne peut pas ouvrir un combat : '
            . 'elle ne dispute pas la possession du corps celeste.'
        );
    }

    /**
     * L'heure planifiee ne correspond pas a celle enregistree.
     *
     * Signe d'un evenement perime, ou d'une mission modifiee depuis l'ouverture.
     */
    public static function becauseTheArrivalTimeDiffers(int $claimed, int $recorded): self
    {
        return new self(
            'L heure d arrivee relue (' . $claimed . ') ne correspond pas a celle enregistree a l ouverture ('
            . $recorded . '). L evenement est perime, ou la mission a change depuis.'
        );
    }
}
