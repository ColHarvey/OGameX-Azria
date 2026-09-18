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
use OGame\Lifeforms\Catalogue\LifeformCatalogue;
use OGame\Lifeforms\Catalogue\LifeformEffect;
use OGame\Lifeforms\Catalogue\LifeformFormulas;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\Combat\LifeformCombatPhotographer;
use OGame\Lifeforms\Demography\PlanetLifeformProfile;
use OGame\Lifeforms\Services\LifeformInstallationService;
use OGame\Lifeforms\Services\LifeformLevels;
use OGame\Lifeforms\Services\LifeformQueueService;
use OGame\Lifeforms\Services\LifeformResearchService;
use OGame\Lifeforms\Species;
use OGame\Models\BuildingQueue;
use OGame\Models\FleetMission;
use OGame\Models\Lifeforms\LifeformAccount;
use OGame\Models\Lifeforms\LifeformBuildingLevel;
use OGame\Models\Lifeforms\LifeformPlanet;
use OGame\Models\Lifeforms\LifeformQueue;
use OGame\Models\Lifeforms\LifeformSlot;
use OGame\Models\Lifeforms\LifeformSlotChange;
use OGame\Models\Lifeforms\LifeformSpeciesProgress;
use OGame\Models\Lifeforms\LifeformTechnologyLevel;
use OGame\Models\Planet;
use OGame\Models\ResearchQueue;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\BuildingQueueService;
use OGame\Services\CharacterClassService;
use OGame\Services\FleetMissionService;
use OGame\Services\MessageService;
use OGame\Services\ObjectService;
use OGame\Services\PlayerService;
use OGame\Services\ResearchQueueService;
use OGame\Services\SettingsService;
use ReflectionMethod;
use Tests\AccountTestCase;
use Tests\RecordsClassHistory;
use Tests\Support\PinsSettings;
use Tests\Support\PlacesLifeformSlots;

/**
 * **Les effets que l inventaire de l audit avait laisses « non verifies »** (journal §157) : chacun est ici prouve par
 * le chemin reel — un stock credite, un prix debite, une echeance ecrite, une caracteristique calculee, un emplacement
 * ouvert ou ferme — et non par la presence du bonus dans le catalogue ou le resolveur.
 */
final class LifeformEffectWitnessesTest extends AccountTestCase
{
    use PinsSettings;
    use PlacesLifeformSlots;
    use RecordsClassHistory;

    private const int CRYSTAL_REFINERY = 12109;

    private const int DEUTERIUM_SYNTHESISER = 12110;

    private const int MEGALITH = 12108;

    private const int RUNE_FORGE = 12104;

    private const int ORIKTORIUM = 12105;

    private const int ACOUSTIC_SCANNING = 12202;

    private const int MAGMA_POWERED_PRODUCTION = 12205;

    private const int DEPTH_SOUNDING = 12207;

    private const int OBSIDIAN_SHIELD_REINFORCEMENT = 12216;

    private const int ROCKTAL_COLLECTOR_ENHANCEMENT = 12218;

    private const int FOOD_SILO = 11107;

    private const int FUSION_DRIVES = 11203;

    private const int METROPOLIS = 11111;

    private const int DEPOT_AI = 13204;

    private const int HIGH_TEMPERATURE_SUPERCONDUCTORS = 13211;

    private const int TELEKINETIC_TRACTOR_BEAM = 14204;

