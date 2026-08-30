<?php

namespace OGame\Enums;

enum CharacterClass: int
{
    case COLLECTOR = 1;
    case GENERAL = 2;
    case DISCOVERER = 3;

    /**
     * Get the translation key segment for this class.
     *
     * Les libelles vivent dans resources/lang/<locale>/t_ingame.php, section characterclass :
     * ils etaient auparavant ecrits en dur en francais dans cet enum, ce qui les affichait en
     * francais aux joueurs anglophones.
     */
    private function getTranslationKey(): string
    {
        return match ($this) {
            self::COLLECTOR => 'collector',
            self::GENERAL => 'general',
            self::DISCOVERER => 'discoverer',
        };
    }

    /**
     * Get the display name of the character class.
     */
    public function getName(): string
    {
        return trans('t_ingame.characterclass.' . $this->getTranslationKey() . '.name');
    }

    /**
     * Get the machine name (CSS class) of the character class.
     */
    public function getMachineName(): string
    {
        return match ($this) {
            self::COLLECTOR => 'miner',
            self::GENERAL => 'warrior',
            self::DISCOVERER => 'explorer',
        };
    }

    /**
     * Get the class-specific ship ID.
     */
    public function getClassShipId(): int
    {
        return match ($this) {
            self::COLLECTOR => 217, // Crawler
            self::GENERAL => 218, // Reaper
            self::DISCOVERER => 219, // Pathfinder
        };
    }

    /**
     * Get the class-specific ship name.
     */
    public function getClassShipName(): string
    {
        return trans('t_ingame.characterclass.' . $this->getTranslationKey() . '.ship');
    }

    /**
     * Get the cost to change to this class (in Dark Matter).
     */
    public function getChangeCost(): int
    {
        return 500000;
    }

    /**
     * Get all character class bonuses as an array.
     *
     * @return array<int, string>
     */
    public function getBonuses(): array
    {
        $bonuses = trans('t_ingame.characterclass.' . $this->getTranslationKey() . '.bonuses');

        if (!is_array($bonuses)) {
            return [];
        }

        /** @var array<int, string> $bonuses */
        return array_values($bonuses);
    }

    /**
     * Get the ship description for this class.
     */
    public function getShipDescription(): string
    {
        return trans('t_ingame.characterclass.' . $this->getTranslationKey() . '.ship_description');
    }
}
