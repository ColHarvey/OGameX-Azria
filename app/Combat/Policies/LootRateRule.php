<?php

namespace OGame\Combat\Policies;

use OGame\Combat\Support\LootPolicy;

/**
 * Une regle de taux de pillage, identifiee par sa version.
 *
 * ## Pourquoi une interface plutot qu'une methode de plus
 *
 * La version persistee avec un combat ne vaut que si l'implementation qui l'a produite reste
 * disponible. Une formule ecrite en dur dans une classe unique ne peut pas cohabiter avec la
 * suivante : le jour ou elle change, tous les combats deja calcules changent avec elle, sans que
 * leur version ne bouge.
 *
 * Chaque regle vit donc dans sa propre classe, avec ses propres constantes, et le registre choisit
 * celle que le combat reclame.
 */
interface LootRateRule
{
    /**
     * L'identifiant persiste avec chaque combat.
     *
     * @return string
     */
    public function version(): string;

    /**
     * Le taux applicable a ces faits, en centiemes de pour-cent.
     *
     * @param LootPolicy $facts Les faits photographies : inactivite, fret engage, honneur.
     * @return int
     */
    public function rateInBasisPoints(LootPolicy $facts): int;
}
