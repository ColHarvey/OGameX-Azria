<?php

namespace OGame\GameObjects\Services\Properties;

use OGame\GameObjects\Services\Properties\Abstracts\ObjectPropertyService;
use OGame\Services\PlayerService;

/**
 * Class ShieldPropertyService.
 *
 * @package OGame\Services
 */
class ShieldPropertyService extends ObjectPropertyService
{
    protected string $propertyName = 'shield';

    /**
     * @inheritdoc
     */
    protected function getBonusPercentage(PlayerService $player): int
    {
        $shielding_technology_level = $player->getResearchLevel('shielding_technology');

        // Every level technology gives 10% bonus.
        return $shielding_technology_level * 10;
    }

    /**
     * **Le bonus de classe compte comme des niveaux de boucliers** (decision de Keven, 12 septembre
     * 2026) : il porte sur la caracteristique reelle, plus seulement sur le niveau rapporte. La somme
     * des classes est faite par le joueur, source unique, et un combattant gele y repond depuis sa
     * photographie. Chaque niveau vaut 10 %, comme un niveau de recherche.
     */
    protected function getClassBonusPercentage(PlayerService $player): int
    {
        return $player->getCombatResearchBonusLevels() * 10;
    }
}
