<?php

namespace Tests\Feature\Lifeforms;

use Illuminate\Support\Facades\Date;
use OGame\Enums\CharacterClass;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\ExpeditionMission;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Lifeforms\Bonuses\LifeformBonusCache;
use OGame\Lifeforms\Bonuses\LifeformBonusResolver;
use OGame\Lifeforms\Catalogue\LifeformEffect;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\Services\LifeformInstallationService;
use OGame\Lifeforms\Services\LifeformLevels;
use OGame\Lifeforms\Species;
use OGame\Models\FleetMission;
use OGame\Models\Lifeforms\LifeformAccount;
use OGame\Models\Lifeforms\LifeformBuildingLevel;
use OGame\Models\Lifeforms\LifeformPlanet;
use OGame\Models\Lifeforms\LifeformSlot;
use OGame\Models\Lifeforms\LifeformSpeciesProgress;
use OGame\Models\Lifeforms\LifeformTechnologyLevel;
use OGame\Models\Planet;
use OGame\Models\User;
use OGame\Services\CharacterClassService;
use OGame\Services\FleetMissionService;
use OGame\Services\MessageService;
use OGame\Services\NPCPlayerService;
use OGame\Services\ObjectService;
use OGame\Services\PhalanxService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;
use OGame\Services\WreckFieldService;
use ReflectionMethod;
use Tests\AccountTestCase;
use Tests\RecordsClassHistory;
use Tests\Support\PinsSettings;

/**
 * Les points d application des bonus (tranche 5) : production, energie, entrepots, prix et temps, unites,
 * flottes, phalange, expeditions, epaves, classes — et leur neutralite sans forme de vie.
 */
final class LifeformBonusHooksTest extends AccountTestCase
{
    use PinsSettings;
    use RecordsClassHistory;

    private const int MAGMA_FORGE = 12106;

    private const int DISRUPTION_CHAMBER = 12107;

    private const int MINERAL_RESEARCH_CENTRE = 12111;

    private const int ION_CRYSTAL_MODULES = 12213;

    private const int ORBITAL_DEN = 11205;

    private const int RESEARCH_AI = 11206;

    private const int STEALTH_FIELD_GENERATOR = 11204;

    private const int HIGH_PERFORMANCE_TERRAFORMER = 11207;

    private const int GENERAL_OVERHAUL_LIGHT_FIGHTER = 13205;

    private const int PLASMA_DRIVE = 13202;

    private const int EFFICIENCY_MODULE = 13203;

    private const int MECHAN_GENERAL_ENHANCEMENT = 13218;

    private const int AUTOMATISED_ASSEMBLY_CENTRE = 13106;

    private const int NANO_REPAIR_BOTS = 13112;

    private const int NEUROMODAL_COMPRESSOR = 14206;

    private const int PSIONIC_NETWORK = 14203;

    private const int ENHANCED_SENSOR_TECHNOLOGY = 14205;

