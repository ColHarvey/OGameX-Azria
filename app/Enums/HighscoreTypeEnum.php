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
     * Les trois classements des formes de vie, comme le jeu officiel les tient : les batiments d un cote, les
     * technologies de l autre, et leur somme — qui entre, elle, dans le General. Ces investissements ne vont
     * **jamais** dans l Economie ni dans la Recherche classiques (journal §173, preuves conservees).
     *
     * Les valeurs 8, 9 et 10 coincident avec celles de l API officielle, ou `Lifeform` vaut 8,
     * `Lifeform Economy` 9 et `Lifeform Technology` 10. La coincidence est heureuse et non recherchee : ce
     * sont simplement les trois valeurs libres suivantes de notre propre suite.
     */
    case lifeform = 8;
    case lifeform_economy = 9;
    case lifeform_technology = 10;

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

    /**
     * Les classements propres aux formes de vie.
     */
    public function isLifeform(): bool
    {
        return match ($this) {
            self::lifeform, self::lifeform_economy, self::lifeform_technology => true,
            default => false,
        };
    }
}
