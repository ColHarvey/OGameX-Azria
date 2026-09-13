<?php

namespace OGame\GameObjects\Services\Properties;

use Exception;
use OGame\GameObjects\Services\Properties\Abstracts\ObjectPropertyService;
use OGame\Services\PlayerService;

/**
 * Class StructuralIntegrityPropertyService.
 *
 * @package OGame\Services
 */
class StructuralIntegrityPropertyService extends ObjectPropertyService
{
    protected string $propertyName = 'structural_integrity';

    /**
     * @inheritdoc
     * @throws Exception
     */
    protected function getBonusPercentage(PlayerService $player): int
    {
        $armor_technology_level = $player->getResearchLevel('armor_technology');

        // Every level of armor technology gives 10% bonus.
        return $armor_technology_level * 10;
    }

    /**
     * **Le bonus de classe compte comme des niveaux de blindage** (decision de Keven, 12 septembre
     * 2026) : il porte sur la caracteristique reelle, plus seulement sur le niveau rapporte. La somme
     * des classes est faite par le joueur, source unique, et un combattant gele y repond depuis sa
     * photographie. Chaque niveau vaut 10 %, comme un niveau de recherche.
     */
    protected function getClassBonusPercentage(PlayerService $player): int
    {
        return $player->getCombatResearchBonusLevels() * 10;
    }
}
