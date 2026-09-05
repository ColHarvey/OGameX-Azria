<?php

namespace Tests\Feature\Combat;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\CombatState;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\CombatInstance;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;

/**
 * Un ralliement **ouvert et tenu ouvert** — une premiere attaque l'ouvre, une seconde vague a +18 s
 * garde la fenetre —, avec un stock pose et une production gelee sur la cible.
 *
 * ## Pourquoi pas le montage d'`EngagesAPersistentCombat`
 *
 * Une flotte seule ferme sa fenetre dans la transaction d'ouverture : il n'y a rien entre l'ouverture
 * et la fermeture. Les essais qui observent ce qui se passe **pendant** la fenetre — un transport qui
 * arrive, une fermeture qui court contre lui — ont besoin d'une fenetre reelle.
 *
 * Le stock de metal est fixe et `time_last_update` est mis dans le futur : la seule variation
 * possible du metal de la cible est ce que les essais y livrent.
 */
trait OpensARallyWithAWindow
{
    protected const int RALLY_STOCK_METAL = 100_000;

    protected const int RALLY_WINDOW_SECONDS = 18;

    protected function basicSetupForARally(): void
    {
        $this->planetAddUnit('small_cargo', 60);
        $this->planetAddUnit('light_fighter', 700);
        $this->playerSetResearchLevel('computer_technology', object_level: 2);
        $reglages = resolve(SettingsService::class);
        $reglages->set('economy_speed', 8);
        $reglages->set('fleet_speed_war', 1);
        $reglages->set('fleet_speed_holding', 1);
        $reglages->set('fleet_speed_peaceful', 1);
        $reglages->set('attack_block_until', 0);
        $this->planetAddResources(new Resources(0, 0, 1_000_000, 0));
    }

    /**
     * Ouvre le ralliement et s'arrete a l'ouverture : le temps est celui de l'ouverture.
     *
     * @return array{0: CombatInstance, 1: int, 2: int} Le combat, la planete visee, l'instant d'ouverture.
     */
    protected function anOpenRally(): array
    {
        for ($i = 0; $i < 6; $i++) {
            $this->createAndLoginUser();
        }
        $this->basicSetup();

        $unites = new UnitCollection();
        $unites->addUnit(ObjectService::getUnitObjectByMachineName('small_cargo'), 50);
        $unites->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 350);
        $cible = $this->sendMissionToOtherPlayerCleanPlanet($unites, new Resources(0, 0, 0, 0));
        $ouvreuse = $this->lastMissionDispatched();
        $ouverture = (int)$ouvreuse->time_arrival;

        $coordonnees = $cible->getPlanetCoordinates();
        $vague = new UnitCollection();
        $vague->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 100);
        $this->dispatchFleet(new Coordinate($coordonnees->galaxy, $coordonnees->system, $coordonnees->position), $vague, new Resources(0, 0, 0, 0), PlanetType::Planet);
        $seconde = $this->lastMissionDispatched();
        $this->assertNotSame($ouvreuse->id, $seconde->id, 'The second wave was not dispatched.');
        DB::table('fleet_missions')->where('id', $seconde->id)->update(['time_arrival' => $ouverture + self::RALLY_WINDOW_SECONDS]);

        $proprietaire = (int)DB::table('planets')->where('id', $cible->getPlanetId())->value('user_id');
        DB::table('users')->where('id', $proprietaire)->update(['tactical_retreat_ratio' => 0]);
        DB::table('planets')->where('id', $cible->getPlanetId())->update([
            'metal' => self::RALLY_STOCK_METAL,
            'crystal' => 50_000,
            'deuterium' => 10_000,
            'rocket_launcher' => 20,
            'time_last_update' => (int)now()->timestamp + 86_400,
        ]);

        resolve(SettingsService::class)->set('persistent_combat_enabled', '1');
        $this->travelTo(Date::createFromTimestamp($ouverture));
        $this->get('/overview')->assertStatus(200);

        $combat = CombatInstance::query()->where('mission_id', $ouvreuse->id)->first();
        $this->assertNotNull($combat, 'The arrival did not open a combat.');
        $this->assertSame(CombatState::Rallying, $combat->status, 'The rally closed at once: the second wave did not hold the window open.');

        return [$combat, $cible->getPlanetId(), $ouverture];
    }

    /**
     * Un transport du joueur courant vers la cible, non traite, aux instants demandes.
     */
    protected function aPendingTransportTowards(int $planetId, int $departure, int $arrival, int $metal): FleetMission
    {
        $origine = $this->planetService;
        $depart = $origine->getPlanetCoordinates();
        $arrivee = DB::table('planets')->where('id', $planetId)->first(['galaxy', 'system', 'planet']);
        $this->assertNotNull($arrivee);

        return FleetMission::forceCreate([
            'user_id' => $this->currentUserId,
            'planet_id_from' => $origine->getPlanetId(),
            'type_from' => 1,
            'galaxy_from' => $depart->galaxy,
            'system_from' => $depart->system,
            'position_from' => $depart->position,
            'planet_id_to' => $planetId,
            'type_to' => 1,
            'galaxy_to' => (int)$arrivee->galaxy,
            'system_to' => (int)$arrivee->system,
            'position_to' => (int)$arrivee->planet,
            'mission_type' => 3,
            'time_departure' => $departure,
            'time_arrival' => $arrival,
            'small_cargo' => 5,
            'metal' => $metal,
            'crystal' => 0,
            'deuterium' => 0,
        ]);
    }

    protected function lastMissionDispatched(): FleetMission
    {
        $mission = FleetMission::query()
            ->where('processed', 0)
            ->where('user_id', $this->currentUserId)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($mission, 'No fleet mission was dispatched.');

        return $mission;
    }
}
