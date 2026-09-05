<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\CombatState;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\CombatInstance;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\FleetUnion;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\BuddyService;
use OGame\Services\FleetMissionService;
use OGame\Services\FleetUnionService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;

/**
 * Une attaque groupee **durable** : l'initiateur et un allie arrivent ensemble, le combat s'ouvre et
 * se ferme dans la meme transaction (aucune vague en attente), la bataille est calculee et attend son
 * echeance. Les essais du reglement en ACS partent de la.
 *
 * Le montage de l'allie et de la cible reprend celui de `FleetDispatchAcsAttackTest`, qui ne le
 * partage pas.
 */
trait OpensAPersistentAcsBattle
{
    private PlanetService|null $acsTargetPlanet = null;

    private PlanetService|null $acsAllyPlanet = null;

    private User|null $acsAllyUser = null;

    protected function basicSetupForAnAcsBattle(): void
    {
        $this->planetAddUnit('light_fighter', 400);
        $this->planetAddUnit('reaper', 20);
        // Des recycleurs : un seul dans la flotte de l'initiateur la ralentit assez pour qu'un allie
        // aux Faucheurs rejoigne l'union dans les 30 % de vol restant que la regle accorde.
        $this->planetAddUnit('recycler', 5);
        $this->playerSetResearchLevel('computer_technology', object_level: 2);
        $reglages = resolve(SettingsService::class);
        $reglages->set('economy_speed', 8);
        $reglages->set('fleet_speed_war', 1);
        $reglages->set('fleet_speed_holding', 1);
        $reglages->set('fleet_speed_peaceful', 1);
        $reglages->set('attack_block_until', 0);
        $reglages->set('debris_field_from_ships', 30);
        $this->planetAddResources(new Resources(0, 0, 1_000_000, 0));
    }

    /**
     * Ouvre et ferme un combat groupe contre la cible, puis s'arrete : la bataille est calculee, le
     * reglement n'a pas eu lieu.
     *
     * @param array<string, int> $initiatorFleet Unites de l'initiateur (nom de machine => nombre).
     * @param array<string, int> $allyFleet Unites de l'allie.
     * @param array<string, int> $targetDefences Defenses posees sur la cible avant l'ouverture.
     * @return array{0: CombatInstance, 1: int, 2: FleetMission, 3: FleetMission}
     */
    protected function anAcsBattleReadyToSettle(array $initiatorFleet, array $allyFleet, array $targetDefences): array
    {
        $this->basicSetup();
        $this->createAcsTargetPlayer();
        $this->createAcsAllyPlayer();

        foreach ($targetDefences as $nom => $nombre) {
            $this->acsTargetPlanet()->addUnit($nom, $nombre);
        }
        $proprietaire = (int)$this->acsTargetPlanet()->getPlayer()?->getId();
        DB::table('users')->where('id', $proprietaire)->update(['tactical_retreat_ratio' => 0]);

        $unites = new UnitCollection();
        foreach ($initiatorFleet as $nom => $nombre) {
            $unites->addUnit(ObjectService::getUnitObjectByMachineName($nom), $nombre);
        }
        $this->dispatchFleet($this->acsTargetPlanet()->getPlanetCoordinates(), $unites, new Resources(0, 0, 0, 0), PlanetType::Planet);
        $initiatrice = resolve(FleetMissionService::class)->getActiveFleetMissionsForCurrentPlayer()->first();
        $this->assertNotNull($initiatrice, 'The initiator fleet was not dispatched.');

        $this->post('/ajax/fleet/union/create', [
            'fleetID' => $initiatrice->id,
            'groupname' => 'BatailleDurable',
            'unionUsers' => $this->acsAllyUser()->username,
            '_token' => csrf_token(),
        ]);
        $initiatrice->refresh();
        $this->assertNotNull($initiatrice->union_id, 'The union was not created.');

        $flotteAlliee = new UnitCollection();
        foreach ($allyFleet as $nom => $nombre) {
            $this->acsAllyPlanet()->addUnit($nom, $nombre);
            $flotteAlliee->addUnit(ObjectService::getUnitObjectByMachineName($nom), $nombre);
        }
        $serviceAllie = resolve(FleetMissionService::class, ['player' => $this->acsAllyPlanet()->getPlayer()]);
        $alliee = $serviceAllie->createNewFromPlanet(
            $this->acsAllyPlanet(),
            $this->acsTargetPlanet()->getPlanetCoordinates(),
            PlanetType::Planet,
            1,
            $flotteAlliee,
            new Resources(0, 0, 0, 0),
            10,
            0
        );
        $union = FleetUnion::query()->find($initiatrice->union_id);
        $this->assertNotNull($union);
        resolve(FleetUnionService::class)->joinUnion($union, $alliee);
        $alliee->refresh();
        $initiatrice->refresh();
        $this->assertSame((int)$initiatrice->union_id, (int)$alliee->union_id, 'The ally did not join the union.');

        resolve(SettingsService::class)->set('persistent_combat_enabled', '1');
        $arrivee = max((int)$initiatrice->time_arrival, (int)$alliee->time_arrival);
        $this->travelTo(Date::createFromTimestamp($arrivee + 1));
        $this->get('/overview')->assertStatus(200);

        $combat = CombatInstance::query()->where('mission_id', $initiatrice->id)->first();
        $this->assertNotNull($combat, 'The union arrival did not open a durable combat.');
        $this->assertSame(CombatState::Active, $combat->status, 'The rally did not close at once: a wave is still pending.');

        $initiatrice->refresh();
        $alliee->refresh();

        return [$combat, $this->acsTargetPlanet()->getPlanetId(), $initiatrice, $alliee];
    }

