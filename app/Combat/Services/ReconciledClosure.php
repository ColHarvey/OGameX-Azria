<?php

namespace OGame\Combat\Services;

use OGame\Combat\Causality\CausallyReconciledSnapshot;
use OGame\Combat\Support\SnapshotContributionSet;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Resources;

/**
 * Ce que la reconciliation d'une fermeture rend a `RallyClosureService`.
 *
 * La photographie reconciliee dit ce qui entre dans le combat ; les ressources protegees sont l'etat
 * d'ouverture augmente des seules livraisons admissibles ; la garnison photographiee est l'effectif
 * d'ouverture augmente des seules unites que des effets admissibles ont produites ; le defenseur
 * photographie porte les quatre faits que la bataille lui prend, releves par les seules recherches
 * et constructions admissibles ; les identites
 * appliquees sont celles que la fermeture a livrees elle-meme, par leurs gestionnaires canoniques ;
 * les inclusions sont ce que la fermeture doit ecrire avec sa provenance, identite par identite.
 */
final readonly class ReconciledClosure
{
    /**
     * @param array<int, string> $appliedIdentities
     * @param array<string, SnapshotContributionSet> $inclusions
     */
    public function __construct(
        public CausallyReconciledSnapshot $snapshot,
        public Resources $protectedResources,
        public UnitCollection $photographedGarrison,
        public PhotographedDefender $photographedDefender,
        public array $appliedIdentities,
        public array $inclusions,
    ) {
    }
}
