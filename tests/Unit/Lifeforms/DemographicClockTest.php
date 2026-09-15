<?php

namespace Tests\Unit\Lifeforms;

use InvalidArgumentException;
use OGame\Lifeforms\Demography\DemographicClock;
use OGame\Lifeforms\Demography\DemographicRules;
use OGame\Lifeforms\Demography\DemographicState;
use OGame\Lifeforms\Demography\PlanetLifeformProfile;
use OGame\Lifeforms\Species;
use Tests\UnitTestCase;

/**
 * L horloge demographique : composable, bornee par l espace de vie et la nourriture, jamais sous la
 * population de base.
 */
final class DemographicClockTest extends UnitTestCase
{
    private const int HOUR = 3600;

    public function testOneHourEqualsFourQuartersOfAnHourEvenAcrossEvents(): void
    {
        $profil = $this->humains(logement: 2, ferme: 1);
        $depart = new DemographicState(210.0, 0.0, 0);

        // Sur une heure : aucun evenement.
        $this->assertSameState($this->avance($depart, $profil, [self::HOUR]), $this->avance($depart, $profil, [900, 1800, 2700, 3600]));

        // Sur 120 heures : le stock se remplit, l espace de vie est atteint, puis la famine ramene la population.
        $enUneFois = $this->avance($depart, $profil, [120 * self::HOUR]);
        $parMorceaux = $this->avance($depart, $profil, [7 * self::HOUR, 30 * self::HOUR, 31 * self::HOUR, 90 * self::HOUR, 120 * self::HOUR]);
        $this->assertSameState($enUneFois, $parMorceaux);
        $this->assertEqualsWithDelta($profil->inhabitantsFed(), $enUneFois->population, 1e-6, 'Apres la famine, la population est ce que la ferme nourrit.');
        $this->assertSame(0.0, $enUneFois->food);
    }

    public function testGrowthIsLinearUpToTheLivingSpace(): void
    {
        $profil = $this->humains(logement: 2, ferme: 5);
        $this->assertSame(922, $profil->livingSpace);
        $this->assertGreaterThan(922, $profil->inhabitantsFed(), 'Le montage nourrit toute la planete.');

        $apresUneHeure = (new DemographicClock())->advance(new DemographicState(210.0, 5.0, 0), $profil, self::HOUR);
        $this->assertEqualsWithDelta(210.0 + $profil->growthPerHour, $apresUneHeure->population, 1e-9);

        $apresLongtemps = (new DemographicClock())->advance(new DemographicState(210.0, 5.0, 0), $profil, 1000 * self::HOUR);
        $this->assertSame(922.0, $apresLongtemps->population, 'La population ne depasse jamais l espace de vie.');
        $this->assertEqualsWithDelta($profil->foodStorage, $apresLongtemps->food, 1e-9, 'Le stock reste plein sous surplus.');
    }

    public function testWithoutAFarmNothingGrowsAndNobodyStarvesBelowTheBasePopulation(): void
    {
        $profil = $this->humains(logement: 3, ferme: 0);
        $this->assertSame(0.0, $profil->foodProductionPerHour);

        $apres = (new DemographicClock())->advance(new DemographicState(210.0, 0.0, 0), $profil, 500 * self::HOUR);
        $this->assertSame(210.0, $apres->population, 'Sans nourriture, la population de base demeure et rien ne croit.');
        $this->assertSame(0.0, $apres->food);
    }

