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
use OGame\Lifeforms\Services\LifeformQueueService;
use OGame\Lifeforms\Species;
use OGame\Models\FleetMission;
use OGame\Models\Lifeforms\LifeformAccount;
use OGame\Models\Lifeforms\LifeformBuildingLevel;
use OGame\Models\Lifeforms\LifeformPlanet;
use OGame\Models\Lifeforms\LifeformSlot;
use OGame\Models\Lifeforms\LifeformSlotChange;
use OGame\Models\Lifeforms\LifeformSpeciesProgress;
use OGame\Models\Lifeforms\LifeformTechnologyLevel;
use OGame\Models\Planet;
use OGame\Models\Resource;
use OGame\Models\Resources;
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
use Tests\Support\PlacesLifeformSlots;

/**
 * Les points d application des bonus (tranche 5) : production, energie, entrepots, prix et temps, unites,
 * flottes, phalange, expeditions, epaves, classes — et leur neutralite sans forme de vie.
 */
final class LifeformBonusHooksTest extends AccountTestCase
{
    use PinsSettings;
    use PlacesLifeformSlots;
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
        LifeformSlotChange::query()->whereIn('planet_id', $planetes)->delete();
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
        $this->technology(self::ION_CRYSTAL_MODULES, 100);
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

        // **Les foreuses consomment 10 % de moins, et la page le dit comme le bilan** (demande de Keven, journal
        // §155.19). Modules de cristal ionique niveau 100 : 0,1 % par niveau, plafonne a 50 %. Quatre-vingts
        // foreuses a 100 % : 80 × 50 × (1 − 0,10) = 3 600, et non 4 000.
        $foreuses = 80 * 50 * (1 - 0.10);
        $this->assertSame((int)($consommationMine + $energieFormesDeVie + $foreuses), (int)$planete->energyConsumption()->get(), 'Le bilan de la planete compte les foreuses reduites, la mine reduite et les batiments de forme de vie.');
        $page = $this->get('/resources/settings');
        $page->assertStatus(200);
        $html = (string)$page->getContent();
        $this->assertStringContainsString('title="' . (new Resource(-$foreuses))->getFormattedFull() . '"', $html, 'La page des ressources affiche l energie des foreuses reduite par les Modules de cristal ionique.');
        $this->assertStringNotContainsString('title="' . (new Resource(-4000.0))->getFormattedFull() . '"', $html, 'Elle n affiche pas la consommation sans les formes de vie.');
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
        $this->technology(self::ORBITAL_DEN, 5);
        $this->technology(self::RESEARCH_AI, 10);
        $this->technology(self::STEALTH_FIELD_GENERATOR, 10);
        $this->technology(self::HIGH_PERFORMANCE_TERRAFORMER, 10);

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
        $this->technology(self::GENERAL_OVERHAUL_LIGHT_FIGHTER, 10);
        $this->technology(self::PLASMA_DRIVE, 10);
        $this->technology(self::EFFICIENCY_MODULE, 10);
        $this->technology(self::MECHAN_GENERAL_ENHANCEMENT, 50);

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

