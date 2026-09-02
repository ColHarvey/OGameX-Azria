<?php

namespace OGame\Combat\Causality;

use OGame\Combat\Exceptions\IncompleteEventSlice;

/**
 * Une tranche dont la completude a ete **verifiee**, et non seulement affirmee.
 *
 * ## Pourquoi ce type n'a pas de fabrique libre
 *
 * Une premiere version acceptait un simple `readUnderLock: true`. C'etait une convention de nommage,
 * pas une garantie : n'importe quel appelant pouvait declarer une tranche complete. Le reconciliateur
 * lui faisait alors confiance et produisait une photographie plausible et fausse.
 *
 * Il n'existe donc qu'un seul chemin, et il exige les deux choses qu'un objet pur ne peut pas
 * fabriquer :
 *
 *     une barriere de partition -> la preuve que la fermeture possede la partition, jusqu'a une
 *                                  cle d'effet donnee
 *     toutes les sources        -> la preuve qu'aucune n'a ete oubliee
 *
 * ## Ce qu'elle ne prouve toujours pas
 *
 * Que les lignes lues etaient les bonnes. Cela releve de `RallyClosureService`, du verrou et de la
 * transaction. Ce type prouve qu'on a demande a toutes les sources et qu'on a vu tout ce que le
 * curseur couvre — pas que la base a repondu juste.
 */
final readonly class VerifiedCompleteEventSlice
{
    /**
     * @param array<int, CausalEvent> $events Les evenements verifies.
     * @param PartitionBarrier $barrier La partition possedee, et jusqu'ou.
     */
    private function __construct(
        private array $events,
        public PartitionBarrier $barrier,
    ) {
    }

    /**
     * La verification, sous verrou, d'une revendication.
     *
     * @param CausalEventSliceClaim $claim Ce qui a ete assemble.
     * @param PartitionBarrier $barrier La partition possedee.
     * @return self
     */
    public static function verifiedUnderLock(CausalEventSliceClaim $claim, PartitionBarrier $barrier): self
    {
        $manquantes = $claim->missingSources();

        if ($manquantes !== []) {
            throw new IncompleteEventSlice(
                'La tranche n a pas interroge : ' . implode(
                    ', ',
                    array_map(static fn (CausalEventSource $s): string => $s->value, $manquantes)
                ) . '. Une source oubliee produit une photographie plausible et fausse — il y manque un effet, '
                . 'et rien ne le signale.'
            );
        }

        foreach ($claim->all() as $event) {
            if (!$barrier->hasSeen($event)) {
                throw new IncompleteEventSlice(
                    'L evenement « ' . $event->identity . ' » se situe au-dela du curseur de la partition : '
                    . 'cette lecture ne l a pas vu, et la tranche ne peut pas se dire complete a son sujet.'
                );
            }
        }

        return new self($claim->all(), $barrier);
    }

    /**
     * Les evenements verifies.
     *
     * @return array<int, CausalEvent>
     */
    public function all(): array
    {
        return $this->events;
    }

    /**
     * Combien d'evenements la tranche porte.
     */
    public function count(): int
    {
        return count($this->events);
    }
}
