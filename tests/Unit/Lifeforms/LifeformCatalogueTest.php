<?php

namespace Tests\Unit\Lifeforms;

use OGame\Lifeforms\Bonuses\LifeformBonusResolver;
use OGame\Lifeforms\Catalogue\LifeformCatalogue;
use OGame\Lifeforms\Catalogue\LifeformEffect;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\Catalogue\LifeformObject;
use OGame\Lifeforms\Species;
use OGame\Services\ObjectService;
use Tests\UnitTestCase;

/**
 * Le catalogue des formes de vie est epingle, fiche par fiche, a la feuille extraite du fichier
 * maitre Gameforge (`tests/Fixtures/lifeforms/lfmaster-global.tsv`). Une valeur retouchee sans sa
 * source rougit ici.
 */
final class LifeformCatalogueTest extends UnitTestCase
{
    private const string FIXTURE = __DIR__ . '/../../Fixtures/lifeforms/lfmaster-global.tsv';

    public function testTheCatalogueCarriesExactlyTheOneHundredAndTwentyOfficialSheets(): void
    {
        $tout = LifeformCatalogue::all();
        $this->assertCount(120, $tout);
        $this->assertSame(array_keys($tout), array_map(fn (LifeformObject $o) => $o->id, array_values($tout)));

        foreach (Species::cases() as $espece) {
            $this->assertCount(12, LifeformCatalogue::buildingsOf($espece), $espece->name);
            $this->assertCount(18, LifeformCatalogue::technologiesOf($espece), $espece->name);
            $this->assertSame(range(1, 12), array_map(fn (LifeformObject $o) => $o->index, LifeformCatalogue::buildingsOf($espece)));
            $this->assertSame(range(1, 18), array_map(fn (LifeformObject $o) => $o->index, LifeformCatalogue::technologiesOf($espece)));
        }
    }

    public function testEverySheetMatchesTheMasterFileFieldByField(): void
    {
        $rangees = $this->rangeesDuFichierMaitre();
        $this->assertCount(120, $rangees);

        $compteur = [];
        foreach ($rangees as $numero => $c) {
            $espece = match ($c[4]) {
                'Human' => Species::Humans,
                'Rock´tal' => Species::Rocktal,
                'Mecha' => Species::Mechas,
                'Kaelesh' => Species::Kaelesh,
                default => $this->fail("rangee $numero : espece inconnue « {$c[4]} »"),
            };
            $genre = $c[3] === 'Building' ? LifeformKind::Building : LifeformKind::Technology;
            $compteur[$espece->value][$genre->value] = ($compteur[$espece->value][$genre->value] ?? 0) + 1;
            $index = $compteur[$espece->value][$genre->value];
            $id = $espece->idPrefix() * 1000 + $genre->idDigit() * 100 + $index;
            $objet = LifeformCatalogue::byId($id);
            $ou = "rangee $numero ({$c[1]}, $id)";

            $this->assertSame($espece, $objet->species, $ou);
            $this->assertSame($genre, $objet->kind, $ou);
            $this->assertSame((int)$c[5], $objet->metal, "$ou metal");
            $this->assertSame((int)$c[6], $objet->crystal, "$ou cristal");
            $this->assertSame((int)$c[7], $objet->deuterium, "$ou deuterium");
            $this->assertSame($c[8] === '' ? 0 : (int)$c[8], $objet->energy, "$ou energie");
            $this->assertEqualsWithDelta((float)$c[9], $objet->costFactor, 1e-9, "$ou facteur de cout");
            $this->assertEqualsWithDelta($c[12] === '' ? 1.0 : (float)$c[12], $objet->energyFactor, 1e-9, "$ou facteur d energie");
            $this->assertEqualsWithDelta((float)$c[22], $objet->durationFactor, 1e-9, "$ou facteur de duree");
            $this->assertSame((int)$c[23], $objet->durationBase, "$ou base de duree");

            $bonusAttendus = [];
            for ($i = 0; $i < 3; $i++) {
                if ($c[13 + $i * 3] !== '') {
                    $bonusAttendus[] = [(float)$c[13 + $i * 3], $c[14 + $i * 3] === '' ? 1.0 : (float)$c[14 + $i * 3], $c[15 + $i * 3] === '' ? null : (float)$c[15 + $i * 3]];
                }
            }
            $this->assertCount(count($bonusAttendus), $objet->bonuses, "$ou nombre de bonus");
            foreach ($bonusAttendus as $k => [$base, $facteur, $max]) {
                $bonus = $objet->bonuses[$k];
                $this->assertEqualsWithDelta($base, $bonus->base, 1e-9, "$ou bonus " . ($k + 1) . ' base');
                $this->assertEqualsWithDelta($facteur, $bonus->factor, 1e-9, "$ou bonus " . ($k + 1) . ' facteur');
                if ($max === null) {
                    $this->assertNull($bonus->max, "$ou bonus " . ($k + 1) . ' plafond');
                } else {
                    $this->assertEqualsWithDelta($max, $bonus->max, 1e-9, "$ou bonus " . ($k + 1) . ' plafond');
                }
                $this->assertTrue(LifeformEffect::isKnown($bonus->code), "$ou code inconnu $bonus->code");
            }
        }
    }

