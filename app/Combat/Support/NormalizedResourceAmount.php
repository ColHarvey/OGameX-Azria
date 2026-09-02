<?php

namespace OGame\Combat\Support;

/**
 * Un solde converti en unites entieres, avec ce que la conversion a rencontre.
 *
 * **Autonome, et c'est le point.** La frontiere ne conserve rien entre deux appels : chaque
 * conversion rend son propre resultat. Sans cela, un diagnostic souleve sur le metal reapparaitrait
 * dans le rapport du cristal converti juste apres, et reutiliser la meme frontiere transporterait
 * l'appel precedent.
 */
final readonly class NormalizedResourceAmount
{
    /**
     * @param int $units La valeur canonique qui gouverne le combat.
     * @param ResourceNormalizationDiagnostics $diagnostics Ce que la conversion a rencontre.
     */
    public function __construct(
        public int $units,
        public ResourceNormalizationDiagnostics $diagnostics,
    ) {
    }
}
