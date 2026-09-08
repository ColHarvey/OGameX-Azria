<?php

namespace OGame\Patrol\Geometry;

/**
 * Un point de la geometrie de reference d un systeme, en unites spatiales entieres.
 *
 * L origine est l etoile. Les coordonnees sont celles que le serveur tient : la carte les projette,
 * elle ne les fabrique jamais. Voir `SystemGeometry`.
 */
final class SpatialPoint
{
    public function __construct(public readonly int $x, public readonly int $y)
    {
    }

    public function equals(SpatialPoint $other): bool
    {
        return $this->x === $other->x && $this->y === $other->y;
    }

    /**
     * La distance a l etoile, en unites spatiales.
     */
    public function norm(): float
    {
        return sqrt((float)($this->x * $this->x + $this->y * $this->y));
    }

    public function asString(): string
    {
        return $this->x . ',' . $this->y;
    }
}
