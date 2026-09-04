<?php

namespace OGame\Services;

use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Date;
use OGame\Combat\Services\EngagedFleetCheck;
use OGame\Combat\Services\FleetDispositionRegistry;
use OGame\Combat\Services\FleetMovementGate;
use OGame\Enums\FleetSpeedType;
use OGame\Factories\GameMissionFactory;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameConstants\UniverseConstants;
use OGame\GameMessages\AcsDefendArrivalHost;
use OGame\GameMessages\AcsDefendArrivalSender;
use OGame\GameMissions\Abstracts\GameMission;
use OGame\GameMissions\AcsDefendMission;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;

/**
 * Class FleetMissionService.
 *
 * FleetMissionService object.
 *
 * @package OGame\Services
 */
class FleetMissionService
{
    /**
     * The queue model where this class should get its data from.
     *
     * @var FleetMission
     */
    private FleetMission $model;

    /**
     * FleetMissionService constructor.
     */
    public function __construct(private PlayerService $player, private MessageService $messageService, private GameMissionFactory $gameMissionFactory, private SettingsService $settingsService, private CoordinateDistanceCalculator $coordinateDistanceCalculator)
    {
        $this->model = new FleetMission();
    }

    /**
     * Calculate the duration of a fleet mission based on the current planet, target coordinates and fleet.
     *
     * @param PlanetService $fromPlanet
     * @param Coordinate $to
     * @param UnitCollection $units
     * @param GameMission|null $mission
     * @param float $speed_percent
     * @return int
     */
    public function calculateFleetMissionDuration(PlanetService $fromPlanet, Coordinate $to, UnitCollection $units, GameMission|null $mission = null, float $speed_percent = 10): int
    {
        // Get slowest unit speed.
        $player = $fromPlanet->getPlayer();
        if ($player === null) {
            throw new Exception('Planet has no owner.');
        }

        $slowest_speed = $units->getSlowestUnitSpeed($player);
        $distance = $this->calculateFleetMissionDistance($fromPlanet, $to);

        // Determine which fleet speed to use based on mission type.
        // If no mission is provided, use the old fleet_speed for backward compatibility (e.g., for consumption calculations).
        if ($mission === null) {
            $fleetSpeed = $this->settingsService->fleetSpeed();
        } else {
            $fleetSpeed = match ($mission->getFleetSpeedType()) {
                FleetSpeedType::war => $this->settingsService->fleetSpeedWar(),
                FleetSpeedType::holding => $this->settingsService->fleetSpeedHolding(),
                FleetSpeedType::peaceful => $this->settingsService->fleetSpeedPeaceful(),
            };
        }
        return (int) max(
            round(
                (35000 / $speed_percent * sqrt($distance * 10 / $slowest_speed) + 10) / $fleetSpeed
            ),
            1
        );
    }

    /**
     * Calculates the fleet mission distance between two coordinates in a galaxy map.
     *
     * The method determines the distance based on the differences in galaxy, system,
     * and planet positions, while accounting for features like donut-shaped galaxy
     * and system mechanics, empty systems, and inactive systems.
     *
     * Distance Calculation Rules:
     * 1. If the galaxies differ:
     *    Distance = 20,000 * |galaxy1 - galaxy2|
     *    If "donut galaxy" is enabled, the shortest path between galaxies is used.
     *
     * 2. If the systems differ (within the same galaxy):
     *    Distance = 2,700 + (19 * 5 * max(|system1 - system2| - emptySystems - inactiveSystems, 1))
     *    If "donut system" is enabled, the shortest path between systems is used.
     *
     * 3. If only the planets differ (within the same system):
     *    Distance = 1,000 + (5 * |planet1 - planet2|)
     *
     * 4. If the coordinates are identical (same galaxy, system, and planet):
     *    Distance = 5 (minimum distance for same location).
     *
     * Parameters:
     * - $fromPlanet: The starting planet's coordinates (PlanetService object).
     * - $to: The target coordinates (Coordinate object).
     * - $maxGalaxy: Maximum number of galaxies in the map.
     * - $maxSystem: Maximum number of systems in each galaxy.
     * - $emptySystems: Number of empty systems between start and target.
     * - $inactiveSystems: Number of inactive systems between start and target.
     *
     * Returns:
     * - int: The calculated fleet mission distance.
     */
    public function calculateFleetMissionDistance(PlanetService $fromPlanet, Coordinate $to): int
    {
        $fromCoordinate = $fromPlanet->getPlanetCoordinates();

        $diffGalaxy = abs($fromCoordinate->galaxy - $to->galaxy);
        $diffSystem = abs($fromCoordinate->system - $to->system);
        $diffPlanet = abs($fromCoordinate->position - $to->position);

        // If the galaxies are different
        if ($diffGalaxy != 0) {
            $diff2 = abs($diffGalaxy -  $this->settingsService->numberOfGalaxies());

            if ($diff2 < $diffGalaxy) {
                return $diff2 * 20000;
            } else {
                return $diffGalaxy * 20000;
            }
        }

        // If the system are different
        if ($diffSystem != 0) {
            $diff2 = abs($diffSystem - UniverseConstants::MAX_SYSTEM_COUNT);
            $deltaSystem = 0;

            if ($diff2 < $diffSystem) {
                $deltaSystem = $diff2;
            } else {
                $deltaSystem = $diffSystem;
            }

            // Calculate empty and inactive systems to subtract from distance
            $emptySystems = $this->coordinateDistanceCalculator->getNumEmptySystems($fromCoordinate, $to);
            $inactiveSystems = $this->coordinateDistanceCalculator->getNumInactiveSystems($fromCoordinate, $to);

            $deltaSystem = max($deltaSystem - $emptySystems - $inactiveSystems, 1);
            return $deltaSystem * 5 * 19 + 2700;
        }

        // If the planet are different
        if ($diffPlanet != 0) {
            return $diffPlanet * 5 + 1000;
        }

        // If the coordinates are the same
        return 5;
    }