    public function testFamineBringsThePopulationDownToWhatTheFarmFeedsTheMomentTheStockRunsOut(): void
    {
        $profil = $this->humains(logement: 10, ferme: 1);
        $nourris = $profil->inhabitantsFed();
        $this->assertLessThan($profil->livingSpace, $nourris);

        $trop = new DemographicState($nourris * 3, $profil->foodStorage, 0);
        $deficitParHeure = $trop->population * $profil->foodPerInhabitantPerHour - $profil->foodProductionPerHour;
        $heuresAvantRuptureADeficitConstant = $profil->foodStorage / $deficitParHeure;

        // Tant qu il reste du stock, la population continue meme de croitre : la rupture arrive donc
        // plus tot qu un deficit constant ne le laisse croire, jamais plus tard.
        $bienAvant = (new DemographicClock())->advance($trop, $profil, (int)floor($heuresAvantRuptureADeficitConstant * self::HOUR * 0.25));
        $this->assertGreaterThan($trop->population, $bienAvant->population, 'Tant qu il reste du stock, la croissance continue.');
        $this->assertGreaterThan(0.0, $bienAvant->food);

        $bienApres = (new DemographicClock())->advance($trop, $profil, (int)ceil($heuresAvantRuptureADeficitConstant * self::HOUR) + 1);
        $this->assertEqualsWithDelta($nourris, $bienApres->population, 1e-6, 'Le stock vide ramene la population a ce qui est nourri.');
        $this->assertSame(0.0, $bienApres->food);
    }

    public function testFamineNeverGoesBelowTheBasePopulation(): void
    {
        // Une ferme de niveau 1 nourrit 588 Humains et un logement de niveau 1 en loge 508 : une
        // population de 600 sans stock redescend a la plus petite des deux bornes, jamais sous 210.
        $profil = $this->humains(logement: 1, ferme: 1);
        $affames = new DemographicState(600.0, 0.0, 0);
        $apres = (new DemographicClock())->advance($affames, $profil, self::HOUR);
        $this->assertSame(508, $profil->livingSpace);
        $this->assertEqualsWithDelta(min(600.0, $profil->inhabitantsFed(), $profil->livingSpace), $apres->population, 1e-6);
        $this->assertGreaterThanOrEqual($profil->basePopulation, $apres->population);
        $this->assertSame(210.0, $profil->basePopulation);

        // Sans ferme du tout, 600 affames redescendent a la population de base, pas a zero.
        $sansFerme = $this->humains(logement: 3, ferme: 0);
        $apres = (new DemographicClock())->advance($affames, $sansFerme, self::HOUR);
        $this->assertSame(210.0, $apres->population);
    }

    public function testTheStockIsCappedAndTheClockNeverRunsBackwards(): void
    {
        $profil = $this->humains(logement: 2, ferme: 3);
        $apres = (new DemographicClock())->advance(new DemographicState(210.0, 1e9, 100), $profil, 100);
        $this->assertEqualsWithDelta($profil->foodStorage, $apres->food, 1e-9, 'Un stock au-dela du plafond est ramene au plafond.');
        $this->assertSame(210.0, $apres->population);

        $this->expectException(InvalidArgumentException::class);
        (new DemographicClock())->advance(new DemographicState(210.0, 0.0, 100), $profil, 99);
    }

    public function testTheSpeedOnlyChangesThePaceNotTheBalance(): void
    {
        $x1 = PlanetLifeformProfile::fromLevels(Species::Humans, [11101 => 5, 11102 => 5], 1.0);
        $x4 = PlanetLifeformProfile::fromLevels(Species::Humans, [11101 => 5, 11102 => 5], 4.0);
        $this->assertSame($x1->livingSpace, $x4->livingSpace);
        $this->assertEqualsWithDelta($x1->inhabitantsFed(), $x4->inhabitantsFed(), 1e-9, 'Ce que la ferme nourrit ne depend pas de la vitesse.');
        $this->assertEqualsWithDelta($x1->growthPerHour * 4, $x4->growthPerHour, 1e-9);
        $this->assertEqualsWithDelta($x1->foodProductionPerHour * 4, $x4->foodProductionPerHour, 1e-9);

        $depart = new DemographicState(210.0, 0.0, 0);
        $lentement = (new DemographicClock())->advance($depart, $x1, 4 * self::HOUR);
        $vite = (new DemographicClock())->advance($depart, $x4, self::HOUR);
        $this->assertEqualsWithDelta($lentement->population, $vite->population, 1e-6, 'Quatre heures a x1 valent une heure a x4.');
    }

