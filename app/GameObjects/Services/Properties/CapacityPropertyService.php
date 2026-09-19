<?php

namespace OGame\GameObjects\Services\Properties;

use OGame\GameObjects\Models\Fields\GameObjectPropertyDetails;
use OGame\GameObjects\Services\Properties\Abstracts\ObjectPropertyService;
use OGame\Lifeforms\Bonuses\LifeformBonusResolver;
use OGame\Lifeforms\Catalogue\LifeformEffect;
use OGame\Lifeforms\Catalogue\LifeformFormulas;
use OGame\Services\CharacterClassService;
use OGame\Services\PlayerService;

/**
 * Class CapacityPropertyService.
 *
 * @package OGame\Services
 */
class CapacityPropertyService extends ObjectPropertyService
{
    protected string $propertyName = 'capacity';

    /**
     * Calculate the total value of the capacity property including character class bonuses.
     *
     * @param PlayerService $player
     * @return GameObjectPropertyDetails
     */
    public function calculateProperty(PlayerService $player): GameObjectPropertyDetails
    {
        $bonusPercentage = $this->getBonusPercentage($player);
        // Use integer arithmetic to avoid floating point precision issues
        $bonusValue = intdiv($this->base_value * $bonusPercentage, 100);

        $totalValue = $this->base_value + $bonusValue;

        $breakdown = [
            'rawValue' => $this->base_value,
            'bonuses' => [
                [
                    'type' => 't_ingame.techtree.tooltip_research_bonus',
                    'value' => $bonusValue,
                    'percentage' => $bonusPercentage,
                ],
            ],
            'totalValue' => $totalValue,
        ];

        // Apply character class cargo bonuses (based on base value only, not including research bonuses)
        $classBonus = $this->getCharacterClassCargoBonus($player);
        if ($classBonus > 0) {
            // Use integer arithmetic to avoid floating point precision issues
            $classBonusValue = intdiv($this->base_value * $classBonus, 100);
            $totalValue += $classBonusValue;

            $breakdown['bonuses'][] = [
                'type' => 't_ingame.techtree.tooltip_character_class_bonus',
                'value' => $classBonusValue,
                'percentage' => $classBonus,
            ];
            $breakdown['totalValue'] = $totalValue;
        }

        // Formes de vie : le fret des vaisseaux civils (Extension des soutes, Compresseur neuromodal) ET la technologie
        // propre au vaisseau (Mk II, Revision generale, Surcadencage : « structural integrity, shield strength, firepower,
        // cargo capacity and basic speed », fichier maitre), sur la valeur de base, arrondi vers le bas, sur une ligne
        // (journal §155.5, complete par l audit des effets §157 : le fret manquait).
        $lifeformPercentage = $player->getLifeformUnitStatsPercent($this->parent_object);
        if (LifeformBonusResolver::isCivilShip($this->parent_object->machine_name)) {
            $lifeformPercentage = round($lifeformPercentage + $player->lifeformBonuses()->fraction(LifeformEffect::CIVIL_SHIP_CARGO) * 100, 6);
        }
        {
            if ($lifeformPercentage > 0) {
                $lifeformValue = LifeformFormulas::partOf($this->base_value, $lifeformPercentage);
                $totalValue += $lifeformValue;
                $breakdown['bonuses'][] = [
                    'type' => 't_ingame.techtree.tooltip_lifeform_bonus',
                    'value' => $lifeformValue,
                    'percentage' => $lifeformPercentage,
                ];
                $breakdown['totalValue'] = $totalValue;
            }
        }

        return new GameObjectPropertyDetails($this->base_value, $bonusValue, $totalValue, $breakdown);
    }

    /**
     * @inheritDoc
     */
    protected function getBonusPercentage(PlayerService $player): int
    {
        $hyperspace_technology_level = $player->getResearchLevel('hyperspace_technology');
        return 5 * $hyperspace_technology_level;
    }

    /**
     * Get character class cargo bonus percentage.
     *
     * @param PlayerService $player
     * @return int Percentage bonus (0-100)
     */
    private function getCharacterClassCargoBonus(PlayerService $player): int
    {
        $characterClassService = app(CharacterClassService::class);
        $user = $player->getUser();
        $object = $this->parent_object;

        // Collector: +25% cargo for transporters (Small Cargo: 202, Large Cargo: 203)
        if ($object->id === 202 || $object->id === 203) {
            $multiplier = $characterClassService->getTransporterCargoBonus($user);
            if ($multiplier > 1.0) {
                return (int)round(($multiplier - 1.0) * 100, 6);
            }
        }

        // General: +20% cargo for Recycler (209) and Pathfinder (219)
        if ($object->id === 209 || $object->id === 219) {
            $multiplier = $characterClassService->getRecyclerPathfinderCargoBonus($user);
            if ($multiplier > 1.0) {
                // Amplifie par les formes de vie, 1,2199999… en flottant : arrondi avant l entier (audit §157).
                return (int)round(($multiplier - 1.0) * 100, 6);
            }
        }

        return 0;
    }
}