    /**
     * Calculate the consumption of a fleet mission based on the current planet, target coordinates and fleet.
     *
     * @param PlanetService $fromPlanet The planet where the fleet is sent from.
     * @param UnitCollection $ships The units that are sent on the mission.
     * @param Coordinate $targetCoordinate The target coordinate of the mission.
     * @param int $holdingHours The holding time of the fleet. The number represents the amount of hours the fleet will wait at the target planet and/or how long expedition will last.
     * @param float $speedPercent The speed percent of the fleet.
     * @return float|mixed The consumption of the fleet mission.
     */
    public function calculateConsumption(PlanetService $fromPlanet, UnitCollection $ships, Coordinate $targetCoordinate, int $holdingHours, float $speedPercent)
    {
        $player = $fromPlanet->getPlayer();
        if ($player === null) {
            throw new Exception('Planet has no owner.');
        }

        $consumption = 0;
        $holdingCosts = 0;

        $distance = $this->calculateFleetMissionDistance($fromPlanet, $targetCoordinate);
        $duration = $this->calculateFleetMissionDuration($fromPlanet, $targetCoordinate, $ships, null, $speedPercent);
        $speedValue = max(0.5, $duration * $this->settingsService->fleetSpeed() - 10);
        foreach ($ships->units as $shipEntry) {
            // Get the ship object and amount
            $ship = $shipEntry->unitObject; // Ship object
            $shipAmount = $shipEntry->amount; // Amount of ships

            // Calculate the speed of the ship
            $ship_speed = $ship->properties->speed->calculate($player)->totalValue;

            if (!empty($shipAmount)) {
                $shipSpeedValue = 35000 / $speedValue * sqrt($distance * 10 / $ship_speed);
                $holdingCosts += $ship->properties->fuel->rawValue * $shipAmount * $holdingHours;

                $consumption += max(
                    $ship->properties->fuel->rawValue * $shipAmount * $distance / 35000 *
                    (pow(($shipSpeedValue / 10 + 1), 2)),
                    1
                );
            }
        }

        // Calculate the consumption based on the speed percent
        $consumption = round($consumption);

        // Holding costs
        if ($holdingHours > 0) {
            $consumption += max(floor($holdingCosts / 10), 1);
        }

        // Apply General class deuterium consumption reduction (-50%)
        $characterClassService = app(CharacterClassService::class);
        $consumptionMultiplier = $characterClassService->getDeuteriumConsumptionMultiplier($player->getUser());
        $consumption = (int)($consumption * $consumptionMultiplier);

        return $consumption;
    }

