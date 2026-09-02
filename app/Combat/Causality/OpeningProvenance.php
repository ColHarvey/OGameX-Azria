<?php

namespace OGame\Combat\Causality;

use InvalidArgumentException;

/**
 * Ce que l'etat protege reflete deja, evenement par evenement.
 *
 * ## Le risque que cette classe existe pour supprimer
 *
 * C'est le piege principal de toute la reconciliation, et il survit a des comparaisons temporelles
 * parfaitement justes :
 *
 *     1. un transport arrive et livre 100 metal
 *     2. la base contient maintenant ces 100
 *     3. le combat s'ouvre et photographie ce solde
 *     4. le reconciliateur retrouve le transport comme engagement anterieur a l'ouverture
 *     5. sans provenance, il ajoute les memes 100 une seconde fois
 *
 * L'engagement **etait** anterieur a l'ouverture, et son effet **etait** prevu avant la fermeture :
 * les deux barrieres disent oui. Seule la provenance dit que c'est deja fait.
 *
 * ## Pourquoi un numero maximal ne suffit pas
 *
 * Un simple « tout ce qui est en dessous de N est deja reflete » suppose que les identifiants sont
 * consommes dans l'ordre et sans trou. Un evenement plus ancien peut encore etre en retard : son
 * identifiant est inferieur a N, il n'a pas ete applique, et le watermark le declare pourtant
 * comptabilise. Il disparaitrait alors sans que rien ne le signale.
 *
 * Cette classe porte donc les **identites exactes**. Un watermark n'est admissible que si
 * l'infrastructure garantit la continuite, ce qui n'est pas le cas ici.
 */
final readonly class OpeningProvenance
{
    /**
     * @param array<string, true> $identities Les identites deja refletees, en cles.
     */
    private function __construct(
        private array $identities,
    ) {
    }

    /**
     * La provenance d'un etat qui ne reflete encore aucun evenement.
     */
    public static function nothing(): self
    {
        return new self([]);
    }

    /**
     * La provenance faite d'identites exactes.
     *
     * @param array<int, string> $identities
     * @return self
     */
    public static function ofIdentities(array $identities): self
    {
        $connues = [];

        foreach ($identities as $identity) {
            if ($identity === '') {
                throw new InvalidArgumentException(
                    'Une identite vide ne designe aucun evenement : la provenance ne pourrait rien en dire.'
                );
            }

            $connues[$identity] = true;
        }

        return new self($connues);
    }

    /**
     * Si l'etat protege reflete deja cet evenement.
     */
    public function alreadyReflects(CausalEvent $event): bool
    {
        return isset($this->identities[$event->identity]);
    }

    /**
     * Combien d'evenements l'etat protege reflete.
     */
    public function count(): int
    {
        return count($this->identities);
    }

    /**
     * Les identites refletees, triees.
     *
     * @return array<int, string>
     */
    public function identities(): array
    {
        $identities = array_keys($this->identities);
        sort($identities);

        return $identities;
    }
}
