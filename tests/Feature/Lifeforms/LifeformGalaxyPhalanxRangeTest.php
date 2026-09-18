<?php

namespace Tests\Feature\Lifeforms;

use Illuminate\Support\Facades\Date;
use OGame\GameConstants\UniverseConstants;
use OGame\Lifeforms\Bonuses\LifeformBonusCache;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\Services\LifeformInstallationService;
use OGame\Lifeforms\Services\LifeformLevels;
use OGame\Lifeforms\Species;
use OGame\Models\Lifeforms\LifeformAccount;
use OGame\Models\Lifeforms\LifeformBuildingLevel;
use OGame\Models\Lifeforms\LifeformPlanet;
use OGame\Models\Lifeforms\LifeformSlot;
use OGame\Models\Lifeforms\LifeformSlotChange;
use OGame\Models\Lifeforms\LifeformSpeciesProgress;
use OGame\Models\Lifeforms\LifeformTechnologyLevel;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\PhalanxService;
use Tests\MoonTestCase;
use Tests\Support\PinsSettings;
use Tests\Support\PlacesLifeformSlots;

/**
 * **Le bouton de phalange de la Galaxie a la portee que le balayage accepte** (audit des effets, journal §157).
 *
 * `GalaxyController::getPlanetActions()` demandait `canScanTarget()` sans identifiant de joueur : la portee de base
 * seule, sans le Decouvreur ni le Reseau d analyse interplanetaire. Le balayage (`PhalanxController`) les comptait :
 * une planete a la limite bonifiee etait balayable, mais la Galaxie n offrait pas le bouton.
 */
final class LifeformGalaxyPhalanxRangeTest extends MoonTestCase
{
    use PinsSettings;
    use PlacesLifeformSlots;

    private const int INTERPLANETARY_ANALYSIS_NETWORK = 14208;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pinSettings(['lifeforms_enabled' => 1]);
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

    public function testTheGalaxyOffersThePhalanxOnAPlanetOnlyTheBonifiedRangeReaches(): void
    {
        // Phalange niveau 5 : 24 systemes ; Reseau d analyse interplanetaire niveau 10 : × 1,06 = 25.
        $this->moonService->setObjectLevel(ObjectService::getObjectByMachineName('sensor_phalanx')->id, 5);
        $this->moonService->addResources(new Resources(0, 0, 100000, 0));
        $maintenant = (int)Date::now()->timestamp;
        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Kaelesh, $maintenant);
        LifeformPlanet::query()->where('planet_id', $this->currentPlanetId)->update(['population' => 5000000.0]);
        $this->placeLifeformTechnology($this->currentPlanetId, Species::Kaelesh, self::INTERPLANETARY_ANALYSIS_NETWORK, $maintenant);
        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Technology, self::INTERPLANETARY_ANALYSIS_NETWORK, 10);
        LifeformBonusCache::invalidate();
        $phalange = resolve(PhalanxService::class);
        $this->assertSame(24, $phalange->calculatePhalanxRange(5), 'Premisse : la portee de base.');
        $this->assertSame(25, $phalange->calculatePhalanxRange(5, $this->currentUserId), 'Premisse : la portee bonifiee.');

        // Une planete etrangere a exactement 25 systemes de la lune : balayable, et seulement grace au bonus.
        $lune = $this->moonService->getPlanetCoordinates();
        $systeme = (($lune->system - 1 + 25) % UniverseConstants::MAX_SYSTEM_COUNT) + 1;
        $etranger = $this->getNearbyForeignPlanet()->getPlayer();
        $this->assertNotNull($etranger);
        $position = null;
        for ($p = 13; $p <= 15; $p++) {
            if (!Planet::query()->where('galaxy', $lune->galaxy)->where('system', $systeme)->where('planet', $p)->exists()) {
                $position = $p;
                break;
            }
        }
        $this->assertNotNull($position, 'Une position libre a la limite.');
        Planet::factory()->create(['user_id' => $etranger->getId(), 'galaxy' => $lune->galaxy, 'system' => $systeme, 'planet' => $position]);

        $this->switchToMoon();
        $lignes = $this->post('/ajax/galaxy', ['galaxy' => $lune->galaxy, 'system' => $systeme])->assertStatus(200)->json('system.galaxyContent');
        $this->assertIsArray($lignes);
        $actions = null;
        foreach ($lignes as $ligne) {
            if ((int)($ligne['position'] ?? 0) === $position) {
                $actions = $ligne['actions'];
            }
        }
        $this->assertIsArray($actions, 'La ligne de la planete a la limite.');
        $this->assertTrue($actions['phalanxActive'], 'Le bouton de phalange est offert : la Galaxie lit la portee bonifiee, comme le balayage.');
        $this->assertTrue($actions['canPhalanx']);

        // Le balayage lui-meme l accepte (le vrai chemin, meme portee).
        $reponse = $this->post('/ajax/phalanx/scan', ['galaxy' => $lune->galaxy, 'system' => $systeme, 'position' => $position]);
        $reponse->assertStatus(200);
        $this->assertStringContainsString(__('t_ingame.galaxy.phalanx_no_movement'), (string)$reponse->json('content_html'), 'A 25 systemes, le balayage passe et rend son rapport.');
    }
}
