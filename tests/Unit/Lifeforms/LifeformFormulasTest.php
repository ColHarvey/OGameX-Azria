<?php

namespace Tests\Unit\Lifeforms;

use InvalidArgumentException;
use OGame\Lifeforms\Catalogue\LifeformBonus;
use OGame\Lifeforms\Catalogue\LifeformCatalogue;
use OGame\Lifeforms\Catalogue\LifeformEffect;
use OGame\Lifeforms\Catalogue\LifeformFormulas;
use OGame\Lifeforms\Catalogue\LifeformObject;
use Tests\UnitTestCase;

/**
 * Les formules du catalogue, epinglees aux valeurs observees dans le jeu officiel et aux essais de
 * la bibliotheque ouverte qui les reproduit.
 */
final class LifeformFormulasTest extends UnitTestCase
{
    public function testTheCostOfALevelFollowsTheOfficialFormula(): void
    {
        $residentiel = LifeformCatalogue::byMachineName('residential_sector');
        $this->assertSame([7, 2, 0], $this->cout($residentiel, 1));
        $this->assertSame([16, 4, 0], $this->cout($residentiel, 2));
        $this->assertSame([30, 8, 0], $this->cout($residentiel, 3));
        $this->assertSame([120594, 34455, 0], $this->cout($residentiel, 35));

        $centre = LifeformCatalogue::byMachineName('research_centre');
        $this->assertSame([20000, 25000, 10000], $this->cout($centre, 1));
        $this->assertSame([52000, 65000, 26000], $this->cout($centre, 2));

        $catalyseur = LifeformCatalogue::byMachineName('catalyser_technology');
        $this->assertSame([177347025, 106408215, 17734702], $this->cout($catalyseur, 18));
        $this->assertSame([165819468, 99491681, 16581946], $this->cout($catalyseur, 18, 0.065), 'Reduction de 6,5 % apres arrondi.');

        $this->assertSame([0, 0, 0], $this->cout($residentiel, 0));
    }

    public function testTheEnergyOfABuildingFollowsTheOfficialFormula(): void
    {
        $condenseur = LifeformCatalogue::byMachineName('antimatter_condenser');
        $this->assertSame([9, 18, 28, 38], array_map(fn (int $n) => LifeformFormulas::energy($condenseur, $n), [1, 2, 3, 4]));
        $this->assertSame(0, LifeformFormulas::energy(LifeformCatalogue::byMachineName('residential_sector'), 10), 'Le logement ne consomme pas d energie.');
    }

    public function testBuildingDurationsMatchTheCommunityCalculator(): void
    {
        $residentiel = LifeformCatalogue::byMachineName('residential_sector');
        $this->assertSame(25 * 60 + 36, LifeformFormulas::buildingDuration($residentiel, 23, 5, 0, 8.0));

        $centre = LifeformCatalogue::byMachineName('research_centre');
        $this->assertSame(17 * 60 + 21, LifeformFormulas::buildingDuration($centre, 2, 5, 0, 8.0));

        $chaine = LifeformCatalogue::byMachineName('assembly_line');
        $this->assertSame(10 * 60 + 32, LifeformFormulas::buildingDuration($chaine, 42, 10, 7, 8.0));

        $this->assertSame(1, LifeformFormulas::buildingDuration($residentiel, 1, 20, 10, 8.0), 'Plancher d une seconde.');
        $this->assertSame(48, LifeformFormulas::buildingDuration($residentiel, 1, 0, 0, 1.0), 'Niveau 1 a x1 sans robots : 40 × 1,21.');
        $this->assertSame(24, LifeformFormulas::buildingDuration($residentiel, 1, 0, 0, 1.0, 0.5), 'Le Megalithe retire sa fraction apres arrondi.');
    }

    public function testTechnologyDurationsMatchTheCommunityCalculator(): void
    {
        $emissaires = LifeformCatalogue::byMachineName('intergalactic_envoys');
        $this->assertSame(6 * 60, LifeformFormulas::technologyDuration($emissaires, 2, 8.0));
        $this->assertSame(2880, LifeformFormulas::technologyDuration($emissaires, 2, 1.0), 'A x1 : 2 × 1000 × 1,44.');
        $this->assertSame(2822, LifeformFormulas::technologyDuration($emissaires, 2, 1.0, 0.02), 'Le centre de recherche retire 2 % par niveau.');
    }

