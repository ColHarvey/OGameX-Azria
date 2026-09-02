<?php

namespace OGame\Combat\Support;

use OGame\Combat\Exceptions\MismatchedOperationKey;

/**
 * Les diagnostics d'une operation, scelles sous son identite.
 *
 * ## Ce que le sceau garantit
 *
 * L'identite pleinement qualifiee d'une occurrence est la paire **cle d'operation + identite
 * locale**. Une collection brute ne porte que la seconde : deux combats differents y produiraient
 * `attacker_reaper||metal` a l'identique.
 *
 * Sceller d'abord, fusionner ensuite. **L'ordre inverse detruirait la garantie** : fusionner deux
 * collections brutes puis apposer une cle sur le resultat effacerait justement l'information qui
 * permet de constater qu'elles ne venaient pas de la meme operation.
 *
 * ## Pourquoi le journal n'accepte que ceci
 *
 * `ResourceDiagnosticsJournal` ne prend qu'une enveloppe scellee. Une collection locale brute
 * devient donc impossible a journaliser — par le type, pas par convention.
 */
final readonly class SealedResourceDiagnostics
{
    /**
     * @param OperationKey $operation L'operation qui a produit ces diagnostics.
     * @param ResourceNormalizationDiagnostics $diagnostics Ce qu'elle a rencontre.
     */
    private function __construct(
        public OperationKey $operation,
        public ResourceNormalizationDiagnostics $diagnostics,
    ) {
    }

    /**
     * Scelle une collection sous l'identite de son operation.
     *
     * @param OperationKey $operation
     * @param ResourceNormalizationDiagnostics $diagnostics
     * @return self
     */
    public static function seal(OperationKey $operation, ResourceNormalizationDiagnostics $diagnostics): self
    {
        return new self($operation, $diagnostics);
    }

    /**
     * L'union de deux enveloppes de la meme operation.
     *
     * @param self $other
     * @return self
     */
    public function mergedWith(self $other): self
    {
        if (!$this->operation->equals($other->operation)) {
            throw MismatchedOperationKey::because($this->operation, $other->operation);
        }

        return new self($this->operation, $this->diagnostics->mergedWith($other->diagnostics));
    }

    /**
     * Si quelque chose merite d'etre signale.
     */
    public function any(): bool
    {
        return $this->diagnostics->any();
    }

    /**
     * L'identite pleinement qualifiee de chaque occurrence.
     *
     * @return array<int, string>
     */
    public function qualifiedIdentities(): array
    {
        $identites = [];

        foreach (array_keys($this->diagnostics->occurrences) as $locale) {
            $identites[] = $this->operation->asString() . '|' . $locale;
        }

        return $identites;
    }
}
