<?php

namespace OGame\Combat\Causality;

use OGame\Combat\Exceptions\ContradictoryOpeningProvenance;

/**
 * Ce que l'etat protege reflete deja, effet par effet.
 *
 * ## Le risque que cette classe existe pour supprimer
 *
 * C'est le piege central de toute la reconciliation, et il survit a des comparaisons temporelles
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
 * ## Pourquoi l'identite seule ne suffit pas non plus
 *
 * Savoir que `mission:42` a deja ete appliquee ne dit pas **quel** effet de `mission:42` est present.
 * Une cargaison modifiee, une cible corrigee, un genre reversionne : la seule correspondance
 * d'identifiant declarerait « deja fait » un effet qui ne l'est pas. Chaque entree porte donc un
 * recu complet.
 *
 * ## Pourquoi un numero maximal ne suffit pas
 *
 * Un « tout ce qui est en dessous de N est deja reflete » suppose des identifiants consommes dans
 * l'ordre et sans trou. Un evenement plus ancien encore en retard a un identifiant inferieur a N,
 * n'a pas ete applique, et le watermark le declare pourtant comptabilise : il disparaitrait sans
 * que rien ne le signale.
 *
 * Cette classe porte donc les **recus exacts**. Un watermark ne serait admissible que si
 * l'infrastructure garantissait la continuite, ce qui n'est pas le cas ici.
 */
final readonly class OpeningProvenance
{
    /**
     * @param array<string, AppliedEffectReceipt> $receipts Les recus, par identite d'evenement.
     */
    private function __construct(
        private array $receipts,
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
     * La provenance faite de recus d'application.
     *
     * @param array<int, AppliedEffectReceipt> $receipts
     * @return self
     */
    public static function ofReceipts(array $receipts): self
    {
        $connus = [];

        foreach ($receipts as $receipt) {
            $deja = $connus[$receipt->eventIdentity] ?? null;

            if ($deja !== null && $deja->effectFingerprint !== $receipt->effectFingerprint) {
                throw new ContradictoryOpeningProvenance(
                    'Deux recus de « ' . $receipt->eventIdentity . ' » decrivent des effets differents. L etat '
                    . 'protege ne peut pas refleter les deux, et choisir l un des deux ferait dependre la '
                    . 'photographie de celui qu on a garde.'
                );
            }

            $connus[$receipt->eventIdentity] = $receipt;
        }

        return new self($connus);
    }

    /**
     * Le recu d'un evenement, s'il en existe un.
     */
    public function receiptFor(CausalEvent $event): AppliedEffectReceipt|null
    {
        return $this->receipts[$event->identity] ?? null;
    }

    /**
     * Combien d'effets l'etat protege reflete.
     */
    public function count(): int
    {
        return count($this->receipts);
    }

    /**
     * Les identites refletees, triees.
     *
     * @return array<int, string>
     */
    public function identities(): array
    {
        $identities = array_keys($this->receipts);
        sort($identities);

        return $identities;
    }
}
