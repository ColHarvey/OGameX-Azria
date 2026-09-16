<?php

namespace Tests\Feature\Lifeforms;

use Illuminate\Support\Facades\Date;
use OGame\Combat\Allocation\FrozenLootAllocation;
use OGame\Combat\Services\PhotographedDefender;
use OGame\Combat\Support\FrozenLifeformCombatBonuses;
use OGame\Combat\Support\LiveLootContextFactory;
use OGame\GameMissions\BattleEngine\Models\AttackerFleet;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameMissions\BattleEngine\Models\DefenderFleet;
use OGame\GameMissions\BattleEngine\PhpBattleEngine;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Lifeforms\Bonuses\LifeformBonusCache;
use OGame\Lifeforms\Catalogue\LifeformCatalogue;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\Combat\LifeformCombatLosses;
use OGame\Lifeforms\Combat\LifeformCombatPhotographer;
use OGame\Lifeforms\Services\LifeformInstallationService;
use OGame\Lifeforms\Services\LifeformLevels;
use OGame\Lifeforms\Species;
use OGame\Models\Lifeforms\LifeformAccount;
use OGame\Models\Lifeforms\LifeformBuildingLevel;
use OGame\Models\Lifeforms\LifeformPlanet;
use OGame\Models\Lifeforms\LifeformQueue;
use OGame\Models\Lifeforms\LifeformSlot;
use OGame\Models\Lifeforms\LifeformSlotChange;
use OGame\Models\Lifeforms\LifeformSpeciesProgress;
use OGame\Models\Lifeforms\LifeformTechnologyLevel;
use OGame\Models\Message;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;
use Tests\Support\PinsSettings;
use Tests\Support\PlacesLifeformSlots;

/**
 * Ce que le moteur partage lit des formes de vie du corps defendu : les debris (Usine de recyclage avancee),
 * la chance de lune (Supra-refracteur), photographies ou lus vivants (journal §155.6).
 */
final class LifeformCombatEngineTest extends AccountTestCase
{
    use PinsSettings;
    use PlacesLifeformSlots;

    private const int ADVANCED_RECYCLING_PLANT = 12112;

    private const int SUPRA_REFRACTOR = 14112;

