<?php

namespace OGame\Combat\Support;

use OGame\Combat\Enums\CombatReasonCode;
use OGame\Models\CombatInstance;

/**
 * Ce qu'un combat prononce pour une flotte que personne n'avait encore jugee.
 *
 * L'instant y figure explicitement, et ce n'est pas un detail : date de l'horloge du travailleur,
 * une decision changerait de valeur a chaque passage, le registre refuserait comme une contradiction
 * ce qui n'est qu'un rejeu, et l'audit lirait le retard du travailleur au lieu de l'instant ou la
 * flotte s'est posee.
 */
final readonly class RefusedFleetVerdict
{
    public function __construct(
        public CombatInstance $combat,
        public CombatReasonCode $reason,
        public int $decidedAt,
    ) {
    }
}
