<?php

namespace OGame\Combat\Allocation;

use OGame\Combat\Support\ResourceNormalizationDiagnostics;
use OGame\Models\Resources;

/**
 * Un butin plafonne par le fret, et ce que sa conversion a rencontre.
 *
 * **Les diagnostics voyagent avec le resultat.** Le pipeline ne journalise pas : il rend. Une seule
 * resolution de combat le traverse six fois, et un avertissement pose a l'interieur en produirait
 * six pour une operation. L'orchestrateur le plus exterieur — la mission — agrege et ecrit une fois.
 */
final readonly class CappedLoot
{
    /**
     * @param Resources $resources Le butin retenu, en unites entieres.
     * @param ResourceNormalizationDiagnostics $diagnostics Ce que la conversion a rencontre.
     */
    public function __construct(
        public Resources $resources,
        public ResourceNormalizationDiagnostics $diagnostics,
    ) {
    }
}
