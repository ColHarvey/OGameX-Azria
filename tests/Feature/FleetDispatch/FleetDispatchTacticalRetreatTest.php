<?php

namespace Tests\Feature\FleetDispatch;

use OGame\GameMissions\AttackMission;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\BattleReport;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\FleetMissionService;
use OGame\Services\ObjectService;
use Tests\FleetDispatchTestCase;

/**
 * Feature coverage for tactical retreat during attack missions.
 */
class FleetDispatchTacticalRetreatTest extends FleetDispatchTestCase
{
    protected int $missionType = 1;

    protected string $missionName = 'Attack';

    /**
     * Le compte dont cet essai change le rapport de retraite, et la valeur qu il y a trouvee.
     *
     * ## Rendre ce qu on a trouve, et non poser une valeur
     *
     * Le proprietaire de la planete etrangere « propre » est partage par les essais d un meme
     * processus. Ce que cette classe y ecrit, la suivante le lit. Poser zero au demontage serait
     * une seconde decision, aussi arbitraire que la premiere ; seule la valeur trouvee en
     * arrivant laisse le banc tel qu il etait.
     *
     * **Mesure faite, et elle contredit l intuition** : la colonne a pour defaut 5, la valeur
     * meme que cet essai ecrit. Il ne fuit donc rien aujourd hui, et ce retablissement ne
     * corrige aucun defaut observable. Il ferme un piege futur : l innocuite tient a une
     * coincidence entre deux nombres, et changer le defaut de la colonne ferait naitre la fuite
     * en silence, sans qu aucun essai ne bouge.
     *
     * Le retablissement vit dans `tearDown()`, qui s execute meme quand l essai echoue — et
     * c est precisement quand un essai echoue que le suivant a besoin d un banc intact.
     */
    private int|null $compteDuRatio = null;

    private int|null $ratioDOrigine = null;

    protected function tearDown(): void
    {
        if ($this->compteDuRatio !== null) {
            User::query()->whereKey($this->compteDuRatio)->update(['tactical_retreat_ratio' => $this->ratioDOrigine]);
            $this->compteDuRatio = null;
        }

        parent::tearDown();
    }

    protected function basicSetup(): void
    {
        $this->planetAddUnit('light_fighter', 200);
        $this->planetAddUnit('small_cargo', 5);
        $this->planetAddResources(new Resources(5000, 5000, 1000000, 0));
    }

    public function testTacticalRetreatPersistsRatioAndFleeInBattleReport(): void
    {
        $this->basicSetup();

        // Use a freshly created hostile planet so leftover ships/resources from
        // earlier suite tests cannot change the 5:1 ratio or remaining fleet.
        $foreignPlanet = $this->getNearbyForeignCleanPlanet();
        $foreignPlanet->addUnit('light_fighter', 5);
        $foreignPlanet->addUnit('rocket_launcher', 20);
        $foreignPlanet->addResources(new Resources(0, 0, 100000, 0));

        $defenderPlayer = $foreignPlanet->getPlayer();
        $this->assertNotNull($defenderPlayer);
        $user = $defenderPlayer->getUser();
        $this->compteDuRatio = (int)$user->id;
        $this->ratioDOrigine = $user->tactical_retreat_ratio;
        $user->tactical_retreat_ratio = 5;
        $user->time = (string) now()->timestamp;
        $user->save();

        $unitCollection = new UnitCollection();
        $unitCollection->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 100);

        $this->dispatchFleet(
            $foreignPlanet->getPlanetCoordinates(),
            $unitCollection,
            new Resources(0, 0, 0, 0),
            PlanetType::Planet
        );

        $fleetMissionService = resolve(FleetMissionService::class, ['player' => $this->planetService->getPlayer()]);
        $duration = $fleetMissionService->calculateFleetMissionDuration(
            $this->planetService,
            $foreignPlanet->getPlanetCoordinates(),
            $unitCollection,
            resolve(AttackMission::class)
        );

        $this->travel($duration + 1)->seconds();
        $this->reloadApplication();
        $this->get('/overview')->assertStatus(200);

        $coords = $foreignPlanet->getPlanetCoordinates();
        $report = BattleReport::query()
            ->where('planet_galaxy', $coords->galaxy)
            ->where('planet_system', $coords->system)
            ->where('planet_position', $coords->position)
            ->where('planet_user_id', $defenderPlayer->getId())
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($report, 'Expected a battle report after the attack');

        $general = $report->general;
        $this->assertIsArray($general);
        $this->assertArrayHasKey('tactical_retreat', $general);
        $this->assertIsArray($general['tactical_retreat']);
        $this->assertTrue($general['tactical_retreat']['defender_fled']);
        $this->assertGreaterThanOrEqual(5, $general['tactical_retreat']['ratio']);
        $this->assertEquals('defender', $general['tactical_retreat']['by']);

        // Fleeing ships remain on the defender planet.
        $foreignPlanet->reloadPlanet();
        $this->assertEquals(5, $foreignPlanet->getObjectAmount('light_fighter'));
    }

    public function testTacticalRetreatPreferenceCanBeUpdated(): void
    {
        $response = $this->post('/ajax/fleet/tactical-retreat', [
            'tacticalRetreatState' => 0,
            '_token' => csrf_token(),
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true, 'tacticalRetreatRatio' => 0]);

        $this->planetPlayer()->getUser()->refresh();
        $this->assertEquals(0, $this->planetPlayer()->getUser()->tactical_retreat_ratio);
    }

    public function testRetreatAfterDefenderRetreatIsStoredOnMission(): void
    {
        $this->basicSetup();

        $foreignPlanet = $this->getNearbyForeignPlanet();
        $unitCollection = new UnitCollection();
        $unitCollection->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 1);
        $unitsArray = [];
        foreach ($unitCollection->units as $unit) {
            $unitsArray['am' . $unit->unitObject->id] = $unit->amount;
        }

        $coordinates = $foreignPlanet->getPlanetCoordinates();
        $post = $this->post('/ajax/fleet/dispatch/send-fleet', [
            'galaxy' => $coordinates->galaxy,
            'system' => $coordinates->system,
            'position' => $coordinates->position,
            'type' => PlanetType::Planet->value,
            'mission' => $this->missionType,
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
            '_token' => csrf_token(),
            'holdingtime' => 0,
            'speed' => 10,
            'retreatAfterDefenderRetreat' => 1,
            ...$unitsArray,
        ]);

        $post->assertStatus(200);
        $post->assertJson(['success' => true]);

        $mission = \OGame\Models\FleetMission::query()->orderByDesc('id')->first();
        $this->assertNotNull($mission);
        $this->assertTrue((bool)$mission->retreat_after_defender_retreat);
    }

    private function planetPlayer(): \OGame\Services\PlayerService
    {
        $player = $this->planetService->getPlayer();
        if ($player === null) {
            $this->fail('Planet has no player.');
        }

        return $player;
    }
}
