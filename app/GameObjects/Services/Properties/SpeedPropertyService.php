<?php

namespace OGame\GameObjects\Services\Properties;

use Exception;
use OGame\GameObjects\Models\Enums\GameObjectType;
use OGame\GameObjects\Models\Fields\GameObjectPropertyDetails;
use OGame\GameObjects\Models\Fields\GameObjectSpeedUpgrade;
use OGame\GameObjects\Services\Properties\Abstracts\ObjectPropertyService;
use OGame\Services\AllianceClassService;
use OGame\Services\CharacterClassService;
use OGame\Services\PlayerService;

/**
 * Class ObjectPropertyService.
 *
 * @package OGame\Services
 */
class SpeedPropertyService extends ObjectPropertyService
{
    protected string $propertyName = 'speed';

    /**
     * Calculate the total value of the speed property, honoring both:
     * - drive bonus percentage (10/20/30% per level)
     * - base speed override at upgrade thresholds (e.g. SC @ Impulse 5 => 10,000)
     */
    public function calculateProperty(PlayerService $player): GameObjectPropertyDetails
    {
        $effectiveBase = $this->determineEffectiveBase($player);
        $bonusPercentage = $this->getBonusPercentage($player);

        $bonusValue = (($effectiveBase / 100) * $bonusPercentage);
        $totalValue = $effectiveBase + $bonusValue;

        $breakdown = [
            'rawValue' => $effectiveBase,
            'bonuses' => [
                [
                    'type' => 't_ingame.techtree.tooltip_research_bonus',
                    'value' => $bonusValue,
                    'percentage' => $bonusPercentage,
                ],
            ],
            'totalValue' => $totalValue,
        ];

        /*
         * **Le bonus de l alliance a sa propre ligne**, comme celui de la classe de personnage, et
         * les deux s additionnent : un Collecteur dans une alliance de Commercants voit ses
         * transporteurs gagner les deux. Verser l un dans l autre dirait au joueur que sa classe lui
         * rapporte ce que son alliance lui rapporte.
         *
         * Les deux se calculent **sur la vitesse de base**, sans les bonus de recherche : c est la
         * regle deja posee ici, et l appliquer differemment ferait deux assiettes.
         */
        $allianceBonus = $this->getAllianceClassSpeedBonus($player);

        if ($allianceBonus > 0) {
            $allianceBonusValue = (($effectiveBase / 100) * $allianceBonus);
            $totalValue += $allianceBonusValue;

            $breakdown['bonuses'][] = [
                'type' => 't_ingame.techtree.tooltip_alliance_class_bonus',
                'value' => $allianceBonusValue,
                'percentage' => $allianceBonus,
            ];
            $breakdown['totalValue'] = $totalValue;
        }

        // Apply character class speed bonuses (based on base speed only, not including research bonuses)
        $classBonus = $this->getCharacterClassSpeedBonus($player);
        if ($classBonus > 0) {
            $classBonusValue = (($effectiveBase / 100) * $classBonus);
            $totalValue += $classBonusValue;

            $breakdown['bonuses'][] = [
                'type' => 't_ingame.techtree.tooltip_character_class_bonus',
                'value' => $classBonusValue,
                'percentage' => $classBonus,
            ];
            $breakdown['totalValue'] = $totalValue;
        }

        return new GameObjectPropertyDetails($effectiveBase, $bonusValue, $totalValue, $breakdown);
    }

    /**
     * Determine base speed override based on speed_upgrade thresholds.
     * Higher-index upgrades take precedence (last match wins).
     */
    private function determineEffectiveBase(PlayerService $player): int
    {
        $object = $this->parent_object;
        $effectiveBase = $this->base_value;

        if (!empty($object->properties->speed_upgrade)) {
            foreach ($object->properties->speed_upgrade as $upgrade) {
                if (!($upgrade instanceof GameObjectSpeedUpgrade)) {
                    continue;
                }

                $meetsThreshold = match ($upgrade->object_machine_name) {
                    'combustion_drive'  => $player->getResearchLevel('combustion_drive')  >= $upgrade->level,
                    'impulse_drive'     => $player->getResearchLevel('impulse_drive')     >= $upgrade->level,
                    'hyperspace_drive'  => $player->getResearchLevel('hyperspace_drive')  >= $upgrade->level,
                    default => false,
                };

                if ($meetsThreshold && $upgrade->base_speed !== null) {
                    // Last applicable upgrade wins (matches "higher index takes precedence")
                    $effectiveBase = (int)$upgrade->base_speed;
                }
            }
        }

        return $effectiveBase;
    }