    /**
     * Convert a mission type to a human readable label.
     *
     * @param int $missionType
     * @return string
     */
    /**
     * Les libelles traduits, par type de mission.
     *
     * getName() rend le nom anglais ecrit dans la classe : le panneau d'evenements de flotte
     * affichait donc « ACS Defend » a un joueur francais. Les cles existent deja, ce sont
     * celles des boutons de la page Flotte — on les reutilise plutot que d'en creer un second
     * jeu qui finirait par diverger.
     *
     * @var array<int, string>
     */
    private const array MISSION_LABEL_KEYS = [
        1 => 't_ingame.fleet.mission_attack',
        2 => 't_ingame.fleet.mission_attack',
        3 => 't_ingame.fleet.mission_transport',
        4 => 't_ingame.fleet.mission_deploy',
        5 => 't_ingame.fleet.mission_acs_defend',
        6 => 't_ingame.fleet.mission_espionage',
        7 => 't_ingame.fleet.mission_colonise',
        8 => 't_ingame.fleet.mission_recycle',
        9 => 't_ingame.fleet.mission_destroy_moon',
        10 => 't_ingame.galaxy.missile_attack',
        15 => 't_ingame.fleet.mission_expedition',
    ];

    public function missionTypeToLabel(int $missionType): string
    {
        // L'attaque ACS (type 2) partage la classe de l'attaque simple et s'affiche comme
        // une attaque dans le panneau d'evenements.
        $cle = self::MISSION_LABEL_KEYS[$missionType] ?? null;

        if ($cle !== null) {
            return __($cle);
        }

        // Type inconnu : on retombe sur le nom anglais de la classe plutot que sur rien.
        return GameMissionFactory::getMissionById($missionType, [])->getName();
    }

    /**
     * Returns whether a mission type has a return mission by default.
     *
     * @param int $missionType
     * @return bool
     */
    public function missionHasReturnMission(int $missionType): bool
    {
        return GameMissionFactory::getMissionById($missionType, [])->hasReturnMission();
    }

    /**
     * Get all missions that the current player has sent themselves (excluding incoming missions by other players).
     *
     * @return Collection<int, FleetMission>
     */
    public function getActiveFleetMissionsSentByCurrentPlayer(): Collection
    {
        // Note: this only includes missions that the current player has sent themselves
        // so it does not include any incoming missions by other players (e.g. hostile attacks, espionage, transports etc.)
        // Canceled missions are automatically excluded because they have processed = 1
        $query = $this->model->where('user_id', $this->player->getId())
            ->where('processed', 0);
        return $query->orderBy('time_arrival')->get();
    }

    /**
     * Get all active fleet missions for the current user.
     * This includes both hostile/friendly incoming missions and all player outgoing missions.
     *
     * @return Collection<int, FleetMission>
     */
    public function getActiveFleetMissionsForCurrentPlayer(): Collection
    {
        $query = $this->model;

        // Query returns:
        // 1. All missions sent by current user.
        // - AND -
        // 2. All missions against any of current users planets (by other players).
        $planetIds = [];
        foreach ($this->player->planets->all() as $planet) {
            $planetIds[] = $planet->getPlanetId();
        }

        $missions = $query->where(function ($query) use ($planetIds) {
            $query->where('user_id', $this->player->getId())
                ->orWhereIn('planet_id_to', $planetIds);
        })
            ->where('canceled', 0) // Exclude canceled missions
            ->where('processed', 0)
            ->get();

        // Order the list taking into account the time_holding. This ensures that the order of missions is correct
        // for the event list that assumes the first mission is the next mission to arrive.
        $missions = $missions->sortBy(function ($mission) {
            // If the mission has not arrived yet, return the time_arrival.
            if ($mission->time_arrival >= Date::now()->timestamp) {
                return $mission->time_arrival;
            }

            // If the mission has arrived AND has a waiting time, return the time_arrival + holding time.
            // IMPORTANT: Holding time is always real time (not affected by fleet speed)
            $actualHoldingTime = $mission->time_holding ?? 0;

            return $mission->time_arrival + $actualHoldingTime;
        });

        return $missions;
    }

    /**
     * Returns whether the current user is under attack.
     *
     * @return bool
     */
    public function currentPlayerUnderAttack(): bool
    {
        $planetIds = [];
        foreach ($this->player->planets->all() as $planet) {
            $planetIds[] = $planet->getPlanetId();
        }

        // Mission types that are considered hostile:
        // 1: Attack
        // 2: ACS Attack
        // 6: Espionage
        // 9: Moon Destruction
        return $this->model->whereIn('planet_id_to', $planetIds)
            ->where('user_id', '!=', $this->player->getId())
            ->whereIn('mission_type', [1, 2, 6, 9])
            ->where('processed', 0)
            ->exists();
    }

