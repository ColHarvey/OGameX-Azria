<?php

namespace OGame\Lifeforms\Rules;

use InvalidArgumentException;

/**
 * Les cinq vitesses qui gouvernent les formes de vie a un instant : economie, recherche, et les
 * trois coefficients propres (construction, recherche, decouverte).
 *
 * Les vitesses composees suivent la convention du depot pour les recherches classiques
 * (`PlanetService::getTechnologyResearchTime()` : economie × recherche) : construction = economie ×
 * coefficient ; recherche = economie × recherche × coefficient ; demographie = economie seule.
 */
final readonly class LifeformSpeeds
{
    public function __construct(
        public float $economy,
        public float $research,
        public float $buildMultiplier,
        public float $researchMultiplier,
        public float $discoveryMultiplier,
    ) {
        foreach ([$economy, $research, $buildMultiplier, $researchMultiplier, $discoveryMultiplier] as $v) {
            if (!is_finite($v) || $v <= 0.0) {
                throw new InvalidArgumentException("Une vitesse doit etre un nombre fini strictement positif ($v).");
            }
        }
    }

    public function demography(): float
    {
        return $this->economy;
    }

    public function building(): float
    {
        return $this->economy * $this->buildMultiplier;
    }

    public function technology(): float
    {
        return $this->economy * $this->research * $this->researchMultiplier;
    }

    public function discovery(): float
    {
        return $this->discoveryMultiplier;
    }

    public function equals(self $other): bool
    {
        return abs($this->economy - $other->economy) < 1e-9
            && abs($this->research - $other->research) < 1e-9
            && abs($this->buildMultiplier - $other->buildMultiplier) < 1e-9
            && abs($this->researchMultiplier - $other->researchMultiplier) < 1e-9
            && abs($this->discoveryMultiplier - $other->discoveryMultiplier) < 1e-9;
    }
}
