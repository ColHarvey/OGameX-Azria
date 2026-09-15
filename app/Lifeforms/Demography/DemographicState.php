<?php

namespace OGame\Lifeforms\Demography;

use InvalidArgumentException;

/**
 * L etat demographique d une planete a un instant : population, stock de nourriture, instant du
 * calcul. Immuable ; l horloge en rend un nouveau.
 */
final readonly class DemographicState
{
    public function __construct(
        public float $population,
        public float $food,
        public int $calculatedAt,
    ) {
        if (!is_finite($population) || $population < 0.0) {
            throw new InvalidArgumentException("Population invalide : $population.");
        }
        if (!is_finite($food) || $food < 0.0) {
            throw new InvalidArgumentException("Stock de nourriture invalide : $food.");
        }
    }

    public function with(float|null $population = null, float|null $food = null, int|null $calculatedAt = null): self
    {
        return new self($population ?? $this->population, $food ?? $this->food, $calculatedAt ?? $this->calculatedAt);
    }
}