    /**
     * Get the total unit count of a fleet mission.
     *
     * @param FleetMission $mission
     * @return int
     */
    public function getFleetUnitCount(FleetMission $mission): int
    {
        // Loop through all known unit types and sum them up.
        $unit_count = 0;

        foreach (ObjectService::getShipObjects() as $ship) {
            $unit_count += $mission->{$ship->machine_name};
        }

        // Add missiles (they're not in ship objects but can be on missions)
        if (isset($mission->interplanetary_missile)) {
            $unit_count += $mission->interplanetary_missile;
        }

        return $unit_count;
    }

    /**
     * Returns the units of a fleet mission.
     *
     * @param FleetMission $mission
     * @return UnitCollection
     */
    public function getFleetUnits(FleetMission $mission): UnitCollection
    {
        $units = new UnitCollection();

        foreach (ObjectService::getShipObjects() as $ship) {
            $amount = $mission->{$ship->machine_name};
            if ($amount > 0) {
                $units->addUnit($ship, $mission->{$ship->machine_name});
            }
        }

        // Add missiles if present
        if (isset($mission->interplanetary_missile) && $mission->interplanetary_missile > 0) {
            try {
                $missile = ObjectService::getUnitObjectByMachineName('interplanetary_missile');
                $units->addUnit($missile, $mission->interplanetary_missile);
            } catch (Exception $e) {
                // If missile object not found, skip adding it
            }
        }

        return $units;
    }

    /**
     * Returns the resources of a fleet mission.
     *
     * @param FleetMission $mission
     * @return Resources
     */
    public function getResources(FleetMission $mission): Resources
    {
        return new Resources(
            $mission->metal,
            $mission->crystal,
            $mission->deuterium + ($mission->deuterium_consumption / 2),  //if mission deployment: Add half of the consumed deuterium
            0
        );
    }

    /**
     * Get missions that are either from or to the given planets that have reached the arrival + waiting time
     * but are not processed yet.
     *
     * @param int[] $planetIds
     * @return Collection<int, FleetMission>
     */
    public function getArrivedMissionsByPlanetIds(array $planetIds): Collection
    {
        $currentTime = Date::now()->timestamp;

        // Get unprocessed missions that have arrived.
        // For ACS Defend (type 5), time_arrival = physical_arrival + time_holding, so we also
        // include missions where the physical arrival time has passed (to send arrival messages on time).
        $missions = $this->model
            ->where(function ($query) use ($planetIds) {
                $query->whereIn('planet_id_from', $planetIds)
                    ->orWhereIn('planet_id_to', $planetIds);
            })
            ->where('processed', 0)
            ->where(function ($query) use ($currentTime) {
                $query->where('time_arrival', '<=', $currentTime)
                    // ACS Defend: also include missions that have physically arrived but are still holding
                    ->orWhere(function ($query) use ($currentTime) {
                        $query->where('mission_type', 5)
                            ->whereNull('parent_id')
                            ->whereNotNull('time_holding')
                            ->whereRaw('(time_arrival - time_holding) <= ?', [$currentTime]);
                    });
            })
            ->get();

        // Filter based on mission type and hold time
        return $missions->filter(function ($mission) use ($currentTime) {
            // ACS Defend outbound: time_arrival includes hold time, process immediately when arrived
            $isAcsDefendOutbound = ($mission->mission_type === 5 && $mission->parent_id === null);
            if ($isAcsDefendOutbound) {
                return true;
            }

            // Holding time is always real time (not affected by fleet speed modifier)
            if ($mission->time_holding !== null) {
                return ($mission->time_arrival + $mission->time_holding) <= $currentTime;
            }

            return true;
        });
    }

    /**
     * Get missions that are either from or to the given planets that are currently
     * underway and have not been processed yet.
     *
     * @param int[] $planetIds
     * @return Collection<int, FleetMission>
     */
    public function getActiveMissionsByPlanetIds(array $planetIds): Collection
    {
        return $this->model
            ->where(function ($query) use ($planetIds) {
                $query->whereIn('planet_id_from', $planetIds)
                    ->orWhereIn('planet_id_to', $planetIds);
            })
            ->where('processed', 0)
            ->get();
    }