    /**
     * @inheritdoc
     * @throws Exception
     */
    protected function getBonusPercentage(PlayerService $player): int
    {
        // Speed bonus is calculated based on drive technology:
        // Combustion: 10%/lvl, Impulse: 20%/lvl, Hyperspace: 30%/lvl

        $object = $this->parent_object;

        // Drive levels
        $combustion_drive_level = $player->getResearchLevel('combustion_drive');
        $impulse_drive_level = $player->getResearchLevel('impulse_drive');
        $hyperspace_drive_level = $player->getResearchLevel('hyperspace_drive');

        // Allow speed_upgrade to override which drive applies (last match wins)
        $bonus_percentage_per_level = 0;
        $applicable_technology_level = 0;

        if (!empty($object->properties->speed_upgrade)) {
            /** @var GameObjectSpeedUpgrade $upgrade */
            foreach ($object->properties->speed_upgrade as $upgrade) {
                if ($upgrade->object_machine_name == 'combustion_drive' && $combustion_drive_level >= $upgrade->level) {
                    $bonus_percentage_per_level = 10;
                    $applicable_technology_level = $combustion_drive_level;
                } elseif ($upgrade->object_machine_name == 'impulse_drive' && $impulse_drive_level >= $upgrade->level) {
                    $bonus_percentage_per_level = 20;
                    $applicable_technology_level = $impulse_drive_level;
                } elseif ($upgrade->object_machine_name == 'hyperspace_drive' && $hyperspace_drive_level >= $upgrade->level) {
                    $bonus_percentage_per_level = 30;
                    $applicable_technology_level = $hyperspace_drive_level;
                }
            }
        }

        if ($bonus_percentage_per_level === 0) {
            // Fall back to the required drive on the object itself
            foreach ($object->requirements as $requirement) {
                if ($requirement->object_machine_name == 'combustion_drive') {
                    $bonus_percentage_per_level = 10;
                    $applicable_technology_level = $combustion_drive_level;
                } elseif ($requirement->object_machine_name == 'impulse_drive') {
                    $bonus_percentage_per_level = 20;
                    $applicable_technology_level = $impulse_drive_level;
                } elseif ($requirement->object_machine_name == 'hyperspace_drive') {
                    $bonus_percentage_per_level = 30;
                    $applicable_technology_level = $hyperspace_drive_level;
                }
            }
        }

        return $bonus_percentage_per_level * $applicable_technology_level;
    }

    /**
     * Le bonus de vitesse d une classe d alliance, en pourcentage.
     *
     * Seuls les transporteurs en profitent aujourd hui — une alliance de Commercants. Le bonus des
     * Guerriers, lui, depend de la **destination** (un vol entre membres de l alliance) et ne peut
     * donc pas se decider ici, ou l on ne connait que le vaisseau : il vit la ou la duree d un vol
     * est calculee, avec la cible sous la main.
     */
    private function getAllianceClassSpeedBonus(PlayerService $player): int
    {
        $object = $this->parent_object;

        // Petit et grand transporteur.
        if ($object->id !== 202 && $object->id !== 203) {
            return 0;
        }

        $multiplier = app(AllianceClassService::class)->getTransporterSpeedBonus($player->getUser());

        return $multiplier > 1.0 ? (int)round(($multiplier - 1.0) * 100) : 0;
    }

    /**
     * Get character class speed bonus percentage.
     *
     * @param PlayerService $player
     * @return int Percentage bonus (0-100)
     */
    private function getCharacterClassSpeedBonus(PlayerService $player): int
    {
        $characterClassService = app(CharacterClassService::class);
        $user = $player->getUser();
        $object = $this->parent_object;

        // Collector: +100% transporter speed (Small Cargo: 202, Large Cargo: 203)
        if ($object->id === 202 || $object->id === 203) {
            $multiplier = $characterClassService->getTransporterSpeedBonus($user);
            if ($multiplier > 1.0) {
                return (int)(($multiplier - 1.0) * 100);
            }
        }

        // General: +100% combat ship speed (all military ships except Espionage Probe: 210)
        if ($object->type === GameObjectType::Ship) {
            // Check if it's a military ship (not transporter, recycler, colony ship, solar satellite, crawler, espionage probe)
            $nonCombatShips = [202, 203, 208, 209, 210, 212, 217]; // Small/Large Cargo, Colony Ship, Recycler, Espionage Probe, Solar Satellite, Crawler
            if (!in_array($object->id, $nonCombatShips)) {
                $multiplier = $characterClassService->getCombatShipSpeedBonus($user);
                if ($multiplier > 1.0) {
                    return (int)(($multiplier - 1.0) * 100);
                }
            }
        }

        // General: +100% recycler speed (Recycler: 209)
        if ($object->id === 209) {
            $multiplier = $characterClassService->getRecyclerSpeedBonus($user);
            if ($multiplier > 1.0) {
                return (int)(($multiplier - 1.0) * 100);
            }
        }

        return 0;
    }
}