    public function testATechnologyBonusIsLinearInTheLevelAndMultipliedByExperience(): void
    {
        $repaire = $this->bonusDe('orbital_den', LifeformEffect::STORAGE_CAPACITY);
        $this->assertEqualsWithDelta(36.144, LifeformFormulas::technologyBonusPercent($repaire, 9, 0.004), 1e-9, 'Page reelle : 36,14 % au niveau 9 avec 0,4 % d experience.');
        $this->assertEqualsWithDelta(0.54, LifeformFormulas::technologyBonusPercent($this->bonusDe('high_performance_extractors', LifeformEffect::ALL_PRODUCTION), 9), 1e-9);
        $this->assertEqualsWithDelta(0.88, LifeformFormulas::technologyBonusPercent($this->bonusDe('catalyser_technology', LifeformEffect::DEUTERIUM_PRODUCTION), 11), 1e-9);
        $this->assertEqualsWithDelta(1.2, LifeformFormulas::technologyBonusPercent($this->bonusDe('general_overhaul_light_fighter', LifeformEffect::SHIP_STATS, 'light_fighter'), 4), 1e-9);
        $this->assertEqualsWithDelta(1.6, LifeformFormulas::technologyBonusPercent($this->bonusDe('telekinetic_tractor_beam', LifeformEffect::EXPEDITION_SHIPS), 8), 1e-9);

        $efficacite = $this->bonusDe('efficiency_module', LifeformEffect::FUEL_CONSUMPTION_REDUCTION);
        $this->assertEqualsWithDelta(0.18, LifeformFormulas::technologyBonusPercent($efficacite, 6), 1e-9);
        $this->assertEqualsWithDelta(30.0, LifeformFormulas::technologyBonusPercent($efficacite, 5000), 1e-9, 'Plafonne a 30 %, comme la page l affiche (« Max. -30% »).');
        $this->assertSame(0.0, LifeformFormulas::technologyBonusPercent($efficacite, 0));
    }

    public function testALinearBuildingBonusIsCappedAndANonLinearOneIsRefused(): void
    {
        // Fichier maitre Global : 3 % par niveau, plafond 90 % (l ancien fichier 903 disait 1,5 % et 80 %).
        $bouclier = $this->bonusDe('planetary_shield', LifeformEffect::POPULATION_PROTECTION);
        $this->assertEqualsWithDelta(30.0, LifeformFormulas::buildingBonusPercent($bouclier, 10), 1e-9);
        $this->assertEqualsWithDelta(90.0, LifeformFormulas::buildingBonusPercent($bouclier, 100), 1e-9, 'Plafond de 90 %.');

        $this->expectException(InvalidArgumentException::class);
        LifeformFormulas::buildingBonusPercent($this->bonusDe('residential_sector', LifeformEffect::LIVING_SPACE), 2);
    }

    public function testLivingSpaceMatchesTheRealPageAndTheQuantitiesStartAtLevelOne(): void
    {
        $espace = $this->bonusDe('residential_sector', LifeformEffect::LIVING_SPACE);
        $this->assertSame(210, LifeformFormulas::livingSpace($espace, 0));
        $this->assertSame(922, LifeformFormulas::livingSpace($espace, 2), 'Page reelle : 922 au niveau 2.');
        $this->assertSame(210, LifeformFormulas::livingSpace($espace, -3), 'Un niveau negatif vaut zero.');

        $nourriture = $this->bonusDe('biosphere_farm', LifeformEffect::FOOD_PRODUCTION);
        $this->assertSame(0.0, LifeformFormulas::quantityFromLevelOne($nourriture, 0));
        $this->assertEqualsWithDelta(10.0, LifeformFormulas::quantityFromLevelOne($nourriture, 1), 1e-9);
        $this->assertEqualsWithDelta(23.0, LifeformFormulas::quantityFromLevelOne($nourriture, 2), 1e-9);
    }

    public function testThePopulationRequiredByATierBuildingMatchesTheRealPage(): void
    {
        $neuro = LifeformCatalogue::byMachineName('neuro_calibration_centre');
        $this->assertEqualsWithDelta(100000000.0, LifeformFormulas::populationRequired($neuro, 1), 1e-6, 'Page reelle : 100 Mn au niveau 1.');
        $this->assertEqualsWithDelta(110000000.0, LifeformFormulas::populationRequired($neuro, 2), 1e-6);
        $this->assertSame(0.0, LifeformFormulas::populationRequired(LifeformCatalogue::byMachineName('residential_sector'), 5));
    }

    public function testASpeedThatIsNotAFinitePositiveNumberIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        LifeformFormulas::technologyDuration(LifeformCatalogue::byMachineName('intergalactic_envoys'), 1, 0.0);
    }

    private function bonusDe(string $nomMachine, string $code, string|null $cible = null): LifeformBonus
    {
        $bonus = LifeformCatalogue::byMachineName($nomMachine)->bonus($code, $cible);
        $this->assertNotNull($bonus, "$nomMachine n a pas d effet $code.");

        return $bonus;
    }

    /**
     * @return array<int, int>
     */
    private function cout(LifeformObject $objet, int $niveau, float $reduction = 0.0): array
    {
        $prix = LifeformFormulas::cost($objet, $niveau, $reduction);

        return [(int)$prix->metal->get(), (int)$prix->crystal->get(), (int)$prix->deuterium->get()];
    }
}
