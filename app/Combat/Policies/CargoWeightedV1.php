<?php

namespace OGame\Combat\Policies;

use OGame\Combat\Support\ExactRatio;
use OGame\Combat\Support\LootPolicy;

/**
 * Le taux de pillage d'un combat entre joueurs, pondere par le fret engage.
 *
 *     cible active   : 50 %
 *     cible inactive : 50 % + 25 % x (fret Decouvreur engage / fret total engage)
 *
 * Une regle propre a ce serveur, et assumee comme telle. Elle recompense la classe **a proportion
 * de ce qu'elle engage** :
 *
 *     aucun Decouvreur          50 %
 *     que des Decouvreurs       75 %
 *     fret partage en deux      62,5 %
 *     un dixieme du fret        52,5 %
 *     une sonde dans une armada  influence quasi nulle
 *
 * ## Le defaut qu'elle corrige
 *
 * Le moteur lisait `$this->attackers[0]` et appliquait le bonus de **ce joueur-la** a toute
 * l'attaque groupee. Le taux dependait donc de l'ordre de la collection : une simple sonde
 * appartenant a un Decouvreur, arrivee en tete, faisait passer tout le butin de 50 % a 75 %.
 *
 * Ici, permuter les participants ne peut rien changer : le taux ne depend que de deux sommes, et
 * une somme est commutative.
 *
 * ## Pourquoi les constantes vivent ici
 *
 * Elles font partie de la formule. Logees dans une classe partagee, la version suivante les
 * heriterait ou les modifierait, et le taux d'un ancien combat changerait sans que sa version
 * bouge. Une version doit designer une formule complete, constantes comprises.
 */
final class CargoWeightedV1 implements LootRateRule
{
    /**
     * L'identifiant persiste avec chaque combat.
     */
    public const string VERSION = 'cargo_weighted_v1';

    /**
     * Taux de base, en points de base. Cinquante pour cent.
     */
    public const int BASE_RATE = 5_000;

    /**
     * Ce que le bonus Decouvreur ajoute au maximum, en points de base.
     */
    public const int DISCOVERER_BONUS = 2_500;

    /**
     * Cent pour cent, en points de base.
     */
    public const int FULL_RATE = 10_000;

    /**
     * @inheritDoc
     */
    public function version(): string
    {
        return self::VERSION;
    }

    /**
     * @inheritDoc
     */
    public function rateInBasisPoints(LootPolicy $facts): int
    {
        // Par maximum, jamais par addition : un bandit attaque par un Decouvreur resterait a 100 %,
        // pas a 175 %.
        return max($this->classRate($facts), $facts->honor->minimumRateInBasisPoints());
    }

    /**
     * Le taux du a la classe des attaquants.
     *
     * @param LootPolicy $facts
     * @return int
     */
    private function classRate(LootPolicy $facts): int
    {
        if (!$facts->targetIsInactive || $facts->cargo->isEmpty()) {
            // Cible active : pas de bonus. Aucun fret engage : rien a piller de toute facon, et
            // surtout aucune division a tenter.
            return self::BASE_RATE;
        }

        // **Arrondi vers le bas, au centieme de pour-cent**, et c'est une regle, pas un accident :
        // la borne reservee et le butin reel doivent partager exactement le meme arrondi, sans quoi
        // un ecart d'un point de base ferait echouer le reglage.
        //
        // Le calcul passe par `ExactRatio` et non par `intdiv(2500 * $fret, $total)` : au-dela de
        // trois mille sept cents milliards, ce produit est promu en flottant par PHP, et deux ordres
        // d'addition differents donneraient alors deux taux differents.
        $bonus = ExactRatio::floorOfProductOverDivisor(
            self::DISCOVERER_BONUS,
            $facts->cargo->discovererCargo,
            $facts->cargo->totalCargo,
        );

        return self::BASE_RATE + $bonus;
    }
}
