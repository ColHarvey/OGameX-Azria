<?php

namespace OGame\Lifeforms\Bonuses;

/**
 * Une ligne de la page des bonus : ce qu une technologie d une planete apporte a un effet.
 *
 * C est le detail derriere un total — la page officielle le montre exactement ainsi : par planete, une
 * table « Emplacement / Niveau / Technologie / Total ». Les contributions et le total viennent de la
 * **meme promenade** du resolveur : il n y a pas deux calculs (journal §155.7).
 */
final readonly class LifeformBonusContribution
{
    public function __construct(
        public int $planetId,
        public int $slot,
        public int $objectId,
        public int $level,
        public string $code,
        public string|null $target,
        /**
         * La part apportee, **en fraction** (0,01 = 1 %) : c est ce que le resolveur somme.
         */
        public float $fraction,
    ) {
    }

    public function key(): string
    {
        return LifeformBonusSet::key($this->code, $this->target);
    }
}
