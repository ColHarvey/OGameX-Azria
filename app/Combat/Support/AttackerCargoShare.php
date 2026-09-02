<?php

namespace OGame\Combat\Support;

use InvalidArgumentException;

/**
 * Le fret offensif engage dans un combat, et la part qui revient a des Decouvreurs.
 *
 * **Deux totaux, pas une liste de participants.** Le taux de pillage ne depend que de la
 * proportion, et la reduire a deux nombres rend l'ordre des participants structurellement
 * incapable d'influer sur le resultat : une somme est commutative. C'etait l'exigence la plus
 * importante de cette regle.
 *
 * ## Ce que « fret » designe ici
 *
 * La capacite de fret **libre avant le combat** :
 *
 * - des seules flottes attaquantes retenues dans la photographie, initiateur compris ;
 * - deduction faite de ce qu'elles transportent deja ;
 * - avant les pertes — les survivants ne servent qu'a determiner ce qui sera reellement rapporte ;
 * - candidates exclues ou rappelees ignorees ;
 * - transports, retours et Defenses ACS ignores : ils n'attaquent pas.
 */
final readonly class AttackerCargoShare
{
    /**
     * @param int $discovererCargo Fret libre appartenant a des Decouvreurs.
     * @param int $totalCargo Fret libre de toutes les flottes offensives retenues.
     */
    public function __construct(
        public int $discovererCargo,
        public int $totalCargo,
    ) {
        if ($discovererCargo < 0 || $totalCargo < 0) {
            throw new InvalidArgumentException('Une capacite de fret ne peut pas etre negative.');
        }

        if ($discovererCargo > $totalCargo) {
            throw new InvalidArgumentException(
                'Le fret des Decouvreurs (' . $discovererCargo . ') depasse le fret total engage (' . $totalCargo . ') : '
                . 'la part serait superieure a un, et le taux depasserait son plafond.'
            );
        }
    }

    /**
     * Aucune flotte offensive retenue.
     *
     * Le cas se presente : toutes les candidates peuvent avoir ete exclues ou rappelees. Il ne
     * doit surtout pas produire une division par zero.
     */
    public static function none(): self
    {
        return new self(0, 0);
    }

    /**
     * Si aucun fret n'est engage.
     */
    public function isEmpty(): bool
    {
        return $this->totalCargo === 0;
    }

    /**
     * Additionne la part d'un joueur de plus.
     *
     * L'addition etant commutative, l'ordre dans lequel les participants sont ajoutes ne change
     * rien — c'est ce qui garantit qu'une permutation donne le meme taux.
     *
     * @param int $cargo
     * @param bool $isDiscoverer
     * @return self
     */
    public function plus(int $cargo, bool $isDiscoverer): self
    {
        if ($cargo < 0) {
            throw new InvalidArgumentException('Une capacite de fret ne peut pas etre negative.');
        }

        // **La somme elle-meme peut deborder.** La commutativite ne protege de rien si le total
        // bascule en flottant : deux ordres d'addition donneraient alors deux totaux differents,
        // et donc deux taux differents. Un total hors domaine est refuse plutot que tronque.
        if ($cargo > PHP_INT_MAX - $this->totalCargo) {
            throw new InvalidArgumentException(
                'Le fret total depasserait la capacite d un entier. Aucune flotte du jeu ne peut l atteindre : '
                . 'cette valeur signale une donnee corrompue.'
            );
        }

        return new self(
            $this->discovererCargo + ($isDiscoverer ? $cargo : 0),
            $this->totalCargo + $cargo,
        );
    }
}
