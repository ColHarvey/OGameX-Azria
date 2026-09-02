<?php

namespace OGame\Combat\Causality;

use InvalidArgumentException;
use OGame\Combat\Support\EffectOrderKey;

/**
 * La propriete exclusive d'une partition, jusqu'a une cle d'effet donnee.
 *
 * ## Pourquoi les verrous ne suffisent pas
 *
 * Deux workers peuvent obtenir les memes verrous dans un ordre different, et chacun se croire seul.
 * Un verrou dit « personne d'autre n'ecrit ici en ce moment » ; il ne dit pas « j'ai vu tout ce qui
 * precede ». La fermeture doit donc **posseder la partition** et avancer derriere un curseur qui
 * n'autorise que la prochaine cle d'effet.
 *
 * La barriere porte cette possession : la partition — un combat, un corps exact — et la cle jusqu'a
 * laquelle la lecture fait autorite. Un evenement dont l'effet depasse le curseur n'a pas ete vu par
 * cette lecture-la, et la tranche ne peut pas se dire complete a son sujet.
 */
final readonly class PartitionBarrier
{
    /**
     * @param int $combatInstanceId Le combat dont la fermeture detient la partition.
     * @param int $targetBodyId Le corps exact : planete et lune sont deux partitions distinctes.
     * @param EffectOrderKey $ownedThroughEffect La derniere cle d'effet que cette lecture couvre.
     */
    public function __construct(
        public int $combatInstanceId,
        public int $targetBodyId,
        public EffectOrderKey $ownedThroughEffect,
    ) {
        if ($combatInstanceId < 1 || $targetBodyId < 1) {
            throw new InvalidArgumentException(
                'Une barriere de partition designe un combat et un corps persistes : sans eux, deux fermetures '
                . 'simultanees se croiraient sur des partitions differentes.'
            );
        }
    }

    /**
     * Si cette lecture a vu cet evenement.
     *
     * Le curseur, et lui seul. L'appartenance au bon corps est verifiee **ailleurs**, par l'etat
     * d'ouverture : garder les deux controles separes laisse un evenement etranger traverser la
     * barriere pour se faire classer `NotApplicable`, ce qui vaut mieux que de le faire disparaitre
     * en silence a la frontiere.
     */
    public function hasSeen(CausalEvent $event): bool
    {
        return $event->effect->compareTo($this->ownedThroughEffect) <= 0;
    }

    /**
     * Si cet evenement appartient a la partition possedee.
     */
    public function belongsToPartition(CausalEvent $event): bool
    {
        return $event->targetBodyId === $this->targetBodyId;
    }
}