    protected function acsTargetPlanet(): PlanetService
    {
        $this->assertNotNull($this->acsTargetPlanet, 'The target planet is not initialised.');

        return $this->acsTargetPlanet;
    }

    protected function acsAllyPlanet(): PlanetService
    {
        $this->assertNotNull($this->acsAllyPlanet, 'The ally planet is not initialised.');

        return $this->acsAllyPlanet;
    }

    protected function acsAllyUser(): User
    {
        $this->assertNotNull($this->acsAllyUser, 'The ally user is not initialised.');

        return $this->acsAllyUser;
    }

    protected function createAcsTargetPlayer(): User
    {
        $cible = User::factory()->create();
        $this->acsTargetPlanet = $this->createPlanetAtSafeCoordinate($cible->id, 13, 15, 3);
        $this->acsTargetPlanet()->addResources(new Resources(100_000, 100_000, 100_000, 0));

        return $cible;
    }

    protected function createAcsAllyPlayer(): User
    {
        $allie = User::factory()->create();
        $coordonnees = $this->acsTargetPlanet()->getPlanetCoordinates();
        $positions = array_values(array_filter([13, 14, 15, 1, 2, 3], static fn (int $p): bool => $p !== $coordonnees->position));
        $position = collect($positions)->first(
            static fn (int $p): bool => !Planet::query()->where('galaxy', $coordonnees->galaxy)->where('system', $coordonnees->system)->where('planet', $p)->exists()
        );
        $this->assertNotNull($position, 'No free position for the ally in the target system.');
        $planete = Planet::factory()->create([
            'user_id' => $allie->id,
            'galaxy' => $coordonnees->galaxy,
            'system' => $coordonnees->system,
            'planet' => $position,
        ]);
        $joueur = resolve(PlayerService::class, ['player_id' => $allie->id]);
        $this->acsAllyPlanet = resolve(PlanetServiceFactory::class)->makeForPlayer($joueur, $planete->id);
        $this->acsAllyUser = $allie;
        $this->acsAllyPlanet()->addResources(new Resources(0, 0, 1_000_000, 0));

        $amis = resolve(BuddyService::class);
        $demande = $amis->sendRequest($this->currentUserId, $allie->id);
        $amis->acceptRequest($demande->id, $allie->id);

        return $allie;
    }
}
