<?php

namespace OGame\Combat\Causality;

use InvalidArgumentException;

/**
 * Quand l'engagement est devenu irrevocable.
 *
 * ## Le seul role de cet ordre
 *
 * Il decide **si** un engagement appartient a la photographie. Il ne decide jamais dans quel ordre
 * les effets sont appliques : c'est `EffectOrderKey` qui s'en charge, et melanger les deux est
 * l'erreur que ces deux classes existent pour rendre impossible.
 *
 * Un transport decide hier et arrivant dans dix secondes, et un transport decide dans une minute et
 * arrivant dans une heure, ne se classent pas dans le meme ordre selon qu'on regarde la decision ou
 * l'effet. Trier les effets par ordre de decision donnerait un resultat plausible et faux.
 *
 * ## Pourquoi un depart en cas d'egalite
 *
 * Deux engagements peuvent devenir irrevocables dans la meme seconde. Le depart doit alors venir
 * d'une valeur persistee et stable — jamais de l'ordre ou la base a rendu les lignes, ni de l'heure
 * a laquelle un worker s'est reveille.
 */
final readonly class DecisionOrder
{
    /**
     * @param int $decidedAt L'instant ou l'engagement est devenu irrevocable, en secondes.
     * @param int $tieBreaker Un depart stable et persiste, en cas d'egalite.
     */
    public function __construct(
        public int $decidedAt,
        public int $tieBreaker,
    ) {
        if ($tieBreaker < 1) {
            throw new InvalidArgumentException(
                'Un depart d egalite doit venir d une valeur persistee et strictement positive : sans lui, '
                . 'deux engagements de la meme seconde se classeraient selon l ordre de lecture de la base.'
            );
        }
    }

    /**
     * Si l'engagement etait irrevocable **strictement avant** cet instant.
     *
     * **L'egalite compte pour « apres ».** Un engagement pris a la seconde exacte de l'ouverture
     * n'etait pas anterieur a elle ; l'admettre ferait dependre son sort d'une course.
     *
     * @param int $instant
     * @return bool
     */
    public function isStrictlyBefore(int $instant): bool
    {
        return $this->decidedAt < $instant;
    }

    /**
     * La comparaison entre deux ordres de decision.
     *
     * @param self $other
     * @return int
     */
    public function compareTo(self $other): int
    {
        return [$this->decidedAt, $this->tieBreaker] <=> [$other->decidedAt, $other->tieBreaker];
    }
}