    private const int INTERPLANETARY_ANALYSIS_NETWORK = 14208;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pinSettings(['lifeforms_enabled' => 1, 'economy_speed' => 1, 'research_speed' => 1, 'fleet_speed' => 1]);
        LifeformBonusCache::invalidate();
    }

    protected function tearDown(): void
    {
        $planetes = Planet::query()->where('user_id', $this->currentUserId)->pluck('id');
        LifeformSlot::query()->whereIn('planet_id', $planetes)->delete();
        LifeformTechnologyLevel::query()->whereIn('planet_id', $planetes)->delete();
        LifeformBuildingLevel::query()->whereIn('planet_id', $planetes)->delete();
        LifeformPlanet::query()->whereIn('planet_id', $planetes)->delete();
        LifeformAccount::query()->where('user_id', $this->currentUserId)->delete();
        LifeformSpeciesProgress::query()->where('user_id', $this->currentUserId)->delete();
        LifeformBonusCache::invalidate();
        $this->restorePinnedSettings();
        parent::tearDown();
    }

    public function testEverythingIsNeutralWithoutLifeforms(): void
    {
        $planete = $this->planetService;
        $this->planetSetObjectLevel('metal_mine', 10);
        $index = $planete->getObjectProductionIndex(ObjectService::getGameObjectsWithProductionByMachineName('metal_mine'));
        $this->assertSame(0.0, (float)$index->lifeform->metal->get());
        $this->assertSame(0.0, (float)$index->lifeform->energy->get());
        $this->assertEquals(ObjectService::getObjectRawPrice('metal_mine', 11)->metal->get(), ObjectService::getObjectPrice('metal_mine', $planete)->metal->get());

        $joueur = $this->player();
        $chasseur = ObjectService::getShipObjectByMachineName('light_fighter');
        $attaque = $chasseur->properties->attack->calculate($joueur);
        $this->assertSame(50, $attaque->totalValue);
        $lignes = $attaque->breakdown['bonuses'];
        $this->assertIsArray($lignes);
        $this->assertCount(1, $lignes, 'Sans forme de vie, une seule ligne : la recherche.');
        $this->assertSame(0.0, $joueur->getLifeformUnitStatsPercent($chasseur));
        $this->assertSame(0.0, (new NPCPlayerService('pirate', 0, 0, 0))->getLifeformUnitStatsPercent($chasseur));
        $this->assertTrue((new NPCPlayerService('pirate', 0, 0, 0))->lifeformBonuses()->isEmpty());
    }

    public function testRocktalBuildingsRaiseProductionCutMineCostsAndConsumeEnergy(): void
    {
        $planete = $this->planetService;
        $this->planetSetObjectLevel('metal_mine', 10);
        $this->planetSetObjectLevel('solar_plant', 40);
        $planete->updateResourceProductionStats(true);
        $metalAvant = $planete->getMetalProductionPerHour();
        $this->assertGreaterThan(0, $metalAvant);
        $this->assertSame(100, (int)$planete->getResourceProductionFactor(), 'Le banc part avec toute son energie.');

        $this->choose(Species::Rocktal);
        $this->building(self::MAGMA_FORGE, 10);
        $this->building(self::DISRUPTION_CHAMBER, 10);
        $this->building(self::MINERAL_RESEARCH_CENTRE, 10);
        $this->technology(1, self::ION_CRYSTAL_MODULES, 100);
        $this->planetAddUnit('crawler', 80);

        $mine = $planete->getObjectProductionIndex(ObjectService::getGameObjectsWithProductionByMachineName('metal_mine'));
        $this->assertGreaterThan(0, $mine->mine->metal->get());
        $this->assertEquals(floor(($mine->mine->metal->get() + $mine->planet_slot->metal->get()) * 0.20), $mine->lifeform->metal->get(), 'Forge de magma niveau 10 : +20 % sur la mine et la case.');
        $this->assertEquals(floor(abs($mine->mine->energy->get()) * 0.05), $mine->lifeform->energy->get(), 'Chambre de perturbation niveau 10 : la mine consomme 5 % de moins.');
        $this->assertEquals($mine->mine->energy->get() + $mine->lifeform->energy->get(), $mine->total->energy->get());
        $autres = $mine->basic->metal->get() + $mine->mine->metal->get() + $mine->plasma_technology->metal->get() + $mine->planet_slot->metal->get() + $mine->engineer->metal->get() + $mine->geologist->metal->get() + $mine->character_class->metal->get() + $mine->alliance_class->metal->get() + $mine->crawler->metal->get() + $mine->commanding_staff->metal->get() + $mine->items->metal->get();
        $this->assertEquals($autres + $mine->lifeform->metal->get(), $mine->total->metal->get(), 'Le total compte la ligne des formes de vie.');

        $this->assertEquals(floor(($mine->mine->metal->get() + $mine->planet_slot->metal->get()) * 80 * 0.0002 * 1.10), $mine->crawler->metal->get(), 'Modules de cristal ionique niveau 100 : les quatre-vingts foreuses rendent 10 % de plus (a 1 %, l arrondi cachait l effet).');

        $solaire = $planete->getObjectProductionIndex(ObjectService::getGameObjectsWithProductionByMachineName('solar_plant'));
        $this->assertEquals(floor($solaire->mine->energy->get() * 0.15), $solaire->lifeform->energy->get(), 'Chambre de perturbation niveau 10 : +15 % d energie.');

        $brut = ObjectService::getObjectRawPrice('metal_mine', 11);
        $prix = ObjectService::getObjectPrice('metal_mine', $planete);
        $this->assertEquals(floor($brut->metal->get() * 0.95), $prix->metal->get(), 'Centre de recherche minerale niveau 10 : les mines coutent 5 % de moins.');
        $this->assertEquals(floor($brut->crystal->get() * 0.95), $prix->crystal->get());
        $this->assertEquals(ObjectService::getObjectRawPrice('robot_factory', 1)->metal->get(), ObjectService::getObjectPrice('robot_factory', $planete)->metal->get(), 'Un batiment qui n est pas une mine garde son prix.');

        $planete->updateResourceProductionStats(true);
        $this->assertSame(100, (int)$planete->getResourceProductionFactor(), 'La centrale couvre aussi les batiments de forme de vie : la hausse mesuree est celle du bonus, pas d une penurie.');
        $this->assertGreaterThan($metalAvant, $planete->getMetalProductionPerHour());
        $energieFormesDeVie = resolve(LifeformBonusResolver::class)->buildingEnergyOf($this->currentPlanetId);
        $this->assertGreaterThan(0, $energieFormesDeVie);
        $consommationMine = abs($planete->getObjectProduction('metal_mine', null, true)->energy->get());
        $this->assertGreaterThan(0, $consommationMine);
        $this->assertLessThan(abs($mine->mine->energy->get()), $consommationMine, 'La mine consomme moins que sans la Chambre de perturbation.');
        $foreuses = (int)floor(80 * 50 * (1 - 0.10));
        $this->assertEquals((int)($consommationMine + $foreuses + $energieFormesDeVie), (int)$planete->energyConsumption()->get(), 'La consommation de la planete : la mine (reduite), les foreuses (reduites de 10 %) et les batiments de forme de vie.');
    }

    public function testHumansTechnologiesEnlargeStorageAndShortenResearchAndTerraformer(): void
    {
        $planete = $this->planetService;
        $this->planetSetObjectLevel('metal_store', 3);
        $this->planetSetObjectLevel('research_lab', 2);
        $planete->updateResourceStorageStats(true);
        $entrepotAvant = (int)Planet::query()->whereKey($this->currentPlanetId)->value('metal_max');
        $this->assertGreaterThan(0, $entrepotAvant);

        $this->choose(Species::Humans);
        $this->technology(5, self::ORBITAL_DEN, 5);
        $this->technology(6, self::RESEARCH_AI, 10);
        $this->technology(4, self::STEALTH_FIELD_GENERATOR, 10);
        $this->technology(1, self::HIGH_PERFORMANCE_TERRAFORMER, 10);

        $planete->updateResourceStorageStats(true);
        $this->assertSame((int)floor($entrepotAvant * 1.20), (int)Planet::query()->whereKey($this->currentPlanetId)->value('metal_max'), 'Repaire orbital niveau 5 : +20 % d entrepot.');

        // Espionnage : le prix baisse de 1 %, et le temps — derive du prix reduit — baisse de 1 % + 2 %.
        $brut = ObjectService::getObjectRawPrice('espionage_technology', 1);
        $prix = ObjectService::getObjectPrice('espionage_technology', $planete);
        $this->assertEquals(floor($brut->metal->get() * 0.99), $prix->metal->get());
        $laboratoire = $planete->getResearchNetworkLabLevel('espionage_technology');
        $tempsBrut = (int)((($prix->metal->get() + $prix->crystal->get()) / (1000 * (1 + $laboratoire) * 1)) * 3600);
        $this->assertGreaterThan(100, $tempsBrut);
        $this->assertSame((int)floor($tempsBrut * 0.97), (int)$planete->getTechnologyResearchTime('espionage_technology'));

        // Energie : pas de cible, seule la part generale (IA de recherche, 1 %) joue, et le prix ne bouge pas.
        $brutEnergie = ObjectService::getObjectRawPrice('energy_technology', 1);
        $prixEnergie = ObjectService::getObjectPrice('energy_technology', $planete);
        $this->assertEquals($brutEnergie->metal->get(), $prixEnergie->metal->get());
        $tempsEnergie = (int)((($prixEnergie->metal->get() + $prixEnergie->crystal->get()) / (1000 * (1 + $planete->getResearchNetworkLabLevel('energy_technology')) * 1)) * 3600);
        $this->assertSame((int)floor($tempsEnergie * 0.99), (int)$planete->getTechnologyResearchTime('energy_technology'));

        // Terraformeur : −1 % de prix, −2 % de temps ; la fabrique de robots ne bouge pas.
        $brutTerra = ObjectService::getObjectRawPrice('terraformer', 1);
        $prixTerra = ObjectService::getObjectPrice('terraformer', $planete);
        $this->assertEquals(floor($brutTerra->metal->get() * 0.99), $prixTerra->metal->get());
        $robots = $planete->getObjectLevel('robot_factory');
        $nanites = $planete->getObjectLevel('nano_factory');
        $tempsTerra = (int)(((($prixTerra->metal->get() + $prixTerra->crystal->get()) / (2500 * max(4 - (1 / 2), 1) * (1 + $robots) * 1 * (2 ** $nanites)))) * 3600);
        $this->assertSame((int)floor($tempsTerra * 0.98), $planete->getBuildingConstructionTime('terraformer'));
        $this->assertEquals(ObjectService::getObjectRawPrice('robot_factory', $robots + 1)->metal->get(), ObjectService::getObjectPrice('robot_factory', $planete)->metal->get());
    }

    public function testMechasTechnologiesRaiseShipStatsSpeedFuelAndClassBonuses(): void
    {
        $this->choose(Species::Mechas);
        $this->technology(5, self::GENERAL_OVERHAUL_LIGHT_FIGHTER, 10);
        $this->technology(2, self::PLASMA_DRIVE, 10);
        $this->technology(3, self::EFFICIENCY_MODULE, 10);
        $this->technology(1, self::MECHAN_GENERAL_ENHANCEMENT, 50);

        $joueur = $this->player();
        $chasseur = ObjectService::getShipObjectByMachineName('light_fighter');
        $attaque = $chasseur->properties->attack->calculate($joueur);
        $this->assertSame(51, $attaque->totalValue, 'Revision generale du chasseur leger niveau 10 : +3 % de 50, arrondi vers le bas.');
        $this->assertSame(4120, $chasseur->properties->structural_integrity->calculate($joueur)->totalValue);
        $this->assertSame(10, $chasseur->properties->shield->calculate($joueur)->totalValue, '3 % de 10 : rien, arrondi vers le bas.');
        $lignes = $attaque->breakdown['bonuses'];
        $this->assertIsArray($lignes);
        $this->assertContains('t_ingame.techtree.tooltip_lifeform_bonus', array_column($lignes, 'type'));
        $this->assertSame(150, ObjectService::getShipObjectByMachineName('heavy_fighter')->properties->attack->calculate($joueur)->totalValue, 'La technologie vise le chasseur leger seul.');

        $this->assertSame(12750.0, (float)$chasseur->properties->speed->calculate($joueur)->totalValue, 'Propulsion a plasma niveau 10 : +2 % de vitesse.');

        $flotte = new UnitCollection();
        $flotte->addUnit($chasseur, 100);
        $service = resolve(FleetMissionService::class);
        $avec = $service->consumptionOverDistance($joueur, $flotte, 20000, 0, 10);
        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Technology, self::EFFICIENCY_MODULE, 0);
        $sans = $service->consumptionOverDistance($joueur, $flotte, 20000, 0, 10);
        $this->assertGreaterThan(100, $sans);
        $this->assertSame((int)floor($sans * 0.997), $avec, 'Module d efficacite niveau 10 : 0,3 % de carburant en moins.');

        // Le General voit son bonus de classe amplifie de 10 % par l Amelioration mechan du General (niveau 50).
        $this->recordCharacterClass($this->currentUserId, CharacterClass::GENERAL);
        $utilisateur = User::query()->findOrFail($this->currentUserId);
        $classes = resolve(CharacterClassService::class);
        $this->assertEqualsWithDelta(0.45, $classes->getDeuteriumConsumptionMultiplier($utilisateur), 1e-9, '−50 % × 1,1 = −55 %.');
        $this->assertEqualsWithDelta(1.22, $classes->getRecyclerPathfinderCargoBonus($utilisateur), 1e-9, '+20 % × 1,1 = +22 %.');
        $this->assertSame(1.0, $classes->getMineProductionBonus($utilisateur), 'Un General n a pas le bonus du Collecteur, amplifie ou non.');
        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Technology, self::MECHAN_GENERAL_ENHANCEMENT, 0);
        $this->assertSame(0.5, $classes->getDeuteriumConsumptionMultiplier($utilisateur));
    }

    public function testKaeleshTechnologiesReachCargoPhalanxAndExpeditions(): void
    {
        $this->choose(Species::Kaelesh);
        $this->technology(6, self::NEUROMODAL_COMPRESSOR, 10);
        $this->technology(3, self::PSIONIC_NETWORK, 10);
        $this->technology(5, self::ENHANCED_SENSOR_TECHNOLOGY, 10);
        $this->technology(2, self::INTERPLANETARY_ANALYSIS_NETWORK, 10);

        $joueur = $this->player();
        $transporteur = ObjectService::getShipObjectByMachineName('small_cargo');
        $fret = $transporteur->properties->capacity->calculate($joueur);
        $this->assertSame(5200, $fret->totalValue, 'Compresseur neuromodal niveau 10 : +4 % de fret civil sur 5 000.');
        $lignesFret = $fret->breakdown['bonuses'];
        $this->assertIsArray($lignesFret);
        $this->assertContains('t_ingame.techtree.tooltip_lifeform_bonus', array_column($lignesFret, 'type'));
        $this->assertSame(50, ObjectService::getShipObjectByMachineName('light_fighter')->properties->capacity->calculate($joueur)->totalValue, 'Un vaisseau de combat ne prend pas le fret civil.');

        $this->assertSame(25, resolve(PhalanxService::class)->calculatePhalanxRange(5, $this->currentUserId), '24 systemes × 1,06 = 25.');
        $this->assertEqualsWithDelta(1.02, $joueur->lifeformBonuses()->multiplier(LifeformEffect::EXPEDITION_RESOURCES), 1e-9);

        // La trouvaille de base est tiree au sort entre 10 % et 100 % du plafond : le banc la fixe pour mesurer le bonus seul.
        $expedition = new class (resolve(FleetMissionService::class), resolve(MessageService::class), resolve(PlanetServiceFactory::class), resolve(PlayerServiceFactory::class), resolve(SettingsService::class)) extends ExpeditionMission {
            protected function getBaseMaxFindFromHighscore(): int
            {
                return 100000;
            }
        };
        $mission = new FleetMission();
        $mission->user_id = $this->currentUserId;
        $poids = new ReflectionMethod($expedition, 'getOutcomeWeights');
        $attenduTrouNoir = resolve(SettingsService::class)->expeditionWeightBlackHole() * (1 - 0.005);
        $this->assertEqualsWithDelta($attenduTrouNoir, $poids->invoke($expedition, $mission)['black_hole'], 1e-9, 'Reseau psionique niveau 10 : 0,5 % de trou noir en moins.');

        $trouvaille = new ReflectionMethod(ExpeditionMission::class, 'determineMaxResourceFind');
        $this->assertSame(1.0, (float)resolve(SettingsService::class)->expeditionRewardMultiplierResources(), 'Le multiplicateur d administration vaut 1 dans ce banc.');
        $this->assertSame(102000, $trouvaille->invoke($expedition, $mission), 'Technologie de capteurs amelioree niveau 10 : +2 % de ressources trouvees.');
        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Technology, self::ENHANCED_SENSOR_TECHNOLOGY, 0);
        $this->assertSame(100000, $trouvaille->invoke($expedition, $mission));
    }

    public function testMechasBuildingsShortenShipsAndRaiseWreckRecovery(): void
    {
        $planete = $this->planetService;
        $tempsChasseurAvant = $planete->getUnitConstructionTime('light_fighter');
        $tempsLanceurAvant = $planete->getUnitConstructionTime('rocket_launcher');

        $this->choose(Species::Mechas);
        $this->building(self::AUTOMATISED_ASSEMBLY_CENTRE, 10);
        $this->building(self::NANO_REPAIR_BOTS, 10);

        $this->assertSame((int)floor($tempsChasseurAvant * 0.80), $planete->getUnitConstructionTime('light_fighter'), 'Centre d assemblage automatise niveau 10 : −20 % sur les vaisseaux.');
        $this->assertSame($tempsLanceurAvant, $planete->getUnitConstructionTime('rocket_launcher'), 'Les defenses ne sont pas des vaisseaux.');

        $epaves = new WreckFieldService($this->player(), resolve(SettingsService::class));
        $base = $epaves->getRecoverableWreckFieldPercentage(1);
        $this->assertGreaterThan(0, $base);
        $this->assertSame(round(min(100.0, $base * 1.13), 1), $epaves->getRecoverableWreckFieldPercentage(1, $this->currentPlanetId), 'Nano-robots de reparation niveau 10 : +13 % d epaves reparables.');
        $this->assertSame($base, $epaves->getRecoverableWreckFieldPercentage(1, $this->createPlanetAtSafeCoordinate($this->currentUserId)->getPlanetId()), 'Une autre planete sans le batiment garde la part de base.');
    }

    private function choose(Species $species): void
    {
        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, $species, (int)Date::now()->timestamp);
        LifeformBonusCache::invalidate();
    }

    private function building(int $objectId, int $level): void
    {
        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Building, $objectId, $level);
    }

    private function technology(int $slot, int $objectId, int $level): void
    {
        LifeformPlanet::query()->where('planet_id', $this->currentPlanetId)->update(['population' => 2000000.0]);
        LifeformSlot::query()->updateOrCreate(['planet_id' => $this->currentPlanetId, 'slot' => $slot], ['object_id' => $objectId, 'chosen_via' => 'local', 'selected_at' => (int)Date::now()->timestamp]);
        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Technology, $objectId, $level);
    }

    private function player(): PlayerService
    {
        $joueur = $this->planetService->getPlayer();
        $this->assertNotNull($joueur);

        return $joueur;
    }
}