        // **Les cinq caracteristiques** : le fichier maitre dit « structural integrity, shield strength, firepower, cargo
        // capacity and basic speed » pour chaque revision generale et chaque Mk II ; le §155.5 n en appliquait que trois
        // (audit des effets, journal §157). Vitesse : Propulsion a plasma +2 % et Revision +3 % sur la base, une seule ligne.
        $this->assertSame(13125.0, (float)$chasseur->properties->speed->calculate($joueur)->totalValue, 'Propulsion a plasma niveau 10 (+2 %) et Revision generale niveau 10 (+3 %) : 12 500 + 625.');
        $this->assertSame(51, $chasseur->properties->capacity->calculate($joueur)->totalValue, 'Fret du chasseur leger : 50 + 3 %, arrondi vers le bas.');
        $this->assertSame(10200.0, (float)ObjectService::getShipObjectByMachineName('heavy_fighter')->properties->speed->calculate($joueur)->totalValue, 'Le chasseur lourd ne prend que la Propulsion a plasma : 10 000 + 2 %.');
        // « all ships (excluding Deathstars) » : l Etoile de la mort garde sa vitesse de 100.
        $this->assertSame(100.0, (float)ObjectService::getShipObjectByMachineName('deathstar')->properties->speed->calculate($joueur)->totalValue, 'La Propulsion a plasma exclut l Etoile de la mort.');

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
        // Le fret du recycleur passe par la ligne de classe en pour cent ENTIER : 1,2199999… tronque donnait 21 (audit §157).
        $general = resolve(PlayerServiceFactory::class)->make($this->currentUserId, true); // le joueur relu avec sa classe
        $this->assertSame(24400, ObjectService::getShipObjectByMachineName('recycler')->properties->capacity->calculate($general)->totalValue, 'Recycleur : 20 000 + 22 % de classe amplifiee = 24 400, pas 24 200.');
        $this->assertSame(1.0, $classes->getMineProductionBonus($utilisateur), 'Un General n a pas le bonus du Collecteur, amplifie ou non.');
        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Technology, self::MECHAN_GENERAL_ENHANCEMENT, 0);
        $this->assertSame(0.5, $classes->getDeuteriumConsumptionMultiplier($utilisateur));
    }

    /**
     * 0,3 x 3 / 100 vaut 0,008999999999999999 en flottant : multiplie par 100 puis par 4 000 et arrondi vers le bas, le
     * joueur perdait une unite de coque a de nombreux niveaux (audit des effets, journal §157). Le pour cent se pose
     * en dixiemes exacts avant tout arrondi.
     */
    public function testAThreePerMilleBonusDoesNotLoseAUnitToFloatingPointNoise(): void
    {
        $this->choose(Species::Mechas);
        $this->technology(self::GENERAL_OVERHAUL_LIGHT_FIGHTER, 3);
        $joueur = $this->player();
        $chasseur = ObjectService::getShipObjectByMachineName('light_fighter');
        $this->assertSame(0.9, $joueur->getLifeformUnitStatsPercent($chasseur), 'Trois niveaux a 0,3 % : 0,9 exactement.');
        $this->assertSame(4036, $chasseur->properties->structural_integrity->calculate($joueur)->totalValue, '4 000 + 0,9 % = 4 036, pas 4 035.');
        $this->assertSame(12612.0, (float)$chasseur->properties->speed->calculate($joueur)->totalValue, '12 500 + 0,9 % = 12 612 (112,5 arrondi vers le bas).');
    }

    /**
     * « Efficient Swarm Intelligence allows regular AND lifeform research projects to be completed much faster »
     * (fichier maitre) : la file des recherches de formes de vie ecrit une echeance raccourcie, par le vrai chemin —
     * la seule technologie d empire qui touche ces recherches (audit des effets, journal §157).
     */
    public function testEfficientSwarmIntelligenceShortensLifeformResearchThroughTheQueue(): void
    {
        $this->choose(Species::Kaelesh);
        $maintenant = (int)Date::now()->timestamp;
        $planetId = $this->currentPlanetId;
        $niveaux = resolve(LifeformLevels::class);
        $niveaux->setLevel($planetId, LifeformKind::Building, 14103, 1); // la Chambre du vortex ouvre l arbre
        $niveaux->setLevel($planetId, LifeformKind::Building, 14104, 1); // les Salles forment au palier 2
        $this->planetAddResources(new Resources(100000000, 100000000, 100000000, 0));
        $this->placeLifeformSlot($planetId, 1, 14201, $maintenant);
        LifeformPlanet::query()->where('planet_id', $planetId)->update(['population' => 200000000.0]);
        $file = resolve(LifeformQueueService::class);

        $sans = $file->add($this->planetService, 14201, $maintenant);
        $dureeSans = (int)$sans->time_end - $maintenant;
        $this->assertGreaterThan(100, $dureeSans, 'Premisse : une duree mesurable.');
        $file->cancel($this->planetService, (int)$sans->id, $maintenant);

        // L Intelligence en essaim efficace niveau 10 : -1 % (0,1 % par niveau), emplacement 13 (palier 3, position 1).
        $niveaux->setLevel($planetId, LifeformKind::Building, 14105, 4); // le Forum forme au palier 3
        $this->placeLifeformSlot($planetId, 13, 14213, $maintenant);
        $niveaux->setLevel($planetId, LifeformKind::Technology, 14213, 10);
        LifeformBonusCache::invalidate();
        $this->assertEqualsWithDelta(0.01, resolve(LifeformBonusResolver::class)->lifeformResearchTimeReductionOf($this->currentUserId), 1e-9, 'Premisse : la part d empire vaut 1 %.');

        $avec = $file->add($this->planetService, 14201, $maintenant);
        $dureeAvec = (int)$avec->time_end - $maintenant;
        $this->assertSame((int)floor($dureeSans * 0.99), $dureeAvec, 'L echeance ecrite dans la file est raccourcie de 1 %.');
        $this->assertSame($sans->metal, $avec->metal, 'Le prix, lui, ne bouge pas.');
    }

    public function testKaeleshTechnologiesReachCargoPhalanxAndExpeditions(): void
    {
        $this->choose(Species::Kaelesh);
        $this->technology(self::NEUROMODAL_COMPRESSOR, 10);
        $this->technology(self::PSIONIC_NETWORK, 10);
        $this->technology(self::ENHANCED_SENSOR_TECHNOLOGY, 10);
        $this->technology(self::INTERPLANETARY_ANALYSIS_NETWORK, 10);

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
        // Et le tirage LE VOIT : au dixieme de point, 0,2 et 0,199 valaient tous deux 2 (audit §157) ; au dix-millieme, 2 000 et 1 990.
        $this->assertSame(2000, ExpeditionMission::scaledWeight(0.2), 'Le poids officiel du trou noir (0,2) en dix-milliemes.');
        $this->assertSame(1990, ExpeditionMission::scaledWeight(0.2 * (1 - 0.005)), 'Le poids tire distingue 0,5 % de reduction ; au dixieme, 2 et 2.');

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

    private function technology(int $objectId, int $level): void
    {
        // Dans l emplacement de son indice, palier ouvert par les capacites de l espece ; une population qui ouvre
        // les dix-huit emplacements (448 M au dernier), posee — ces essais ne mesurent pas la demographie.
        $espece = resolve(LifeformInstallationService::class)->speciesOf($this->currentUserId);
        $this->assertNotNull($espece);
        LifeformPlanet::query()->where('planet_id', $this->currentPlanetId)->update(['population' => 500000000.0]);
        $this->placeLifeformTechnology($this->currentPlanetId, $espece, $objectId, (int)Date::now()->timestamp);
        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Technology, $objectId, $level);
    }

    private function player(): PlayerService
    {
        $joueur = $this->planetService->getPlayer();
        $this->assertNotNull($joueur);

        return $joueur;
    }
}
