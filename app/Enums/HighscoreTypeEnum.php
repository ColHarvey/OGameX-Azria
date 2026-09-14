<?php

namespace OGame\Enums;

/**
 * Enum that represents the types of highscores.
 */
enum HighscoreTypeEnum: int
{
    case general = 0;
    case economy = 1;
    case research = 2;
    case military = 3;

    case honor = 4;

    case military_built = 5;
    case military_destroyed = 6;
    case military_lost = 7;

    /**
     * Les trois cumuls militaires : ce qu un joueur a construit, detruit et perdu depuis l activation de la collecte.
     *
     * Ils ne sont servis qu une fois la collecte activee (`MilitaryTallyRecorder::collectingSince()`) : avant, leurs
     * compteurs ne couvrent aucune periode, et des zeros passeraient pour des statistiques completes.
     */
    public function isMilitaryTally(): bool
    {
        return match ($this) {
            self::military_built, self::military_destroyed, self::military_lost => true,
            default => false,
        };
    }
}
