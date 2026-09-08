<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use OGame\Combat\Enums\CombatMissionKind;
use OGame\Combat\Enums\TargetScope;
use OGame\Factories\GameMissionFactory;
use OGame\GameMissions\PatrolMission;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Planet\Coordinate;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;

/**
 * Le socle du chantier « patrouilles » (journal §114) : le schema, l interrupteur, les reglages des
 * valeurs encore ouvertes, et le genre de mission 11 raccorde partout ou le jeu enumere ses missions.
 *
 * Rien ici n active un comportement : l interrupteur est eteint par defaut, et ce test le remet a
 * zero derriere lui.
 */
class PatrolFoundationTest extends AccountTestCase
{
    protected function tearDown(): void
    {
        resolve(SettingsService::class)->set('patrols_enabled', 0);

        parent::tearDown();
    }

    public function testTheSchemaOfTheProjectIsInPlace(): void
    {
        $this->assertTrue(Schema::hasColumns('patrols', [
            'user_id', 'home_planet_id', 'state', 'galaxy', 'system', 'x', 'y', 'current_mission_id',
            'fuel_reserve', 'upkeep_paid_at', 'stationed_since', 'entered_system_at', 'order_version',
            'finished_at', 'finish_reason',
        ]));
        $this->assertTrue(Schema::hasColumns('fleet_missions', ['patrol_id', 'target_patrol_id', 'x_from', 'y_from', 'x_to', 'y_to']));
        $this->assertTrue(Schema::hasColumns('space_debris_fields', ['galaxy', 'system', 'x', 'y', 'metal', 'crystal', 'deuterium']));
        $this->assertTrue(Schema::hasColumns('surveillance_contacts', ['observer_planet_id', 'observer_user_id', 'patrol_id', 'entered_system_at', 'visible_from', 'revoked_at']));
        $this->assertTrue(Schema::hasColumns('patrol_combat_barriers', ['patrol_id', 'combat_instance_id', 'opened_at', 'owned_through_effect_at', 'revision']));
        $this->assertTrue(Schema::hasColumn('planets', 'surveillance_network'));
    }

    public function testTheSwitchIsOffByDefaultAndTheOpenValuesAreSettings(): void
    {
        $settings = resolve(SettingsService::class);

        $this->assertFalse($settings->patrolsEnabled());
        $this->assertSame(60, $settings->patrolManoeuvreDelaySeconds());
        $this->assertSame(20, $settings->patrolUpkeepDivisor());
        $this->assertSame(3.0, $settings->patrolSafetyReturnSpeed());
        $this->assertSame([15, 10, 5, 2, 0], array_map(fn (int $niveau): int => $settings->patrolAcquisitionDelayMinutes($niveau), [1, 2, 3, 4, 5]));
        $this->assertSame(300, $settings->patrolAttackMinDurationSeconds());
        $this->assertSame(10, $settings->patrolGridUnits());
        $this->assertSame(1800, $settings->patrolSystemRadiusUnits());
        $this->assertSame(60, $settings->patrolStarExclusionUnits());
        $this->assertSame(3, $settings->patrolInternalDistanceDivisor());

        // Une valeur ouverte se change sans toucher au code, et les bornes tiennent.
        $settings->set('patrol_upkeep_divisor', 25);
        $settings->set('patrol_safety_return_speed', 42);
        $settings->set('patrol_acquisition_delay_n3', 7);

        try {
            $this->assertSame(25, $settings->patrolUpkeepDivisor());
            $this->assertSame(10.0, $settings->patrolSafetyReturnSpeed());
            $this->assertSame(7, $settings->patrolAcquisitionDelayMinutes(3));
        } finally {
            $settings->set('patrol_upkeep_divisor', 20);
            $settings->set('patrol_safety_return_speed', 3);
            $settings->set('patrol_acquisition_delay_n3', 5);
        }
    }

    public function testTypeElevenIsAPatrolEverywhereTheGameEnumeratesItsMissions(): void
    {
        $this->assertInstanceOf(PatrolMission::class, GameMissionFactory::getAllMissions()[11]);
        $this->assertInstanceOf(PatrolMission::class, GameMissionFactory::getMissionById(11, []));
        $this->assertSame(11, PatrolMission::getTypeId());
        $this->assertSame(CombatMissionKind::Patrol, CombatMissionKind::fromMissionType(11));
        $this->assertSame(TargetScope::SpatialPoint, CombatMissionKind::Patrol->targetScope());
        $this->assertFalse(CombatMissionKind::Patrol->opensCombat());
        $this->assertFalse(CombatMissionKind::Patrol->reinforcesTheDefence());
        $this->assertSame(5, PlanetType::SpatialPoint->value);

        // Le libelle est traduit dans les deux langues du serveur, et c est lui que la carte recoit.
        $fr = require base_path('resources/lang/fr/t_ingame.php');
        $en = require base_path('resources/lang/en/t_ingame.php');
        $this->assertSame('Patrouille', $fr['fleet']['mission_patrol']);
        $this->assertSame('Patrol', $en['fleet']['mission_patrol']);
        $this->assertArrayHasKey('refusal_immobile_unit', $fr['patrol']);
        $this->assertArrayHasKey('refusal_immobile_unit', $en['patrol']);
    }

    public function testAPatrolIsRefusedWhileTheSwitchIsOffAndOfferedOnceItIsOn(): void
    {
        $settings = resolve(SettingsService::class);
        $mission = GameMissionFactory::getMissionById(11, []);
        $planet = $this->planetService;
        $coordinates = $planet->getPlanetCoordinates();
        // Une autre orbite que la sienne : aux memes coordonnees et du meme type, le parent refuse.
        $target = new Coordinate($coordinates->galaxy, $coordinates->system, $coordinates->position === 9 ? 10 : 9);

        $fighters = new UnitCollection();
        $fighters->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 5);

        $this->assertFalse($mission->isMissionPossible($planet, $target, PlanetType::SpatialPoint, $fighters)->possible, 'A patrol was offered while the switch is off.');

        $settings->set('patrols_enabled', 1);

        $this->assertTrue($mission->isMissionPossible($planet, $target, PlanetType::SpatialPoint, $fighters)->possible, 'A patrol to a spatial point was refused while the switch is on.');
        $this->assertTrue($mission->isMissionPossible($planet, $target, PlanetType::Planet, $fighters)->possible, 'A patrol next to a planet was refused.');
        $this->assertFalse($mission->isMissionPossible($planet, $target, PlanetType::DebrisField, $fighters)->possible, 'A debris field is not a stationing point.');
        $this->assertFalse($mission->isMissionPossible($planet, $target, PlanetType::DeepSpace, $fighters)->possible, 'The expedition deep space is not a stationing point.');
        $this->assertFalse($mission->isMissionPossible($planet, $target, PlanetType::SpatialPoint, new UnitCollection())->possible, 'An empty fleet cannot patrol.');

        $immobile = new UnitCollection();
        $immobile->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 5);
        $immobile->addUnit(ObjectService::getUnitObjectByMachineName('solar_satellite'), 1);
        $refus = $mission->isMissionPossible($planet, $target, PlanetType::SpatialPoint, $immobile);
        $this->assertFalse($refus->possible, 'A solar satellite cannot fly.');
        $this->assertSame(__('t_ingame.patrol.refusal_immobile_unit'), $refus->error);
    }
}