    /**
     * Get a fleet mission by its ID.
     *
     * @param int $id
     * @param bool $only_active
     * @return FleetMission|null
     */
    public function getFleetMissionById(int $id, bool $only_active = true): FleetMission|null
    {
        if ($only_active) {
            return $this->model
                ->where('id', $id)
                ->where('processed', 0)
                ->first();
        } else {
            return $this->model
                ->where('id', $id)
                ->first();
        }
    }

    /**
     * Get a fleet mission by its parent ID. E.g. this can be used to find the return trip mission launched for a given parent mission ID.
     *
     * @param int $parent_id
     * @param bool $only_active
     * @return FleetMission
     */
    public function getFleetMissionByParentId(int $parent_id, bool $only_active = true): FleetMission
    {
        if ($only_active) {
            $mission = $this->model
                ->where('parent_id', $parent_id)
                ->where('processed', 0)
                ->first();
        } else {
            $mission = $this->model
                ->where('parent_id', $parent_id)
                ->first();
        }

        if ($mission === null) {
            throw new Exception('Fleet mission not found.');
        }

        return $mission;
    }

    /**
     * Creates a new fleet mission for the current planet.
     *
     * @param PlanetService $planet The planet where the fleet is sent from.
     * @param Coordinate $targetCoordinate The target coordinate.
     * @param PlanetType $targetType The type of the target.
     * @param int $missionType The type of the mission.
     * @param UnitCollection $units The units that are sent.
     * @param Resources $resources The resources that are sent.
     * @param float $speedPercent The speed percent of the fleet.
     * @param int $holdingHours The holding time of the fleet.
     * @param int $parent_id Optionally the parent mission ID if this is a follow-up mission.
     * @return FleetMission
     * @throws Exception
     */
    public function createNewFromPlanet(PlanetService $planet, Coordinate $targetCoordinate, PlanetType $targetType, int $missionType, UnitCollection $units, Resources $resources, float $speedPercent, int $holdingHours = 0, int $parent_id = 0, bool $retreatAfterDefenderRetreat = false): FleetMission
    {
        // **Un compte en suppression en attente ne lance plus rien.** C'est ici que la course se
        // ferme : sans ce refus, une flotte partie apres l'inventaire des combats du compte pourrait
        // ouvrir une bataille que personne n'annulerait, et sa barriere tiendrait un corps pour
        // toujours. Le refus vaut pour tous les genres de mission, pas seulement l'attaque : une
        // Defense ACS ou un deploiement laisserait la meme flotte derriere un compte disparu.
        $proprietaire = $planet->getPlayer();

        if ($proprietaire !== null && $proprietaire->isPendingDeletion()) {
            throw new Exception('Ce compte est en cours de suppression : il ne peut plus lancer de flotte.');
        }

        $missionObject = $this->gameMissionFactory->getMissionById($missionType, [
            'fleetMissionService' => $this,
            'messageService' => $this->messageService,
        ]);
        return $missionObject->start($planet, $targetCoordinate, $targetType, $units, $resources, $speedPercent, $holdingHours, $parent_id, $retreatAfterDefenderRetreat);
    }

