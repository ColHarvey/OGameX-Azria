<?php

namespace OGame\Models\Enums;

/**
 * Enum that represents the type of planet.
 */
enum PlanetType: int
{
    /**
     * Represents a planet.
     */
    case Planet = 1;

    /**
     * Represents a debris field.
     */
    case DebrisField = 2;

    /**
     * Represents a moon.
     */
    case Moon = 3;
    /**
     * Represents expeditions.
     */
    case DeepSpace = 4;

    /**
     * Un point libre de l espace, en coordonnees de reference du systeme (`x`, `y`), vise par une
     * patrouille. Ni planete, ni lune, ni debris, ni espace profond : aucun corps, aucune regle de
     * la position 16. Le point porte ses propres debris et sa propre barriere de combat.
     */
    case SpatialPoint = 5;
}
