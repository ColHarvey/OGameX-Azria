<?php

namespace OGame\GameObjects\Services\Properties\Abstracts;

use OGame\GameObjects\Models\Abstracts\GameObject;
use OGame\GameObjects\Models\Fields\GameObjectPropertyDetails;
use OGame\Services\PlayerService;

/**
 * Class ObjectPropertyService.
 *
 * @package OGame\Services
 */
abstract class ObjectPropertyService
{
    /**
     * This is a placeholder for the property name set by the child class.
     *
     * @var string
     */
    protected string $propertyName = '';

    public function __construct(protected GameObject $parent_object, protected int $base_value)
    {
    }

    /**
     * Get the bonus percentage for a property.
     *
     * @return int
     *  Bonus percentage as integer (e.g. 10 for 10% bonus, 110 for 110% bonus, etc.)
     */
    abstract protected function getBonusPercentage(PlayerService $player): int;

    /**
     * La part du bonus qui vient des **classes**, en pourcentage — zero, sauf la ou une classe ajoute
     * des niveaux.
     *
     * Elle est tenue a part de la recherche pour une seule raison : l infobulle la montre sur sa propre
     * ligne (decision de Keven, 12 septembre 2026). Le **calcul**, lui, additionne les deux avant
     * d arrondir, comme les niveaux s additionnent.
     */
    protected function getClassBonusPercentage(PlayerService $player): int
    {
        return 0;
    }

    /**
     * Calculate the total value of a property.
     *
     * @param PlayerService $player
     * @return GameObjectPropertyDetails
     */
    public function calculateProperty(PlayerService $player): GameObjectPropertyDetails
    {
        $researchPercentage = $this->getBonusPercentage($player);
        $classPercentage = $this->getClassBonusPercentage($player);
        $bonusPercentage = $researchPercentage + $classPercentage;
        // Use integer arithmetic to avoid floating point precision issues
        $bonusValue = intdiv($this->base_value * $bonusPercentage, 100);

        $totalValue = $this->base_value + $bonusValue;

        // **Une seule assiette, deux lignes.** Le total vient de la somme des pourcentages, arrondie une
        // fois : c est le nombre que les tirs emploient. Arrondir chaque ligne a part pourrait donner un
        // total que la bataille ne connait pas. La ligne de classe affiche donc ce qui reste apres la
        // ligne de recherche, et les deux somment exactement au bonus.
        $researchValue = intdiv($this->base_value * $researchPercentage, 100);

        // Les libelles sont des **clefs**, traduites par l infobulle : ce calcul tourne des centaines de
        // fois par bataille, ou aucune infobulle n est affichee.
        $breakdown = [
            'rawValue' => $this->base_value,
            'bonuses' => [
                [
                    'type' => 't_ingame.techtree.tooltip_research_bonus',
                    'value' => $researchValue,
                    'percentage' => $researchPercentage,
                ],
            ],
            'totalValue' => $totalValue,
        ];

        if ($classPercentage > 0) {
            $breakdown['bonuses'][] = [
                'type' => 't_ingame.techtree.tooltip_class_bonus',
                'value' => $bonusValue - $researchValue,
                'percentage' => $classPercentage,
            ];
        }

        return new GameObjectPropertyDetails($this->base_value, $bonusValue, $totalValue, $breakdown);
    }
}
