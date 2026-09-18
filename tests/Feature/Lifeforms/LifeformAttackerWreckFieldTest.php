<?php

namespace Tests\Feature\Lifeforms;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Enums\CharacterClass;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Lifeforms\Bonuses\LifeformBonusCache;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\Services\LifeformInstallationService;
use OGame\Lifeforms\Services\LifeformLevels;
use OGame\Lifeforms\Species;
use OGame\Models\FleetMission;
use OGame\Models\Lifeforms\LifeformAccount;
use OGame\Models\Lifeforms\LifeformBuildingLevel;
use OGame\Models\Lifeforms\LifeformPlanet;
use OGame\Models\Lifeforms\LifeformSpeciesProgress;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Models\WreckField;
use OGame\Services\FleetMissionService;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use OGame\Services\WreckFieldService;
use Tests\FleetDispatchTestCase;
use Tests\RecordsClassHistory;

/**
 * **Le chemin instantane : le champ d epaves qu un attaquant General ramene prend les Nano-robots de reparation de
 * sa planete d origine** (audit des effets, journal §157). Le champ du defenseur les prenait depuis la tranche 5 ;
 * celui de l attaquant, calcule au retour (`CombatResolutionService::calculateAttackerWreckField`), ne recevait ni
 * la planete ni la part — un Mechas ramenait moins d epaves que sa fiche ne le promettait.
 */
final class LifeformAttackerWreckFieldTest extends FleetDispatchTestCase
{
    use RecordsClassHistory;

    protected int $missionType = 1;

    protected string $missionName = 'Attack';

    private const int NANO_REPAIR_BOTS = 13112;

    protected function basicSetup(): void
    {
        WreckField::query()->delete();
        $reglages = resolve(SettingsService::class);
        $reglages->set('lifeforms_enabled', 1);
        $reglages->set('debris_field_from_ships', 30);
        $reglages->set('wreck_field_min_resources_loss', 0);
        $reglages->set('wreck_field_min_fleet_percentage', 0);
    }

    protected function tearDown(): void
    {
        $planetes = Planet::query()->where('user_id', $this->currentUserId)->pluck('id');
        LifeformBuildingLevel::query()->whereIn('planet_id', $planetes)->delete();
        LifeformPlanet::query()->whereIn('planet_id', $planetes)->delete();
        LifeformAccount::query()->where('user_id', $this->currentUserId)->delete();
        LifeformSpeciesProgress::query()->where('user_id', $this->currentUserId)->delete();
        LifeformBonusCache::invalidate();
        $reglages = resolve(SettingsService::class);
        $reglages->set('lifeforms_enabled', 0);
        $reglages->set('wreck_field_min_resources_loss', 150000);
        $reglages->set('wreck_field_min_fleet_percentage', 5);
        parent::tearDown();
    }

    protected function messageCheckMissionArrival(): void
    {
    }

    protected function messageCheckMissionReturn(): void
    {
    }

    public function testTheReturningWreckFieldOfAGeneralTakesTheNanoRepairBotsOfTheOrigin(): void
    {
        $this->basicSetup();
        $attaquant = $this->planetService;
        $joueur = $attaquant->getPlayer();
        $this->assertNotNull($joueur);
        $this->recordCharacterClassOn($joueur->getUser(), CharacterClass::GENERAL);
        DB::table('planets')->where('id', $attaquant->getPlanetId())->update(['space_dock' => 1, 'shipyard' => 2]);

        // Mechas, Nano-robots de reparation niveau 10 : +13 % d epaves reparables, sur la planete d origine.
        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Mechas, (int)Date::now()->timestamp);
        resolve(LifeformLevels::class)->setLevel($attaquant->getPlanetId(), LifeformKind::Building, self::NANO_REPAIR_BOTS, 10);
        LifeformBonusCache::invalidate();

        $attaquant->removeUnits($attaquant->getShipUnits(), true);
        $attaquant->save();
        $attaquant->reloadPlanet();
        $attaquant->addUnit('cruiser', 100);
        $attaquant->save();
        $this->planetAddResources(new Resources(0, 0, 200000, 0));

        $unites = new UnitCollection();
        $unites->addUnit(ObjectService::getShipObjectByMachineName('cruiser'), 100);
        $cible = $this->sendMissionToOtherPlayerCleanPlanet($unites, new Resources(0, 0, 0, 0));
        DB::table('planets')->where('id', $cible->getPlanetId())->update(['rocket_launcher' => 500, 'plasma_turret' => 12]);

        $service = resolve(FleetMissionService::class, ['player' => $joueur]);
        $aller = $service->getActiveFleetMissionsForCurrentPlayer()->first();
        $this->assertNotNull($aller);
        $this->travel((int)$aller->time_arrival - (int)$aller->time_departure + 1)->seconds();
        $this->get('/overview')->assertStatus(200);

        $retour = FleetMission::query()->where('user_id', $this->currentUserId)->where('processed', 0)->orderByDesc('id')->first();
        $this->assertNotNull($retour, 'La flotte survit et rentre.');
        $perdus = 100 - (int)$retour->cruiser;
        $this->assertGreaterThan(20, $perdus, 'Premisse : assez de pertes pour que 13 % se voient sur l entier.');
        $this->assertLessThan(100, $perdus);

        $epaves = new WreckFieldService($joueur, resolve(SettingsService::class));
        $sansBonus = (int)floor($perdus * $epaves->getRecoverableWreckFieldPercentage(1) / 100);
        $avecBonus = (int)floor($perdus * $epaves->getRecoverableWreckFieldPercentage(1, $attaquant->getPlanetId()) / 100);
        $this->assertNotSame($sansBonus, $avecBonus, 'Les deux parts donnent le meme entier : l essai ne prouverait rien.');
        $this->assertSame(round(min(100.0, $epaves->getRecoverableWreckFieldPercentage(1) * 1.13), 1), $epaves->getRecoverableWreckFieldPercentage(1, $attaquant->getPlanetId()), 'Premisse : +13 %.');

        $donnees = $retour->wreck_field_data;
        $this->assertIsArray($donnees, 'Le retour porte le champ d epaves du General.');
        $this->assertSame([['machine_name' => 'cruiser', 'quantity' => $avecBonus, 'repair_progress' => 0]], $donnees, 'Le champ d epaves de l attaquant prend les Nano-robots de sa planete d origine.');
    }
}