    public function testTheTierBuildingsAloneRequireAPopulationAndItIsTheirFirstBonus(): void
    {
        foreach (Species::cases() as $espece) {
            foreach (LifeformCatalogue::buildingsOf($espece) as $batiment) {
                if (in_array($batiment->index, [4, 5], true)) {
                    $this->assertTrue($batiment->requiresPopulation(), $batiment->machineName);
                    $code = $batiment->index === 4 ? LifeformEffect::TIER2_CAPACITY : LifeformEffect::TIER3_CAPACITY;
                    $this->assertNotNull($batiment->bonus($code), $batiment->machineName);
                    $this->assertEqualsWithDelta($batiment->bonus($code)->base, (float)$batiment->populationBase, 1e-9, $batiment->machineName);
                } else {
                    $this->assertFalse($batiment->requiresPopulation(), $batiment->machineName);
                }
            }
            foreach (LifeformCatalogue::technologiesOf($espece) as $technologie) {
                $this->assertFalse($technologie->requiresPopulation(), $technologie->machineName);
            }
        }
    }

    public function testEverySpeciesHasItsHousingFarmResearchCentreAndTierBuildings(): void
    {
        foreach (Species::cases() as $espece) {
            $logement = LifeformCatalogue::buildingWithEffect($espece, LifeformEffect::LIVING_SPACE);
            $ferme = LifeformCatalogue::buildingWithEffect($espece, LifeformEffect::FOOD_PRODUCTION);
            $recherche = LifeformCatalogue::buildingWithEffect($espece, LifeformEffect::LF_RESEARCH_TIME_REDUCTION);
            $this->assertSame(1, $logement?->index, $espece->name);
            $this->assertSame(2, $ferme?->index, $espece->name);
            $this->assertSame(3, $recherche?->index, $espece->name);
            $this->assertNotNull($logement->bonus(LifeformEffect::GROWTH_RATE), $espece->name);
            $this->assertNotNull($ferme->bonus(LifeformEffect::FOOD_STORAGE), $espece->name);
        }
    }

    public function testRequirementsOnlyNameBuildingsOfTheSameSpecies(): void
    {
        foreach (LifeformCatalogue::all() as $objet) {
            foreach ($objet->requirements as $exige => $niveau) {
                $this->assertGreaterThan(0, $niveau, "$objet->machineName exige $exige au niveau $niveau");
                $cible = LifeformCatalogue::byId($exige);
                $this->assertSame($objet->species, $cible->species, "$objet->machineName exige un batiment d une autre espece");
                $this->assertSame(LifeformKind::Building, $cible->kind, "$objet->machineName exige une technologie");
            }
            if ($objet->kind === LifeformKind::Technology) {
                $this->assertSame([], $objet->requirements, "$objet->machineName : une technologie n a pas de prerequis de batiment");
            }
        }
    }

