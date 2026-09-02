<?php

namespace OGame\Combat\Support;

/**
 * Le quotient et le reste d'une part proportionnelle, calcules ensemble et exactement.
 *
 * Repartir un butin entre des flottes revient a calculer, pour chacune,
 * `montant x poids / poidsTotal`. La partie entiere lui revient tout de suite ; le reste sert a
 * classer les candidats pour distribuer les unites qui n'ont pas trouve preneur.
 *
 * **Les deux doivent venir du meme calcul.** Reconstruire le reste apres coup par
 * `($montant * $poids) % $poidsTotal` reformerait le produit que toute cette mecanique existe pour
 * eviter — et ce produit deborde des que le butin et le fret sont grands.
 */
final readonly class ExactDivision
{
    /**
     * @param int $quotient La part entiere, `floor(montant x poids / poidsTotal)`.
     * @param int $remainder Ce qui depasse, `(montant x poids) mod poidsTotal`. Toujours dans
     *                       `[0, poidsTotal[`.
     * @param int $denominator Le poids total qui a servi au calcul.
     */
    public function __construct(
        public int $quotient,
        public int $remainder,
        public int $denominator,
    ) {
    }

    /**
     * Si deux restes sont comparables.
     *
     * **Un reste n'a de sens que rapporte a son denominateur.** Comparer directement les restes de
     * deux passes d'allocation qui n'ont pas le meme poids total reviendrait a comparer des
     * fractions par leurs seuls numerateurs : trois cinquiemes passerait pour plus petit que
     * quatre neuviemes.
     *
     * A l'interieur d'une passe, tous les participants partagent le meme denominateur, et la
     * comparaison directe est alors legitime. Cette methode existe pour que cette condition soit
     * verifiable plutot que supposee.
     *
     * @param self $other
     * @return bool
     */
    public function isComparableWith(self $other): bool
    {
        return $this->denominator === $other->denominator;
    }
}
