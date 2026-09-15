<?php

namespace OGame\Lifeforms\Catalogue;

/**
 * Les deux genres d objets d une forme de vie : un batiment (local a la planete, douze par espece)
 * ou une technologie (dix-huit par espece, en trois paliers de six).
 */
enum LifeformKind: string
{
    case Building = 'building';
    case Technology = 'technology';

    /**
     * Le chiffre des centaines de l identifiant officiel : 1 pour un batiment, 2 pour une technologie.
     */
    public function idDigit(): int
    {
        return match ($this) {
            self::Building => 1,
            self::Technology => 2,
        };
    }
}