    public function testTheTiersFollowTheIndexAndTheEighteenthTechnologyEnhancesAClass(): void
    {
        foreach (Species::cases() as $espece) {
            $technologies = LifeformCatalogue::technologiesOf($espece);
            $this->assertSame([1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 2, 2, 3, 3, 3, 3, 3, 3], array_map(fn (LifeformObject $o) => $o->tier(), $technologies));
            if ($espece === Species::Humans) {
                $this->assertSame(LifeformEffect::DISCOVERY_DURATION_REDUCTION, $technologies[0]->bonuses[0]->code);
            } else {
                $this->assertSame(LifeformEffect::CLASS_BONUS, $technologies[17]->bonuses[0]->code, $espece->name);
            }
        }
    }

    public function testTheLifeformIdentifiersNeverCollideWithTheGameCatalogue(): void
    {
        $plusGrandDuJeu = max([0, ...array_map(fn ($o) => $o->id, ObjectService::getObjects())]);
        $this->assertGreaterThan(0, $plusGrandDuJeu);
        $this->assertLessThan(11101, $plusGrandDuJeu);
        foreach (LifeformCatalogue::all() as $objet) {
            $this->assertGreaterThanOrEqual(11101, $objet->id);
            $this->assertLessThanOrEqual(14218, $objet->id);
            $this->assertSame($objet->species, Species::ofObjectId($objet->id));
        }
        $this->assertNull(Species::ofObjectId(503));
    }

    public function testEveryTargetOfATargetedEffectExistsInTheGame(): void
    {
        $classes = ['collector', 'general', 'discoverer'];
        foreach (LifeformCatalogue::all() as $objet) {
            foreach ($objet->bonuses as $bonus) {
                if ($bonus->target === null) {
                    continue;
                }
                if ($bonus->code === LifeformEffect::CLASS_BONUS) {
                    $this->assertContains($bonus->target, $classes, $objet->machineName);
                    continue;
                }
                $this->assertSame($bonus->target, ObjectService::getObjectByMachineName($bonus->target)->machine_name, "$objet->machineName vise $bonus->target");
            }
        }
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function rangeesDuFichierMaitre(): array
    {
        $lignes = file(self::FIXTURE, FILE_IGNORE_NEW_LINES);
        $this->assertNotFalse($lignes);
        $rangees = [];
        foreach ($lignes as $ligne) {
            $c = explode("\t", $ligne);
            $numero = (int)$c[0];
            if ($numero < 2 || $numero > 121) {
                continue;
            }
            $rangees[$numero] = $c;
        }

        return $rangees;
    }

    /**
     * **Les vaisseaux civils** : les sept du jeu officiel (l Eclaireur est de combat), une seule liste pour le fret et la
     * vitesse des formes de vie ET pour les non-combattants du General (audit des bonus, journal §164) — deux ecritures
     * divergeaient au premier oubli, et retirer le Recycleur passait la suite.
     */
    public function testTheCivilShipsAreTheSevenOfTheOfficialGame(): void
    {
        $this->assertSame(['small_cargo', 'large_cargo', 'colony_ship', 'recycler', 'espionage_probe', 'solar_satellite', 'crawler'], LifeformBonusResolver::CIVIL_SHIPS);
        $civils = [];
        foreach (ObjectService::getShipObjects() as $vaisseau) {
            if (LifeformBonusResolver::isCivilShip($vaisseau->machine_name)) {
                $civils[] = $vaisseau->id;
            }
        }
        sort($civils);
        $this->assertSame([202, 203, 208, 209, 210, 212, 217], $civils, 'Petit et Grand transporteur, Colonisation, Recycleur, Sonde, Satellite, Foreuse.');
    }
}