    private const int PLANETARY_SHIELD = 11112;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pinSettings(['lifeforms_enabled' => 1, 'economy_speed' => 1, 'debris_field_from_ships' => 30, 'debris_field_from_defense' => 0, 'maximum_moon_chance' => 20]);
        LifeformBonusCache::invalidate();
    }

    protected function tearDown(): void
    {
        $planetes = Planet::query()->where('user_id', $this->currentUserId)->pluck('id');
        LifeformSlot::query()->whereIn('planet_id', $planetes)->delete();
        LifeformSlotChange::query()->whereIn('planet_id', $planetes)->delete();
        LifeformTechnologyLevel::query()->whereIn('planet_id', $planetes)->delete();
        LifeformBuildingLevel::query()->whereIn('planet_id', $planetes)->delete();
        LifeformQueue::query()->whereIn('planet_id', $planetes)->delete();
        LifeformPlanet::query()->whereIn('planet_id', $planetes)->delete();
        LifeformAccount::query()->where('user_id', $this->currentUserId)->delete();
        LifeformSpeciesProgress::query()->where('user_id', $this->currentUserId)->delete();
        $this->forgetHeldLifeformPlanets();
        LifeformBonusCache::invalidate();
        $this->restorePinnedSettings();
        parent::tearDown();
    }

    public function testTheRecyclingPlantOfTheDefendedPlanetRaisesTheDebrisReadLiveOrPhotographed(): void
    {
        $chasseur = ObjectService::getShipObjectByMachineName('light_fighter');
        $perdusAttaquant = new UnitCollection();
        $perdusAttaquant->addUnit($chasseur, 100);
        $perdusDefenseur = new UnitCollection();
        $perdusDefenseur->addUnit(ObjectService::getUnitObjectByMachineName('rocket_launcher'), 10);

        $sans = $this->engine()->debrisOf($perdusAttaquant, $perdusDefenseur);
        $this->assertEquals(floor($chasseur->price->resources->metal->get() * 100 * 0.30), $sans->metal->get(), 'Sans forme de vie : 30 % des vaisseaux, rien des defenses.');

        $this->choose(Species::Rocktal);
        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Building, self::ADVANCED_RECYCLING_PLANT, 10);
        $this->assertSame(0.06, resolve(LifeformCombatPhotographer::class)->ofBody($this->planetService)->debrisRecovery, 'Usine de recyclage avancee niveau 10 : +6 %.');

        $vivant = $this->engine()->debrisOf($perdusAttaquant, $perdusDefenseur);
        $this->assertEquals(floor($chasseur->price->resources->metal->get() * 100 * 0.318), $vivant->metal->get(), 'Lu vivant : 30 % × 1,06 = 31,8 %.');
        $this->assertEquals(floor($chasseur->price->resources->crystal->get() * 100 * 0.318), $vivant->crystal->get());

        // Un combat durable lit la photographie, jamais le corps : une usine photographiee a zero ne rend que 30 %.
        $photographie = new PhotographedDefender(0, 0, 0, 0, 1, new FrozenLifeformCombatBonuses([], 0.3, 0.0, 0.0, 0.0));
        $photographieMoteur = $this->engine();
        $photographieMoteur->withPhotographedDefender($photographie);
        $this->assertEquals($sans->metal->get(), $photographieMoteur->debrisOf($perdusAttaquant, $perdusDefenseur)->metal->get());
        $gelee = new PhotographedDefender(0, 0, 0, 0, 1, new FrozenLifeformCombatBonuses([], 0.3, 0.0, 0.5, 0.0));
        $geleeMoteur = $this->engine();
        $geleeMoteur->withPhotographedDefender($gelee);
        $this->assertEquals(floor($chasseur->price->resources->metal->get() * 100 * 0.45), $geleeMoteur->debrisOf($perdusAttaquant, $perdusDefenseur)->metal->get(), 'Photographie a +50 % : 45 %.');
    }

    public function testTheSupraRefractorRaisesTheMoonChanceUnderTheUniverseCap(): void
    {
        $debris = new Resources(600000, 400000, 0, 0);
        $this->assertSame(10, $this->engine()->moonChanceOf($debris), 'Un million de debris : 10 %.');

        $this->choose(Species::Kaelesh);
        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Building, self::SUPRA_REFRACTOR, 60);
        $this->assertSame(0.3, resolve(LifeformCombatPhotographer::class)->ofBody($this->planetService)->moonChance, 'Supra-refracteur : 0,5 % par niveau, plafonne a 30 %.');

        $this->assertSame(13, $this->engine()->moonChanceOf($debris), 'Lu vivant : 10 × 1,3 = 13.');
        $this->assertSame(20, $this->engine()->moonChanceOf(new Resources(1800000, 0, 0, 0)), '18 × 1,3 = 23, plafonne a 20 par l univers.');

        $gelee = new PhotographedDefender(0, 0, 0, 0, 1, new FrozenLifeformCombatBonuses([], null, 0.5, 0.0, 0.0));
        $geleeMoteur = $this->engine();
        $geleeMoteur->withPhotographedDefender($gelee);
        $this->assertSame(15, $geleeMoteur->moonChanceOf($debris), 'Photographie a +50 % : 15.');
        $sansFormeDeVie = $this->engine();
        $sansFormeDeVie->withPhotographedDefender(new PhotographedDefender(0, 0, 0, 0, 1));
        $this->assertSame(10, $sansFormeDeVie->moonChanceOf($debris), 'Photographie sans forme de vie : le corps vivant n est pas relu.');
    }

    public function testTheBodyPhotographCarriesThePopulationProtectionAndTheOwnerUnits(): void
    {
        $photographe = resolve(LifeformCombatPhotographer::class);
        $this->assertNull($photographe->ofBody($this->planetService)->protectedShare, 'Sans forme de vie, aucune part protegee : null, pas zero.');

        $this->choose(Species::Humans);
        $this->assertSame(0.0, $photographe->ofBody($this->planetService)->protectedShare, 'Une planete peuplee sans Bouclier : zero de protege (l abri reste).');
        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Building, 11112, 10);
        $this->assertEqualsWithDelta(0.3, $photographe->ofBody($this->planetService)->protectedShare, 1e-9, 'Bouclier planetaire niveau 10 : 30 %.');

        LifeformPlanet::query()->where('planet_id', $this->currentPlanetId)->update(['population' => 2000000.0]);
        $this->placeLifeformSlot($this->currentPlanetId, 1, 11209, (int)Date::now()->timestamp);
        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Technology, 11209, 10);
        $corps = $photographe->ofBody($this->planetService);
        $this->assertSame(['light_fighter' => 3.0], $corps->unitStats, 'Chasseur leger Mk II niveau 10 : +3 %.');
        $joueur = $this->planetService->getPlayer();
        $this->assertNotNull($joueur);
        $flotte = $photographe->ofPlayer($joueur);
        $this->assertSame(['light_fighter' => 3.0], $flotte->unitStats);
        $this->assertNull($flotte->protectedShare, 'Une flotte ne porte rien du corps.');

        // **L interrupteur ferme ne confisque pas ce qui est acquis** (journal §155.9) : une population qui
        // vit continue de mourir au combat, et les unites gardent leurs bonus.
        $this->pinSettings(['lifeforms_enabled' => 0]);
        LifeformBonusCache::invalidate();
        $ferme = $photographe->ofBody($this->planetService);
        $this->assertFalse($ferme->isNone(), 'Fermer l interrupteur a confisque ce qui etait acquis.');
        $this->assertEqualsWithDelta(0.3, $ferme->protectedShare, 1e-9);
        $this->assertSame(['light_fighter' => 3.0], $ferme->unitStats);
    }

    /**
     * **Les habitants non proteges perissent quand l attaque reussit, et seulement alors** (decision de Keven).
     */
    public function testTheUnprotectedPopulationDiesOnlyWhenTheAttackerWins(): void
    {
        $this->choose(Species::Humans);
        // Les pertes se prennent sur la population de l instant : la planete est datee de cet instant, ancre
        // comprise, et rien ne s ecoule entre deux applications.
        $instantDeLaPose = (int)Date::now()->timestamp;
        LifeformPlanet::query()->where('planet_id', $this->currentPlanetId)->update([
            'population' => 10000.0, 'food' => 0.0, 'calculated_at' => $instantDeLaPose,
            'previous_population' => 10000.0, 'previous_food' => 0.0, 'previous_calculated_at' => $instantDeLaPose,
        ]);
        LifeformBonusCache::invalidate();
        $pertes = resolve(LifeformCombatLosses::class);
        $chasseur = ObjectService::getShipObjectByMachineName('light_fighter');
        $instant = (int)Date::now()->timestamp;

        $victoire = new BattleResult();
        $victoire->attackerUnitsResult = new UnitCollection();
        $victoire->attackerUnitsResult->addUnit($chasseur, 10);
        $victoire->defenderUnitsResult = new UnitCollection();

        $this->assertSame(0, $pertes->applyIfAttackerWon($victoire, $this->planetService, null, $instant), 'Sans part protegee connue (null), rien ne s applique.');
        $this->assertSame(7000, $pertes->applyIfAttackerWon($victoire, $this->planetService, 0.3, $instant), '30 % proteges : 7 000 des 10 000 perissent.');
        $this->assertEqualsWithDelta(3000.0, (float)LifeformPlanet::query()->where('planet_id', $this->currentPlanetId)->value('population'), 0.001);
        $message = Message::query()->where('user_id', $this->currentUserId)->where('key', 'lifeform_population_loss')->orderByDesc('id')->first();
        $this->assertNotNull($message);
        $this->assertSame(7000, (int)$message->params['lost']);
        $this->assertSame(30, (int)$message->params['protected_percent']);

        // Chaque victoire tue la part non protegee de la population du moment : 70 % des 3 000 restants.
        $this->assertSame(2100, $pertes->applyIfAttackerWon($victoire, $this->planetService, 0.3, $instant));
        $this->assertEqualsWithDelta(900.0, (float)LifeformPlanet::query()->where('planet_id', $this->currentPlanetId)->value('population'), 0.001);

        // L abri : cent habitants survivent toujours, meme sans Bouclier.
        $this->assertSame(800, $pertes->applyIfAttackerWon($victoire, $this->planetService, 0.0, $instant));
        $this->assertSame(0, $pertes->applyIfAttackerWon($victoire, $this->planetService, 0.0, $instant), 'A l abri, plus personne ne meurt.');
        $this->assertEqualsWithDelta(100.0, (float)LifeformPlanet::query()->where('planet_id', $this->currentPlanetId)->value('population'), 0.001);

        // Une defense qui tient, ou un attaquant aneanti : personne ne meurt.
        LifeformPlanet::query()->where('planet_id', $this->currentPlanetId)->update(['population' => 10000.0]);
        $tenue = new BattleResult();
        $tenue->attackerUnitsResult = new UnitCollection();
        $tenue->attackerUnitsResult->addUnit($chasseur, 10);
        $tenue->defenderUnitsResult = new UnitCollection();
        $tenue->defenderUnitsResult->addUnit(ObjectService::getUnitObjectByMachineName('rocket_launcher'), 1);
        $this->assertSame(0, $pertes->applyIfAttackerWon($tenue, $this->planetService, 0.3, $instant));
        $aneanti = new BattleResult();
        $aneanti->attackerUnitsResult = new UnitCollection();
        $aneanti->defenderUnitsResult = new UnitCollection();
        $this->assertSame(0, $pertes->applyIfAttackerWon($aneanti, $this->planetService, 0.3, $instant), 'Les deux camps aneantis : pas une victoire.');
        $this->assertEqualsWithDelta(10000.0, (float)LifeformPlanet::query()->where('planet_id', $this->currentPlanetId)->value('population'), 0.001);
    }

    /**
     * **Ce que le corps acquiert apres l instant ne protege ni ne rend rien retroactivement** (revue de Codex).
     *
     * `ofBody()` recoit un instant — l ouverture d un ralliement, l arrivee d une attaque — et lisait
     * pourtant les niveaux et les bonus **courants** : un Bouclier planetaire acheve entre l ouverture et le
     * traitement du travailleur sauvait une population qu il ne couvrait pas encore.
     */
    public function testWhatTheBodyGainsAfterTheInstantDoesNotCountForThatInstant(): void
    {
        $photographe = resolve(LifeformCombatPhotographer::class);
        $this->choose(Species::Humans);
        $instant = (int)Date::now()->timestamp;

        // Le dixieme niveau du Bouclier planetaire s acheve cinq secondes apres l instant, et le monde l a
        // deja applique quand on photographie : le corps porte 10, il n en portait que 9.
        $this->aFinishedWork(self::PLANETARY_SHIELD, 10, $instant + 5);
        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Building, self::PLANETARY_SHIELD, 10);
        LifeformBonusCache::invalidate();

        $this->assertEqualsWithDelta(0.3, $photographe->ofBody($this->planetService)->protectedShare, 1e-9, 'Premisse : le corps porte desormais 30 %.');
        $this->assertEqualsWithDelta(0.27, $photographe->ofBody($this->planetService, $instant)->protectedShare, 1e-9, 'Le niveau acheve apres l instant protege la population retroactivement.');
        $this->assertEqualsWithDelta(0.3, $photographe->ofBody($this->planetService, $instant + 5)->protectedShare, 1e-9, 'A son echeance, il protege : une echeance egale compte comme precedente.');

        // Et un Bouclier dont le **premier** niveau s acheve apres l instant ne protegeait rien du tout.
        LifeformQueue::query()->where('planet_id', $this->currentPlanetId)->delete();
        $this->aFinishedWork(self::PLANETARY_SHIELD, 1, $instant + 5);
        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Building, self::PLANETARY_SHIELD, 1);
        LifeformBonusCache::invalidate();
        $this->assertSame(0.0, $photographe->ofBody($this->planetService, $instant)->protectedShare, 'Un Bouclier qui n existait pas encore protege la population.');
    }

    /**
     * **La lune, les debris et les epaves suivent le meme instant** (revue de Codex).
     */
    public function testTheBodyBonusesFollowTheInstantToo(): void
    {
        $photographe = resolve(LifeformCombatPhotographer::class);
        $this->choose(Species::Rocktal);
        $instant = (int)Date::now()->timestamp;

        // Le premier niveau de l usine s acheve apres l instant : a l instant, la planete n en avait aucun.
        $this->aFinishedWork(self::ADVANCED_RECYCLING_PLANT, 1, $instant + 5);
        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Building, self::ADVANCED_RECYCLING_PLANT, 1);
        LifeformBonusCache::invalidate();

        $this->assertEqualsWithDelta(0.006, $photographe->ofBody($this->planetService)->debrisRecovery, 1e-9, 'Premisse : le corps rend desormais +0,6 % de debris.');
        $this->assertSame(0.0, $photographe->ofBody($this->planetService, $instant)->debrisRecovery, 'L usine achevee apres l instant rend plus de debris retroactivement.');
        $this->assertEqualsWithDelta(0.006, $photographe->ofBody($this->planetService, $instant + 5)->debrisRecovery, 1e-9, 'A son echeance, elle rend.');
    }

    /**
     * Un travail de batiment deja livre par le monde, dont l echeance est celle-ci.
     */
    private function aFinishedWork(int $objectId, int $targetLevel, int $timeEnd): void
    {
        LifeformQueue::query()->create([
            'planet_id' => $this->currentPlanetId,
            'user_id' => $this->currentUserId,
            'kind' => LifeformKind::Building->value,
            'object_id' => $objectId,
            'target_level' => $targetLevel,
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
            'energy' => 0,
            'time_start' => $timeEnd - 100,
            'time_end' => $timeEnd,
            'status' => 'done',
            'catalogue_version' => LifeformCatalogue::VERSION,
        ]);
    }

    private function choose(Species $species): void
    {
        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, $species, (int)Date::now()->timestamp);
        LifeformBonusCache::invalidate();
    }

    /**
     * Le moteur PHP sur ma propre planete, avec ses deux calculs d apres-bataille exposes.
     */
    private function engine(): EngineExposingItsAfterBattleMaths
    {
        $flotte = new AttackerFleet();
        $flotte->units = new UnitCollection();
        $flotte->units->addUnit(ObjectService::getShipObjectByMachineName('light_fighter'), 10);
        $joueur = $this->planetService->getPlayer();
        $this->assertNotNull($joueur);
        $flotte->player = $joueur;
        $flotte->fleetMissionId = 1000;
        $flotte->ownerId = $joueur->getId();
        $flotte->cargoResources = new Resources(0, 0, 0, 0);
        $flotte->isInitiator = true;
        $flotte->fleetMission = null;

        return new EngineExposingItsAfterBattleMaths([$flotte], $this->planetService, [DefenderFleet::fromPlanet($this->planetService)], resolve(SettingsService::class), LiveLootContextFactory::forBattle([$flotte], $this->planetService, FrozenLootAllocation::atOperationStart()));
    }
}

/**
 * Le moteur PHP avec ses deux calculs d apres-bataille exposes au banc.
 */
final class EngineExposingItsAfterBattleMaths extends PhpBattleEngine
{
    public function debrisOf(UnitCollection $attackerLost, UnitCollection $defenderLost): Resources
    {
        return $this->calculateDebris($attackerLost, $defenderLost);
    }

    public function moonChanceOf(Resources $debris): int
    {
        return $this->calculateMoonChance($debris);
    }
}
