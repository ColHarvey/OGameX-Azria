<?php

namespace OGame\Combat\Support;

use OGame\Combat\Enums\HonorPolicy;

/**
 * Le taux de pillage d'un combat : combien de ce qui est en caisse peut etre emporte.
 *
 * ## La regle retenue
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
 * Le moteur actuel lit `$this->attackers[0]` et applique le bonus de **ce joueur-la** a toute
 * l'attaque groupee. Le taux depend donc de l'ordre de la collection : une simple sonde
 * appartenant a un Decouvreur, arrivee en tete, faisait passer tout le butin de 50 % a 75 %.
 *
 * Ici, permuter les participants ne peut rien changer : le taux ne depend que de deux sommes, et
 * une somme est commutative.
 *
 * ## Pourquoi des points de base
 *
 * Le calcul se fait en entiers, en centiemes de pour-cent. Des flottants donneraient des ecarts
 * d'arrondi selon l'ordre des operations — precisement ce que cette regle doit exclure — et la
 * borne reservee doit se calculer **exactement** comme le butin reel, sans quoi un ecart d'un
 * milliemme ferait echouer le reglage.
 *
 * ## Les acteurs pilotes par le serveur
 *
 * Un pirate n'a pas de classe de joueur, et ne doit surtout pas etre traite comme un Decouvreur
 * par defaut. Il releve d'une politique explicite — voir `forNpcAttacker()` — qui conserve le
 * comportement actuel.
 */
final readonly class LootPolicy
{
    /**
     * La version de cette regle, persistee avec chaque combat.
     *
     * Changer la formule plus tard ne doit toucher que les combats suivants : un combat ouvert
     * garde la version sous laquelle il a commence.
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
     * @param bool $targetIsInactive L'inactivite de la cible, **figee a l'ouverture**. Un
     *                               changement pendant les deux heures de combat ne doit rien
     *                               modifier retroactivement.
     * @param AttackerCargoShare $cargo Le fret offensif engage et la part des Decouvreurs.
     * @param HonorPolicy $honor L'etat du systeme d'honneur. Desactive aujourd'hui.
     */
    public function __construct(
        public bool $targetIsInactive,
        public AttackerCargoShare $cargo,
        public HonorPolicy $honor = HonorPolicy::Disabled,
    ) {
    }

    /**
     * La politique d'un attaquant pilote par le serveur.
     *
     * Un pirate n'a pas de classe : aucun bonus, quel que soit l'etat de la cible. Nommer ce cas
     * evite qu'un compte systeme herite silencieusement d'un comportement de joueur.
     *
     * @param bool $targetIsInactive
     * @return self
     */
    public static function forNpcAttacker(bool $targetIsInactive): self
    {
        return new self($targetIsInactive, AttackerCargoShare::none());
    }

    /**
     * Le taux maximal legalement pillable, en points de base.
     *
     * **Sans jamais consulter l'issue du combat.** Ni vainqueur, ni survivants, ni pertes, ni
     * capacite de fret survivante n'entrent ici : c'est ce qui empeche le solde disponible du
     * defenseur de reveler le resultat avant le rapport.
     *
     * @return int
     */
    public function maximumRateInBasisPoints(): int
    {
        $classe = $this->classRateInBasisPoints();

        // Par maximum, jamais par addition : un bandit attaque par un Decouvreur resterait a
        // 100 %, pas a 175 %.
        return max($classe, $this->honor->minimumRateInBasisPoints());
    }

    /**
     * Le taux du a la classe des attaquants, en points de base.
     *
     * @return int
     */
    private function classRateInBasisPoints(): int
    {
        if (!$this->targetIsInactive || $this->cargo->isEmpty()) {
            // Cible active : pas de bonus. Aucun fret engage : rien a piller de toute facon, et
            // surtout aucune division a tenter. Le taux de base est conserve, sans effet pratique.
            return self::BASE_RATE;
        }

        // **Arrondi vers le bas, au centieme de pour-cent**, et c'est une regle de
        // `cargo_weighted_v1`, pas un accident : la borne reservee et le butin reel doivent
        // partager exactement le meme arrondi, sans quoi un ecart d'un point de base ferait
        // echouer le reglage.
        //
        // Le calcul passe par `ExactRatio` et non par `intdiv(2500 * $fret, $total)` : au-dela de
        // trois mille sept cents milliards, ce produit est promu en flottant par PHP, et deux
        // ordres d'addition differents donneraient alors deux taux differents — exactement ce que
        // la ponderation par le fret devait rendre impossible.
        $bonus = ExactRatio::floorOfProductOverDivisor(
            self::DISCOVERER_BONUS,
            $this->cargo->discovererCargo,
            $this->cargo->totalCargo,
        );

        return self::BASE_RATE + $bonus;
    }
}