    public function testTheProfileFollowsTheRulesAndTheRealPageAnchors(): void
    {
        $profil = $this->humains(logement: 2, ferme: 1);
        $this->assertSame(922, $profil->livingSpace, 'Page reelle : 922.');
        $this->assertEqualsWithDelta(10.0 / DemographicRules::FOOD_PER_INHABITANT_PER_HOUR, $profil->inhabitantsFed(), 1e-6, 'Une ferme de niveau 1 nourrit 588 habitants ; la page reelle en montrait 590.');
        $this->assertEqualsWithDelta(922 / DemographicRules::HOURS_TO_FILL * (1 + (2 ** 1.2) * 16 / 100), $profil->growthPerHour, 1e-9);
        $this->assertSame(0.0, $profil->tier2Capacity);
        $this->assertSame(0.0, $profil->protectedShare);

        $palier = PlanetLifeformProfile::fromLevels(Species::Humans, [11101 => 40, 11102 => 40, 11104 => 1, 11105 => 1, 11109 => 10, 11107 => 10, 11112 => 10], 1.0);
        $this->assertEqualsWithDelta(20000000.0, $palier->tier2Capacity, 1e-6);
        $this->assertEqualsWithDelta(100000000.0, $palier->tier3Capacity, 1e-6);
        $this->assertEqualsWithDelta(5000000.0, $palier->tier2Of(5000000.0), 1e-6);
        $this->assertEqualsWithDelta(20000000.0, $palier->tier2Of(50000000.0), 1e-6);
        $this->assertEqualsWithDelta(0.30, $palier->protectedShare, 1e-9, 'Bouclier planetaire niveau 10 : 30 %.');
        $this->assertEqualsWithDelta(300.0, $palier->shelteredOf(1000.0), 1e-9);
        $this->assertEqualsWithDelta(100.0, $palier->shelteredOf(150.0), 1e-9, 'L abri de 100 habitants prime sur une part plus petite.');
        $this->assertEqualsWithDelta(50.0, $palier->shelteredOf(50.0), 1e-9, 'Jamais plus que la population presente.');

        $sansBonus = PlanetLifeformProfile::fromLevels(Species::Humans, [11101 => 40, 11102 => 40], 1.0);
        $this->assertGreaterThan($sansBonus->livingSpace, $palier->livingSpace, 'Le Gratte-ciel agrandit l espace de vie.');
        $this->assertLessThan($sansBonus->foodPerInhabitantPerHour, $palier->foodPerInhabitantPerHour, 'Le Silo reduit la consommation.');
    }

    public function testEverySpeciesStartsAtItsOwnBasePopulation(): void
    {
        $bases = [];
        foreach (Species::cases() as $espece) {
            $bases[$espece->name] = PlanetLifeformProfile::fromLevels($espece, [], 1.0)->basePopulation;
        }
        $this->assertSame(['Humans' => 210.0, 'Rocktal' => 150.0, 'Mechas' => 500.0, 'Kaelesh' => 250.0], $bases);
    }

    private function humains(int $logement, int $ferme): PlanetLifeformProfile
    {
        return PlanetLifeformProfile::fromLevels(Species::Humans, [11101 => $logement, 11102 => $ferme], 1.0);
    }

    /**
     * @param array<int, int> $instants
     */
    private function avance(DemographicState $depart, PlanetLifeformProfile $profil, array $instants): DemographicState
    {
        $horloge = new DemographicClock();
        $etat = $depart;
        foreach ($instants as $instant) {
            $etat = $horloge->advance($etat, $profil, $instant);
        }

        return $etat;
    }

    private function assertSameState(DemographicState $a, DemographicState $b): void
    {
        $this->assertEqualsWithDelta($a->population, $b->population, 1e-6, 'population');
        $this->assertEqualsWithDelta($a->food, $b->food, 1e-6, 'nourriture');
        $this->assertSame($a->calculatedAt, $b->calculatedAt);
    }
}
