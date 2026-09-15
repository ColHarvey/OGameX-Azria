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
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\Combat\LifeformCombatLosses;
use OGame\Lifeforms\Combat\LifeformCombatPhotographer;
use OGame\Lifeforms\Services\LifeformInstallationService;
use OGame\Lifeforms\Services\LifeformLevels;
use OGame\Lifeforms\Species;
use OGame\Models\Lifeforms\LifeformAccount;
use OGame\Models\Lifeforms\LifeformBuildingLevel;
use OGame\Models\Lifeforms\LifeformPlanet;
use OGame\Models\Lifeforms\LifeformSlot;
use OGame\Models\Lifeforms\LifeformSpeciesProgress;
use OGame\Models\Lifeforms\LifeformTechnologyLevel;
use OGame\Models\Message;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;
use Tests\Support\PinsSettings;

/**
 * Ce que le moteur partage lit des formes de vie du corps defendu : les debris (Usine de recyclage avancee),
 * la chance de lune (Supra-refracteur), photographies ou lus vivants (journal §155.6).
 */
final class LifeformCombatEngineTest extends AccountTestCase
{
    use PinsSettings;

    private const int ADVANCED_RECYCLING_PLANT = 12112;

    private const int SUPRA_REFRACTOR = 14112;

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
        LifeformTechnologyLevel::query()->whereIn('planet_id', $planetes)->delete();
        LifeformBuildingLevel::query()->whereIn('planet_id', $planetes)->delete();
        LifeformPlanet::query()->whereIn('planet_id', $planetes)->delete();
        LifeformAccount::query()->where('user_id', $this->currentUserId)->delete();
        LifeformSpeciesProgress::query()->where('user_id', $this->currentUserId)->delete();
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
        LifeformSlot::query()->updateOrCreate(['planet_id' => $this->currentPlanetId, 'slot' => 1], ['object_id' => 11209, 'chosen_via' => 'local', 'selected_at' => (int)Date::now()->timestamp]);
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
        LifeformPlanet::query()->where('planet_id', $this->currentPlanetId)->update(['population' => 10000.0, 'calculated_at' => (int)Date::now()->timestamp + 10 * 86400]);
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
