<?php

namespace OGame\Lifeforms\Demography;

use InvalidArgumentException;
use OGame\Lifeforms\Catalogue\LifeformCatalogue;
use OGame\Lifeforms\Catalogue\LifeformEffect;
use OGame\Lifeforms\Catalogue\LifeformFormulas;
use OGame\Lifeforms\Catalogue\LifeformObject;
use OGame\Lifeforms\Species;

/**
 * Les taux demographiques d une planete, constants tant que rien ne change : espace de vie,
 * croissance, nourriture produite et consommee, stock, capacites de palier, population de base.
 *
 * Construit depuis l espece, les niveaux des batiments de formes de vie de la planete et la vitesse
 * economique effective, selon `DemographicRules`. Pur : deux appels avec les memes entrees rendent
 * les memes taux, et l horloge n en lit rien d autre.
 */
final readonly class PlanetLifeformProfile
{
    private function __construct(
        public Species $species,
        public int $livingSpace,
        public float $basePopulation,
        public float $growthPerHour,
        public float $foodProductionPerHour,
        public float $foodPerInhabitantPerHour,
        public float $foodStorage,
        public float $tier2Capacity,
        public float $tier3Capacity,
        public float $protectedShare,
    ) {
    }

    /**
     * @param array<int, int> $levels niveau par identifiant de batiment de forme de vie
     */
    public static function fromLevels(Species $species, array $levels, float $speed): self
    {
        if ($speed <= 0.0 || !is_finite($speed)) {
            throw new InvalidArgumentException("La vitesse doit etre un nombre fini strictement positif ($speed).");
        }
        $niveau = static function (LifeformObject $objet) use ($levels): int {
            $n = $levels[$objet->id] ?? 0;
            if (!is_int($n) || $n < 0) {
                throw new InvalidArgumentException("Niveau invalide pour $objet->machineName.");
            }

            return $n;
        };

        $batiments = LifeformCatalogue::buildingsOf($species);
        $logement = $batiments[0];
        $ferme = $batiments[1];
        $espaceDeVie = $logement->bonus(LifeformEffect::LIVING_SPACE) ?? throw new InvalidArgumentException('Le logement n a pas d espace de vie.');
        $croissance = $logement->bonus(LifeformEffect::GROWTH_RATE) ?? throw new InvalidArgumentException('Le logement n a pas de taux de croissance.');
        $production = $ferme->bonus(LifeformEffect::FOOD_PRODUCTION) ?? throw new InvalidArgumentException('La ferme ne produit pas de nourriture.');
        $stock = $ferme->bonus(LifeformEffect::FOOD_STORAGE) ?? throw new InvalidArgumentException('La ferme ne stocke pas de nourriture.');

        // Les bonus lineaires en pour cent, batiment par batiment.
        $pourcent = static function (string $code) use ($batiments, $niveau): float {
            $total = 0.0;
            foreach ($batiments as $batiment) {
                $bonus = $batiment->bonus($code);
                if ($bonus !== null) {
                    $total += LifeformFormulas::buildingBonusPercent($bonus, $niveau($batiment));
                }
            }

            return $total;
        };

        $niveauLogement = $niveau($logement);
        $niveauFerme = $niveau($ferme);

        $espace = (int)floor(LifeformFormulas::livingSpace($espaceDeVie, $niveauLogement) * (1 + $pourcent(LifeformEffect::LIVING_SPACE_PERCENT) / 100));
        $base = (float)LifeformFormulas::livingSpace($espaceDeVie, 0);

        // Le bonus de croissance du logement suit la formule du fichier maitre : N^facteur × base, en pour cent.
        $bonusCroissance = $niveauLogement > 0 ? ($niveauLogement ** $croissance->factor) * $croissance->base : 0.0;
        $bonusCroissance += $pourcent(LifeformEffect::GROWTH_RATE_PERCENT);
        $croissanceParHeure = $espace / DemographicRules::HOURS_TO_FILL * $speed * (1 + $bonusCroissance / 100);

        $productionParHeure = LifeformFormulas::quantityFromLevelOne($production, $niveauFerme) * $speed * (1 + $pourcent(LifeformEffect::FOOD_PRODUCTION_PERCENT) / 100);
        $reduction = min(99.0, $pourcent(LifeformEffect::FOOD_CONSUMPTION_REDUCTION));
        $consommation = DemographicRules::FOOD_PER_INHABITANT_PER_HOUR * $speed * (1 - $reduction / 100);
        $stockage = LifeformFormulas::livingSpace($stock, $niveauFerme) * (1 + $pourcent(LifeformEffect::FOOD_STORAGE_PERCENT) / 100);
        if ($niveauFerme === 0) {
            $stockage = 0.0;
        }

        $capacite = static function (string $code) use ($batiments, $niveau): float {
            foreach ($batiments as $batiment) {
                $bonus = $batiment->bonus($code);
                if ($bonus !== null) {
                    return LifeformFormulas::quantityFromLevelOne($bonus, $niveau($batiment));
                }
            }

            return 0.0;
        };

        $protection = $pourcent(LifeformEffect::POPULATION_PROTECTION) / 100;

        return new self(
            species: $species,
            livingSpace: $espace,
            basePopulation: $base,
            growthPerHour: $croissanceParHeure,
            foodProductionPerHour: $productionParHeure,
            foodPerInhabitantPerHour: $consommation,
            foodStorage: (float)$stockage,
            tier2Capacity: $capacite(LifeformEffect::TIER2_CAPACITY),
            tier3Capacity: $capacite(LifeformEffect::TIER3_CAPACITY),
            protectedShare: $protection,
        );
    }

    /**
     * Combien d habitants la production nourrit, en regime permanent.
     */
    public function inhabitantsFed(): float
    {
        if ($this->foodPerInhabitantPerHour <= 0.0) {
            return INF;
        }

        return $this->foodProductionPerHour / $this->foodPerInhabitantPerHour;
    }

    public function tier2Of(float $population): float
    {
        return min($population, $this->tier2Capacity);
    }

    public function tier3Of(float $population): float
    {
        return min($this->tier2Of($population), $this->tier3Capacity);
    }

    /**
     * La population a l abri lors d une attaque : l abri fixe plus la part protegee, sans depasser
     * la population presente.
     */
    public function shelteredOf(float $population): float
    {
        return min($population, max((float)DemographicRules::SHELTERED, $population * $this->protectedShare));
    }
}
