<?php

namespace OGame\Combat\Support;

use InvalidArgumentException;

/**
 * L'identite d'un evenement, telle qu'un recu et une inclusion la citent.
 *
 * ## Pourquoi une fabrique, et non une chaine ecrite sur place
 *
 * Meme motif que `CombatParticipantKey`, et le meme incident : des identites d'evenement avaient ete
 * ecrites a la main sous la forme `fleet:4242:arrival`, c'est-a-dire avec le prefixe d'une **cle de
 * participant**. Les deux vivent dans des tables differentes, mais elles se ressemblaient assez pour
 * qu'une requete de diagnostic les melange, et assez peu pour que personne ne le remarque avant que
 * la suite complete ne le voie.
 *
 * Une identite d'evenement commence donc par `event:`, et rien d'autre dans le depot ne commence
 * ainsi.
 *
 * ## Ce qu'une identite doit garantir
 *
 * **Le meme evenement rend toujours la meme chaine**, et deux evenements differents n'en partagent
 * jamais une. C'est de cela que dependent les deux idempotences : celle du monde, qui refuse
 * d'appliquer deux fois le meme effet, et celle de la photographie, qui refuse d'inclure deux fois
 * le meme evenement dans un combat.
 *
 * Une identite construite a partir d'un fait non persiste — un objet en memoire, un compteur de
 * boucle — ne tiendrait aucune des deux : au rejeu, elle serait differente.
 */
final class CombatEventIdentity
{
    /**
     * Ce qui distingue une identite d'evenement d'une cle de participant.
     */
    public const string PREFIX = 'event';

    /**
     * L'arrivee d'une flotte, identifiee par sa mission.
     *
     * La mission est ce qui distingue cette arrivee de toutes les autres, y compris des autres
     * flottes du meme joueur dans la meme union.
     */
    public static function forFleetArrival(int $fleetMissionId): string
    {
        return self::build('arrival', $fleetMissionId);
    }

    /**
     * Une identite bien formee, ou rien.
     */
    private static function build(string $kind, int $identifier): string
    {
        if ($identifier < 1) {
            throw new InvalidArgumentException(
                'Une identite d evenement se construit sur un identifiant persiste. Sans lui, elle '
                . 'serait differente au rejeu, et les deux idempotences — celle du monde et celle de '
                . 'la photographie — cesseraient de reconnaitre ce qu elles ont deja vu.'
            );
        }

        return self::PREFIX . ':' . $kind . ':' . $identifier;
    }
}