    private const int GRAVITATION_SENSORS = 14215;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pinSettings(['lifeforms_enabled' => 1, 'economy_speed' => 1, 'research_speed' => 1, 'fleet_speed' => 1]);
        LifeformBonusCache::invalidate();
    }

    protected function tearDown(): void
    {
        $planetes = Planet::query()->where('user_id', $this->currentUserId)->pluck('id');
        LifeformQueue::query()->whereIn('planet_id', $planetes)->delete();
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

    /**
     * Les branches cristal, deuterium et « toute production » de `calculateLifeform()` : chacune credite sa ressource,
     * et seulement la sienne, jusqu au stock — une heure de production creditee par `updateResourcesUntil()`.
     */
    public function testCrystalDeuteriumAndAllProductionBranchesCreditTheirOwnResourceUpToTheStock(): void
    {
        $planete = $this->planetService;
        $this->planetSetObjectLevel('metal_mine', 10);
        $this->planetSetObjectLevel('crystal_mine', 10);
        $this->planetSetObjectLevel('deuterium_synthesizer', 10);
        $this->planetSetObjectLevel('solar_plant', 60);
        $this->planetSetObjectLevel('metal_store', 15);
        $this->planetSetObjectLevel('crystal_store', 15);
        $this->planetSetObjectLevel('deuterium_store', 15);
        $planete->updateResourceStorageStats(true);
        $planete->updateResourceProductionStats(true);
        $this->assertSame(100, (int)$planete->getResourceProductionFactor());

        $this->choose(Species::Rocktal);
        $this->building(self::CRYSTAL_REFINERY, 10);      // +2 % de cristal par niveau : 20 %
        $this->building(self::DEUTERIUM_SYNTHESISER, 10); // +2 % de deuterium par niveau : 20 %
        $this->technology(self::ACOUSTIC_SCANNING, 10);       // cristal, 0,08 % par niveau : 0,8 %
        $this->technology(self::MAGMA_POWERED_PRODUCTION, 10); // toute production, 0,08 % par niveau : 0,8 %
        $bonus = resolve(LifeformBonusResolver::class)->forPlanet($this->currentPlanetId);
        $this->assertEqualsWithDelta(0.208, $bonus->fraction(LifeformEffect::CRYSTAL_PRODUCTION), 1e-9, 'Raffinerie 20 % + Sondage acoustique 0,8 %.');
        $this->assertEqualsWithDelta(0.20, $bonus->fraction(LifeformEffect::DEUTERIUM_PRODUCTION), 1e-9);
        $this->assertEqualsWithDelta(0.008, $bonus->fraction(LifeformEffect::ALL_PRODUCTION), 1e-9);
        $this->assertSame(0.0, $bonus->fraction(LifeformEffect::METAL_PRODUCTION));

        $metal = $planete->getObjectProductionIndex(ObjectService::getGameObjectsWithProductionByMachineName('metal_mine'));
        $cristal = $planete->getObjectProductionIndex(ObjectService::getGameObjectsWithProductionByMachineName('crystal_mine'));
        $deuterium = $planete->getObjectProductionIndex(ObjectService::getGameObjectsWithProductionByMachineName('deuterium_synthesizer'));
        $this->assertEquals(floor(($metal->mine->metal->get() + $metal->planet_slot->metal->get()) * 0.008), $metal->lifeform->metal->get(), 'Le metal ne prend que la part « toute production ».');
        $this->assertEquals(floor(($cristal->mine->crystal->get() + $cristal->planet_slot->crystal->get()) * 0.216), $cristal->lifeform->crystal->get(), 'Le cristal : 0,8 % + 20,8 %.');
        $this->assertEquals(floor(($deuterium->mine->deuterium->get() + $deuterium->planet_slot->deuterium->get()) * 0.208), $deuterium->lifeform->deuterium->get(), 'Le deuterium : 0,8 % + 20 %.');
        $this->assertGreaterThan(0, $metal->lifeform->metal->get(), 'Premisse : a 0,8 %, la mine de metal niveau 10 rend au moins une unite.');
        $this->assertSame(0.0, (float)$cristal->lifeform->metal->get(), 'La mine de cristal ne credite pas de metal.');
        $this->assertSame(0.0, (float)$deuterium->lifeform->crystal->get());
        $this->assertGreaterThan(0, $cristal->lifeform->crystal->get());
        $this->assertGreaterThan(0, $deuterium->lifeform->deuterium->get());

        // Jusqu au stock : une heure creditee par le vrai compteur.
        $planete->updateResourceProductionStats(true);
        $planete->reloadPlanet();
        $avant = [$planete->metal()->get(), $planete->crystal()->get(), $planete->deuterium()->get()];
        $maintenant = (int)Date::now()->timestamp;
        $planete->updateResourcesUntil($maintenant + 3600, true);
        $planete->reloadPlanet();
        $base = $planete->getPlanetBasicIncome();
        // Le taux memorise arrondit chaque ligne a l entier superieur : deux unites de marge.
        $this->assertEqualsWithDelta($cristal->total->crystal->get() + $base->crystal->get(), $planete->getCrystalProductionPerHour(), 2.0, 'Le taux horaire de cristal est le total de l index, ligne des formes de vie comprise.');
        $this->assertEqualsWithDelta($deuterium->total->deuterium->get() + $base->deuterium->get(), $planete->getDeuteriumProductionPerHour(), 2.0);
        $this->assertEqualsWithDelta($metal->total->metal->get() + $base->metal->get(), $planete->getMetalProductionPerHour(), 2.0);
        $this->assertEqualsWithDelta($avant[1] + $planete->getCrystalProductionPerHour(), $planete->crystal()->get(), 1.0, 'Une heure de cristal creditee au stock.');
        $this->assertEqualsWithDelta($avant[2] + $planete->getDeuteriumProductionPerHour(), $planete->deuterium()->get(), 1.0, 'Une heure de deuterium.');
        $this->assertEqualsWithDelta($avant[0] + $planete->getMetalProductionPerHour(), $planete->metal()->get(), 1.0, 'Une heure de metal.');
        $this->assertGreaterThan($avant[1] + $planete->getCrystalProductionPerHour() - $cristal->lifeform->crystal->get() + 1, $planete->crystal()->get(), 'Sans la ligne, le stock aurait moins.');
    }

    /**
     * Les capacites de palier ouvrent et ferment les emplacements : sans Forge des runes, l emplacement 7 (palier 2) reste
     * ferme et la technologie qu il porte ne compte pas ; l Oriktorium ouvre l emplacement 14 (100 M de palier 3) a partir
     * du niveau 2 seulement (base × N × 1,1^(N−1) : 90 M au niveau 1, 198 M au niveau 2).
     */
    public function testTierCapacitiesOpenAndCloseTheSlotsAndTheTechnologiesTheyCarry(): void
    {
        $this->choose(Species::Rocktal);
        $maintenant = (int)Date::now()->timestamp;
        LifeformPlanet::query()->where('planet_id', $this->currentPlanetId)->update(['population' => 200000000.0]);
        $this->placeLifeformSlot($this->currentPlanetId, 7, self::DEPTH_SOUNDING, $maintenant);
        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Technology, self::DEPTH_SOUNDING, 10);
        $palier3 = LifeformResearchService::technologyAt(Species::Rocktal, 3, 2);
        $this->placeLifeformSlot($this->currentPlanetId, 14, $palier3->id, $maintenant);
        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Technology, $palier3->id, 5);
        $codePalier3 = $palier3->bonuses[0]->code;
        $ciblePalier3 = $palier3->bonuses[0]->target;

        $actifs = fn (): array => $this->actifs();
        $this->assertSame([], $actifs(), 'Sans Forge des runes ni Oriktorium, aucun emplacement de palier 2 ou 3 n est ouvert.');
        $this->assertSame(0.0, resolve(LifeformBonusResolver::class)->forPlanet($this->currentPlanetId)->fraction(LifeformEffect::METAL_PRODUCTION), 'Le Sondage en profondeur ne compte pas.');

        $this->building(self::RUNE_FORGE, 1); // 16 M de palier 2 ≥ 1,2 M
        $this->assertSame([self::DEPTH_SOUNDING => 10], $actifs(), 'La Forge ouvre l emplacement 7.');
        $this->assertGreaterThan(0.0, resolve(LifeformBonusResolver::class)->forPlanet($this->currentPlanetId)->fraction(LifeformEffect::METAL_PRODUCTION));

        $this->building(self::RUNE_FORGE, 20); // 16 M × 20 × 1,14^19 de palier 2, bien plus que les 200 M de la planete
        $this->building(self::ORIKTORIUM, 1); // 90 M < 100 M
        $this->assertSame([self::DEPTH_SOUNDING => 10], $actifs(), 'Oriktorium 1 : 90 M de palier 3, l emplacement 14 reste ferme.');
        $this->assertSame(0.0, resolve(LifeformBonusResolver::class)->forPlanet($this->currentPlanetId)->fraction($codePalier3, $ciblePalier3));

        $this->building(self::ORIKTORIUM, 2); // 198 M ≥ 100 M
        $this->assertSame([self::DEPTH_SOUNDING => 10, $palier3->id => 5], $actifs(), 'Oriktorium 2 ouvre l emplacement 14.');
        $this->assertGreaterThan(0.0, resolve(LifeformBonusResolver::class)->forPlanet($this->currentPlanetId)->fraction($codePalier3, $ciblePalier3));
    }

    /**
     * Le Megalithe reduit le prix ET la duree des batiments de forme de vie, par la file — le devis que la file debite.
     */
    public function testTheMegalithCutsTheLifeformBuildingPriceAndTimeThroughTheQueue(): void
    {
        $this->choose(Species::Rocktal);
        $this->planetAddResources(new Resources(100000000, 100000000, 100000000, 0));
        $maintenant = (int)Date::now()->timestamp;
        $file = resolve(LifeformQueueService::class);
        $this->building(12101, 41);
        $this->building(self::RUNE_FORGE, 1);
        $this->building(self::MEGALITH, 1); // prerequis de la Raffinerie

        $sans = $file->add($this->planetService, self::CRYSTAL_REFINERY, $maintenant);
        $dureeSans = (int)$sans->time_end - $maintenant;
        $file->cancel($this->planetService, (int)$sans->id, $maintenant);
        $this->planetService->reloadPlanet();
        $metalAvant = (int)$this->planetService->metal()->get();

        $raffinerie = LifeformCatalogue::byId(self::CRYSTAL_REFINERY);
        $brut = LifeformFormulas::cost($raffinerie, 1);
        $dureeBrute = LifeformFormulas::buildingDuration($raffinerie, 1, $this->planetService->getObjectLevel('robot_factory'), $this->planetService->getObjectLevel('nano_factory'), 1.0);
        $this->assertSame((int)floor($brut->metal->get() * 0.99), (int)$sans->metal, 'Megalithe 1 (prerequis) : 1 % de moins que le prix brut.');
        $this->assertSame((int)floor($dureeBrute * 0.99), $dureeSans);

        $this->building(self::MEGALITH, 10); // 1 % par niveau, plafond 50 % : 10 %
        $avec = $file->add($this->planetService, self::CRYSTAL_REFINERY, $maintenant);
        $this->assertSame((int)floor($brut->metal->get() * 0.90), (int)$avec->metal, 'Le metal debite par la file baisse de 10 %.');
        $this->assertSame((int)floor($brut->crystal->get() * 0.90), (int)$avec->crystal);
        $this->assertSame((int)floor($dureeBrute * 0.90), (int)$avec->time_end - $maintenant, 'L echeance ecrite baisse de 10 %.');
        $this->assertGreaterThan(1000, $dureeBrute);
        $this->planetService->reloadPlanet();
        $this->assertSame($metalAvant - (int)$avec->metal, (int)$this->planetService->metal()->get(), 'Le stock a ete debite du prix reduit.');
    }

    /**
     * L IA de depot vise le Depot de ravitaillement (prix debite par la file des batiments, echeance ecrite) et les
     * Supraconducteurs a haute temperature la Technologie energetique (prix debite par la file des recherches, echeance).
     */
    public function testDepotAiAndSuperconductorsCutThePriceAndTheDeadlineOfTheirTargets(): void
    {
        $planete = $this->planetService;
        $this->planetSetObjectLevel('research_lab', 1);
        $this->planetAddResources(new Resources(1000000, 1000000, 1000000, 0));
        $joueur = $this->player();

        $prixDepotBrut = ObjectService::getObjectRawPrice('alliance_depot', 1);
        $dureeDepotSans = $planete->getBuildingConstructionTime('alliance_depot');
        $prixEnergieBrut = ObjectService::getObjectRawPrice('energy_technology', 1);
        $dureeEnergieSans = (int)$planete->getTechnologyResearchTime('energy_technology');
        $this->assertGreaterThan(100, $dureeDepotSans);
        $this->assertGreaterThan(100, $dureeEnergieSans);

        $this->choose(Species::Mechas);
        $this->technology(self::DEPOT_AI, 100);                        // 0,1 % de prix et 0,2 % de temps par niveau : 10 % / 20 %
        $this->technology(self::HIGH_TEMPERATURE_SUPERCONDUCTORS, 100); // idem pour la Technologie energetique

        $depot = ObjectService::getObjectByMachineName('alliance_depot');
        $planete->reloadPlanet();
        $metalAvant = (int)$planete->metal()->get();
        resolve(BuildingQueueService::class)->add($planete, $depot->id);
        $ligne = BuildingQueue::query()->where('planet_id', $this->currentPlanetId)->where('object_id', $depot->id)->orderByDesc('id')->firstOrFail();
        $this->assertSame((int)floor($prixDepotBrut->metal->get() * 0.90), (int)$ligne->metal, 'Le Depot de ravitaillement est debite 10 % de moins.');
        // Le temps classique derive du prix (metal + cristal) : le prix remise de 10 % raccourcit deja de 10 %, puis la
        // reduction de temps de 20 % s applique — regle Azria dite au journal §155.5 (« le temps derive du prix remise »).
        $this->assertEqualsWithDelta(floor($dureeDepotSans * 0.90 * 0.80), (int)$ligne->time_end - (int)$ligne->time_start, 1.0, 'Et construit 20 % plus vite, sur un temps deja derive du prix remise.');
        $planete->reloadPlanet();
        $this->assertSame($metalAvant - (int)floor($prixDepotBrut->metal->get() * 0.90), (int)$planete->metal()->get(), 'Le stock porte le debit reduit.');
        $this->assertSame((int)ObjectService::getObjectRawPrice('robot_factory', 1)->metal->get(), (int)ObjectService::getObjectPrice('robot_factory', $planete)->metal->get(), 'Un autre batiment garde son prix.');

        $energie = ObjectService::getObjectByMachineName('energy_technology');
        resolve(ResearchQueueService::class)->add($joueur, $planete, $energie->id);
        $recherche = ResearchQueue::query()->where('planet_id', $this->currentPlanetId)->where('object_id', $energie->id)->orderByDesc('id')->firstOrFail();
        $this->assertSame((int)floor($prixEnergieBrut->crystal->get() * 0.90), (int)$recherche->crystal, 'La Technologie energetique est debitee 10 % de moins.');
        $this->assertEqualsWithDelta(floor($dureeEnergieSans * 0.90 * 0.80), (int)$recherche->time_end - (int)$recherche->time_start, 1.0, 'Et recherchee 20 % plus vite, sur un temps deja derive du prix remise.');
        $this->assertSame((int)ObjectService::getObjectRawPrice('espionage_technology', 1)->crystal->get(), (int)ObjectService::getObjectPrice('espionage_technology', $planete)->crystal->get(), 'Une autre recherche garde son prix.');
    }

    /**
     * Le Renforcement de bouclier en obsidienne arme toutes les defenses (coque, bouclier, attaque), et le photographe
     * l inscrit sous la clef « defence » pour le gel d un combat.
     */
    public function testObsidianShieldReinforcementArmsEveryDefenceAndIsPhotographed(): void
    {
        $joueur = $this->player();
        $lanceur = ObjectService::getUnitObjectByMachineName('rocket_launcher');
        $plasma = ObjectService::getUnitObjectByMachineName('plasma_turret');
        $coqueSans = $lanceur->properties->structural_integrity->calculate($joueur)->totalValue;
        $bouclierSans = $lanceur->properties->shield->calculate($joueur)->totalValue;
        $attaqueSans = $plasma->properties->attack->calculate($joueur)->totalValue;
        $this->assertSame(0.0, $joueur->getLifeformUnitStatsPercent($lanceur));

        $this->choose(Species::Rocktal);
        $this->technology(self::OBSIDIAN_SHIELD_REINFORCEMENT, 10); // 0,5 % par niveau : 5 %
        $joueur = resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);
        $this->assertSame(5.0, $joueur->getLifeformUnitStatsPercent($lanceur));
        $this->assertSame((int)floor($coqueSans * 1.05), $lanceur->properties->structural_integrity->calculate($joueur)->totalValue, 'Lanceur de missiles : +5 % de coque.');
        $this->assertSame((int)floor($attaqueSans * 1.05), $plasma->properties->attack->calculate($joueur)->totalValue, 'Tourelle a plasma : +5 % d attaque.');
        $this->assertSame((int)floor($bouclierSans * 1.05), $lanceur->properties->shield->calculate($joueur)->totalValue, 'Bouclier : +5 %.');
        $this->assertSame(0.0, $joueur->getLifeformUnitStatsPercent(ObjectService::getShipObjectByMachineName('light_fighter')), 'Un vaisseau n est pas une defense.');

        $photo = resolve(LifeformCombatPhotographer::class)->ofBody($this->planetService);
        $this->assertSame(5.0, $photo->unitStats['defence'] ?? null, 'La photographie porte la clef « defence » a 5 %.');
    }

    /**
     * L Amelioration du Collecteur Rock'tal amplifie chaque bonus du Collecteur : mines, energie, foreuses, fret et
     * vitesse des transporteurs — et rien pour un joueur d une autre classe.
     */
    public function testTheRocktalCollectorEnhancementAmplifiesEveryCollectorBonus(): void
    {
        $this->choose(Species::Rocktal);
        $this->technology(self::ROCKTAL_COLLECTOR_ENHANCEMENT, 50); // 0,2 % par niveau : +10 %
        $classes = resolve(CharacterClassService::class);
        $utilisateur = User::query()->findOrFail($this->currentUserId);
        $this->assertSame(1.0, $classes->getMineProductionBonus($utilisateur), 'Sans classe, rien a amplifier.');

        $this->recordCharacterClass($this->currentUserId, CharacterClass::COLLECTOR);
        $utilisateur = User::query()->findOrFail($this->currentUserId);
        $this->assertEqualsWithDelta(1.275, $classes->getMineProductionBonus($utilisateur), 1e-9, '+25 % × 1,1.');
        $this->assertEqualsWithDelta(1.11, $classes->getEnergyProductionBonus($utilisateur), 1e-9, '+10 % × 1,1.');
        $this->assertEqualsWithDelta(1.55, $classes->getCrawlerBonusMultiplier($utilisateur), 1e-9, '+50 % × 1,1.');
        $this->assertEqualsWithDelta(1.275, $classes->getTransporterCargoBonus($utilisateur), 1e-9);
        $this->assertEqualsWithDelta(2.1, $classes->getTransporterSpeedBonus($utilisateur), 1e-9, '+100 % × 1,1.');

        $collecteur = resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);
        // La ligne de classe du fret est un pour cent ENTIER (CapacityPropertyService : intdiv(base × pour cent, 100)) : 27,5 % → 27 %.
        $this->assertSame(5000 + intdiv(5000 * 27, 100), ObjectService::getShipObjectByMachineName('small_cargo')->properties->capacity->calculate($collecteur)->totalValue, 'Petit transporteur : 5 000 + 27 % (27,5 % arrondi a l entier de la ligne de classe).');

        $this->recordCharacterClass($this->currentUserId, CharacterClass::GENERAL);
        $this->assertSame(1.0, $classes->getMineProductionBonus(User::query()->findOrFail($this->currentUserId)), 'Un General n a pas le bonus du Collecteur.');
    }

    /**
     * Le Silo a nourriture : +1 % de stock par niveau, −1 % de consommation par niveau (plafond 80 %), +0,8 % de croissance
     * par niveau — lus dans le profil qui gouverne l horloge demographique.
     */
    public function testTheFoodSiloRaisesStorageAndGrowthAndCutsConsumption(): void
    {
        $niveaux = [11101 => 21, 11102 => 22];
        $sans = PlanetLifeformProfile::fromLevels(Species::Humans, $niveaux, 1.0);
        $avec = PlanetLifeformProfile::fromLevels(Species::Humans, $niveaux + [self::FOOD_SILO => 10], 1.0);
        $this->assertEqualsWithDelta($sans->foodStorage * 1.10, $avec->foodStorage, 1e-6, '+10 % de stock de nourriture.');
        $this->assertEqualsWithDelta($sans->foodPerInhabitantPerHour * 0.90, $avec->foodPerInhabitantPerHour, 1e-9, '−10 % de consommation par habitant.');
        // Les pour cent de croissance s additionnent (logement + Silo) avant le multiplicateur : +8 points, pas +8 %.
        $logement = LifeformCatalogue::byId(11101)->bonus(LifeformEffect::GROWTH_RATE);
        $this->assertNotNull($logement);
        $bonusLogement = (21 ** $logement->factor) * $logement->base;
        $this->assertEqualsWithDelta($sans->growthPerHour / (1 + $bonusLogement / 100) * (1 + ($bonusLogement + 8) / 100), $avec->growthPerHour, 1e-6, '+8 points de croissance.');
        $plafond = PlanetLifeformProfile::fromLevels(Species::Humans, $niveaux + [self::FOOD_SILO => 200], 1.0);
        $this->assertEqualsWithDelta($sans->foodPerInhabitantPerHour * 0.20, $plafond->foodPerInhabitantPerHour, 1e-9, 'La consommation ne baisse jamais de plus de 80 %.');
    }

    /**
     * La Propulsion a fusion accelere les vaisseaux civils seulement, jusqu a la duree du vol.
     */
    public function testFusionDrivesSpeedUpCivilShipsOnlyDownToTheFlightDuration(): void
    {
        $joueur = $this->player();
        $transporteur = ObjectService::getShipObjectByMachineName('small_cargo');
        $chasseur = ObjectService::getShipObjectByMachineName('light_fighter');
        $vitesseSans = $transporteur->properties->speed->calculate($joueur)->totalValue;
        $chasseurSans = $chasseur->properties->speed->calculate($joueur)->totalValue;
        $flotte = new UnitCollection();
        $flotte->addUnit($transporteur, 10);
        $service = resolve(FleetMissionService::class);
        $dureeSans = $service->durationOverDistance($joueur, $flotte, 20000, null, 10);

        $this->choose(Species::Humans);
        $this->technology(self::FUSION_DRIVES, 20); // 0,5 % par niveau : +10 %
        $joueur = resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);
        $this->assertSame((int)floor($vitesseSans * 1.10), (int)$transporteur->properties->speed->calculate($joueur)->totalValue, 'Petit transporteur : +10 %.');
        $this->assertSame((int)$chasseurSans, (int)$chasseur->properties->speed->calculate($joueur)->totalValue, 'Le chasseur leger n est pas civil.');
        $this->assertLessThan($dureeSans, $service->durationOverDistance($joueur, $flotte, 20000, null, 10), 'Le vol est plus court.');

        // La Metropole multiplie les technologies de la planete (lf_tech_bonus, 0,5 % par niveau ; ici 10 : × 1,05) — dans le
        // resolveur, la lecture que les bonus appliques prennent (l autre ecrivain, LifeformResearchService, sert la fiche).
        $this->building(self::METROPOLIS, 10);
        $this->assertEqualsWithDelta(0.10 * 1.05, resolve(LifeformBonusResolver::class)->forPlayer($this->currentUserId)->fraction(LifeformEffect::CIVIL_SHIP_SPEED), 1e-9, '+10 % × 1,05.');
        $joueur = resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);
        $this->assertSame((int)floor($vitesseSans * 1.105), (int)$transporteur->properties->speed->calculate($joueur)->totalValue, 'Petit transporteur : +10,5 % avec la Metropole.');
    }

    /**
     * Le Rayon tracteur telekinetique et les Capteurs gravitationnels : plus de vaisseaux et plus de matiere noire
     * trouves, par les methodes que l expedition emploie.
     */
    public function testTractorBeamAndGravitationSensorsRaiseTheExpeditionFinds(): void
    {
        $this->choose(Species::Kaelesh);
        $this->technology(self::TELEKINETIC_TRACTOR_BEAM, 50); // 0,2 % par niveau : +10 %
        $this->technology(self::GRAVITATION_SENSORS, 50);      // 0,1 % par niveau : +5 %
        $joueur = resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);
        $this->assertEqualsWithDelta(1.10, $joueur->lifeformBonuses()->multiplier(LifeformEffect::EXPEDITION_SHIPS), 1e-9);
        $this->assertEqualsWithDelta(1.05, $joueur->lifeformBonuses()->multiplier(LifeformEffect::EXPEDITION_DARK_MATTER), 1e-9);

        $expedition = new class (resolve(FleetMissionService::class), resolve(MessageService::class), resolve(PlanetServiceFactory::class), resolve(PlayerServiceFactory::class), resolve(SettingsService::class)) extends ExpeditionMission {
            protected function getBaseMaxFindFromHighscore(): int
            {
                return 100000;
            }
        };
        $mission = new FleetMission();
        $mission->user_id = $this->currentUserId;
        $mission->id = 0;
        $trouvaille = new ReflectionMethod(ExpeditionMission::class, 'determineMaxShipFind');
        $this->assertSame(110000, $trouvaille->invoke($expedition, $mission), 'Rayon tracteur : +10 % de vaisseaux trouves.');
        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Technology, self::TELEKINETIC_TRACTOR_BEAM, 0);
        LifeformBonusCache::invalidate();
        $this->assertSame(100000, $trouvaille->invoke($expedition, $mission));
    }

    /**
     * @return array<int, int>
     */
    private function actifs(): array
    {
        LifeformBonusCache::invalidate();
        $etat = LifeformPlanet::query()->where('planet_id', $this->currentPlanetId)->firstOrFail();
        $niveaux = resolve(LifeformLevels::class)->buildingLevelsOf($this->currentPlanetId);
        $profil = PlanetLifeformProfile::fromLevels(Species::Rocktal, $niveaux, 1.0);
        $actifs = resolve(LifeformResearchService::class)->activeTechnologyLevels($this->currentPlanetId, $etat, $profil, Species::Rocktal, $niveaux);
        ksort($actifs);

        return $actifs;
    }

    private function choose(Species $species): void
    {
        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, $species, (int)Date::now()->timestamp);
        LifeformBonusCache::invalidate();
    }

    private function building(int $objectId, int $level): void
    {
        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Building, $objectId, $level);
        LifeformBonusCache::invalidate();
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
        LifeformBonusCache::invalidate();
    }

    private function player(): PlayerService
    {
        $joueur = $this->planetService->getPlayer();
        $this->assertNotNull($joueur);

        return $joueur;
    }
}