    /**
     * Process a fleet mission.
     *
     * @param FleetMission $mission
     * @return void
     */
    public function updateMission(FleetMission $mission): void
    {
        // Load the mission object again from database to ensure we have the latest data.
        $mission = $this->getFleetMissionById($mission->id, false);
        if ($mission === null) {
            return;
        }

        // Sanity check: only process missions that have arrived AND potential waiting time has passed.
        // Different mission types handle hold time differently:
        // - ACS Defend outbound (type 5, no parent): Send arrival messages at physical arrival, create return mission after hold
        // - ACS Defend return (type 5, with parent): Normal processing, no hold time
        // - Expedition (type 15): Process after hold time (exploration period)
        // - Other missions: No hold time
        // IMPORTANT: Holding time is always real time for ALL missions (not affected by fleet speed)
        $holdTime = 0;
        $isAcsDefendOutbound = ($mission->mission_type === 5 && $mission->parent_id === null);

        if ($mission->time_holding !== null && !$isAcsDefendOutbound) {
            $holdTime = $mission->time_holding;
        }

        // Special handling for ACS Defend outbound: send arrival messages at physical arrival time
        // This must happen BEFORE the time check so messages are sent even if time has passed
        // For ACS Defend, time_arrival = physical_arrival + time_holding (game time)
        // So physical_arrival = time_arrival - time_holding
        if ($isAcsDefendOutbound && $mission->time_holding !== null && $mission->processed_hold == 0) {
            $physicalArrivalTime = $mission->time_arrival - $mission->time_holding;

            // If we've reached physical arrival and haven't sent hold-processed messages yet
            if ($physicalArrivalTime <= Date::now()->timestamp) {
                // Mark as processed_hold to avoid sending messages multiple times
                $mission->processed_hold = 1;
                $mission->save();

                // Send arrival messages to sender and host
                $this->sendAcsDefendArrivalMessages($mission);
            }
        }

        // Une Defense ACS arrivee sur un corps dont le combat n'a pas de place pour elle repart :
        // refusee a la fermeture, ou arrivee apres. Elle ne stationne jamais hors photographie.
        if ($isAcsDefendOutbound && !$mission->processed && $mission->time_holding !== null
            && ($mission->time_arrival - $mission->time_holding) <= Date::now()->timestamp) {
            $defense = $this->gameMissionFactory->getMissionById(5, [
                'fleetMissionService' => $this,
                'messageService' => $this->messageService,
            ]);

            if ($defense instanceof AcsDefendMission) {
                // **Retenue et demi-tour se decident derriere la meme porte que le rappel**, sur une
                // mission relue sous verrou — et c'est la mission qui prend la porte, pour qu'aucun
                // appelant ne puisse decider avec un modele d'avant.
                if ($defense->settleArrival($mission, (int)Date::now()->timestamp)) {
                    return;
                }

                // **Pas de relecture ici, et c'est voulu.** L'objet que ce code tient decrit peut-etre
                // un passe, mais aucune ecriture ne s'en deduit : tout ce qui suit ne fait que decider
                // s'il faut *tenter* le traitement d'arrivee, lequel repasse par la porte et relit la
                // ligne avant d'ecrire. Une relecture de plus ici couterait une requete et donnerait
                // l'illusion que c'est elle qui protege.
            }
        }

        $arrivalTimeWithWaitingTime = $mission->time_arrival + $holdTime;
        if ($arrivalTimeWithWaitingTime > Date::now()->timestamp) {
            return;
        }

        // Sanity check: only process missions that have not been processed yet.
        if ($mission->processed) {
            return;
        }

        $missionObject = $this->gameMissionFactory->getMissionById($mission->mission_type, [
            'fleetMissionService' => $this,
            'messageService' => $this->messageService,
        ]);
        $missionObject->process($mission);
    }

    /**
     * Send arrival messages for ACS Defend missions.
     * Called when the fleet physically arrives at the destination (start of hold time).
     *
     * @param FleetMission $mission
     * @return void
     */
    private function sendAcsDefendArrivalMessages(FleetMission $mission): void
    {
        $planetServiceFactory = app(PlanetServiceFactory::class);

        if ($mission->planet_id_from === null || $mission->planet_id_to === null) {
            throw new Exception('Fleet mission is missing origin or target planet.');
        }

        $origin_planet = $planetServiceFactory->make($mission->planet_id_from, true);
        $target_planet = $planetServiceFactory->make($mission->planet_id_to, true);

        if ($origin_planet === null || $target_planet === null) {
            throw new Exception('Origin or target planet not found.');
        }

        $origin_player = $origin_planet->getPlayer();
        $target_player = $target_planet->getPlayer();

        if ($origin_player === null || $target_player === null) {
            throw new Exception('Origin or target planet has no owner.');
        }

        // Send message to sender (Fleet Command)
        $this->messageService->sendSystemMessageToPlayer($origin_player, AcsDefendArrivalSender::class, [
            'to' => '[planet]' . $mission->planet_id_to . '[/planet]',
        ]);

        // Send message to host/target (Space Monitoring)
        $this->messageService->sendSystemMessageToPlayer($target_player, AcsDefendArrivalHost::class, [
            'to' => '[planet]' . $mission->planet_id_to . '[/planet]',
        ]);
    }

    /**
     * Cancel a fleet mission.
     *
     * @param FleetMission $mission
     * @return void
     */
    public function cancelMission(FleetMission $mission): void
    {
        // Planet relocation ship transfers (deployment to self) cannot be recalled.
        if ($mission->mission_type === 4 && $mission->planet_id_from === $mission->planet_id_to) {
            return;
        }

        // **Le rappel entre par la meme porte que le demi-tour et l'expiration**, et decide sur une
        // mission relue sous verrou. Il lisait auparavant l'objet charge par l'appelant : une flotte
        // renvoyee ou inscrite dans l'intervalle etait rappelee quand meme, et deux missions retour
        // pouvaient naitre d'une seule flotte.
        resolve(FleetMovementGate::class)->decideUnderLock($mission, function (FleetMission $tenue): void {
            $this->recallIfNothingHoldsIt($tenue);
        });
    }

    /**
     * Rappelle la flotte, si rien ne la retient.
     *
     * Appele **uniquement** derriere `FleetMovementGate`, sur la mission tenue sous verrou : chacune
     * de ces lectures decide d'une ecriture, et aucune ne vaut sur un modele charge plus tot.
     */
    private function recallIfNothingHoldsIt(FleetMission $mission): void
    {
        // Une flotte engagee dans un combat durable ne se rappelle plus : la bataille est calculee
        // avec elle, et un rappel la ferait a la fois combattre et rentrer. Le filet est ici, dans
        // le chemin que tout rappel emprunte — l'interface n'est jamais la protection.
        if (resolve(EngagedFleetCheck::class)->isEngaged($mission)) {
            return;
        }

        // **Un aller qui a deja son retour ne repart pas une seconde fois.** L'invariant vaut pour
        // tout genre de mission : le retour est la trace que la flotte est partie, et en creer un
        // second ferait exister ses vaisseaux deux fois. Les gardes d'heure ci-dessous ne le
        // couvraient que par accident — un demi-tour qui reecrivait `time_arrival` les faisait
        // tomber juste, et cesser de reecrire ce fait historique a rouvert le defaut.
        if (FleetMission::query()->where('parent_id', $mission->id)->exists()) {
            return;
        }

        // **Le combat a peut-etre deja decide de son mouvement : le clic l'execute, il ne le
        // remplace pas.** Une flotte qui porte une disposition non consommee rentre par ce
        // verdict-la, avec la raison que le joueur lira — tout de suite, sans attendre le prochain
        // passage du travailleur. Ni rappel ordinaire, ni `canceled`, ni raison redecidee : le
        // consommateur commun, et rien d'autre.
        if (resolve(FleetDispositionRegistry::class)->pendingFor($mission) !== null) {
            $this->gameMissionFactory->getMissionById($mission->mission_type, [
                'fleetMissionService' => $this,
                'messageService' => $this->messageService,
            ])->carryOutTheMovementAlreadyDecided($mission, (int)Date::now()->timestamp);

            return;
        }

        // Sanity check: only allow cancelling missions that have not yet arrived.
        // For ACS Defend (type 5), time_arrival already includes the hold time, so when
        // time_arrival < now the hold period has also expired and recall is no longer allowed.
        // For other missions with hold time (e.g. expeditions), canceling is not allowed after arrival.
        if ($mission->time_arrival < Date::now()->timestamp) {
            return;
        }

        // Determine if this is an ACS Defend mission currently within its hold period.
        // For ACS Defend: time_arrival = physical_arrival + time_holding
        // The fleet is in hold when: (time_arrival - time_holding) <= now < time_arrival
        // The processed flag may be set to 1 by AttackMission when the fleet is attacked during hold time,
        // so we must allow recall in that case too.
        $isAcsDefendInHoldTime = (
            $mission->mission_type === 5
            && $mission->time_holding !== null
            && $mission->time_holding > 0
            && ($mission->time_arrival - $mission->time_holding) <= Date::now()->timestamp
        );

        // Sanity check: only allow canceling missions that have not been processed yet.
        // Exception: ACS Defend missions can be recalled during hold time even if processed.
        if ($mission->processed && !$isAcsDefendInHoldTime) {
            return;
        }

        // If an ACS Defend fleet is recalled during hold time and the arrival messages have not
        // been sent yet (processed_hold == 0), send them now. The fleet did physically arrive
        // at the destination even though it is being recalled, so both sender and host must be
        // informed. Without this, a recall before the first page load since physical arrival
        // would skip the messages entirely because cancel() sets processed=1, causing the
        // query in getArrivedMissionsByPlanetIds() to exclude this mission permanently.
        if ($isAcsDefendInHoldTime && $mission->processed_hold == 0) {
            $this->sendAcsDefendArrivalMessages($mission);
            $mission->processed_hold = 1;
            $mission->save();
        }

        $missionObject = $this->gameMissionFactory->getMissionById($mission->mission_type, [
            'fleetMissionService' => $this,
            'messageService' => $this->messageService,
        ]);
        $missionObject->cancel($mission);
    }
}
