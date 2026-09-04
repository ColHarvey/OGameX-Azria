<?php

namespace OGame\GameMissions;

use OGame\Combat\Enums\CombatOutboxKind;
use OGame\Combat\Enums\CombatReasonCode;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Services\EngagedFleetCheck;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Enums\FleetMissionStatus;
use OGame\Enums\FleetSpeedType;
use OGame\GameMissions\Abstracts\GameMission;
use OGame\GameMissions\Models\MissionPossibleStatus;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\CelestialBodyCombatBarrier;
use OGame\Models\CombatInstance;
use OGame\Models\CombatOutboxMessage;
use OGame\Models\CombatParticipant;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Planet\Coordinate;
use OGame\Services\AllianceService;
use OGame\Services\BuddyService;
use OGame\Services\PlanetService;
use RuntimeException;

class AcsDefendMission extends GameMission
{
    protected static string $name = 'ACS Defend';
    protected static int $typeId = 5;
    protected static bool $hasReturnMission = true;
    protected static FleetSpeedType $fleetSpeedType = FleetSpeedType::holding;
    protected static FleetMissionStatus $friendlyStatus = FleetMissionStatus::Friendly;

    /**
     * @inheritdoc
     */
    public function isMissionPossible(PlanetService $planet, Coordinate $targetCoordinate, PlanetType $targetType, UnitCollection $units): MissionPossibleStatus
    {
        // Check parent conditions (vacation mode, same coordinates)
        $parentCheck = parent::isMissionPossible($planet, $targetCoordinate, $targetType, $units);
        if (!$parentCheck->possible) {
            return $parentCheck;
        }

        // ACS is disabled on this server.
        if (!$this->settings->allianceCombatSystemOn()) {
            return new MissionPossibleStatus(false, __('ACS is disabled on this server.'));
        }

        // ACS Defend mission is only possible for planets and moons.
        if (!in_array($targetType, [PlanetType::Planet, PlanetType::Moon])) {
            return new MissionPossibleStatus(false);
        }

        // If target planet does not exist, the mission is not possible.
        $targetPlanet = $this->planetServiceFactory->makeForCoordinate($targetCoordinate, true, $targetType);
        if ($targetPlanet === null) {
            return new MissionPossibleStatus(false);
        }

        // ACS Defend is not possible on destroyed planets/moons.
        if ($destroyedCheck = $this->checkDestroyedTarget($targetPlanet, $targetType, false)) {
            return $destroyedCheck;
        }

        // Cannot send ACS Defend to own planet
        if ($ownPlanetCheck = $this->checkOwnPlanet($planet, $targetPlanet)) {
            return $ownPlanetCheck;
        }

        // If target player is in vacation mode, the mission is not possible.
        if ($vacationCheck = $this->checkTargetVacationMode($targetPlanet)) {
            return $vacationCheck;
        }

        // Check if players are buddies (accepted buddy request exists) or in the same alliance
        $currentPlayer = $planet->getPlayer();
        $targetPlayer = $targetPlanet->getPlayer();
        if ($currentPlayer === null || $targetPlayer === null) {
            return new MissionPossibleStatus(false);
        }
        $currentUserId = $currentPlayer->getUser()->id;
        $targetUserId = $targetPlayer->getUser()->id;

        $buddyService = app(BuddyService::class);
        $isBuddy = $buddyService->areBuddies($currentUserId, $targetUserId);

        $allianceService = app(AllianceService::class);
        $isAllianceMember = $allianceService->arePlayersInSameAlliance($currentUserId, $targetUserId);

        // Only allow ACS Defend to buddies or alliance members
        if (!$isBuddy && !$isAllianceMember) {
            return new MissionPossibleStatus(false, __('You can only send ACS Defend missions to buddies or alliance members!'));
        }

        // If all checks pass, the mission is possible.
        return new MissionPossibleStatus(true);
    }

    /**
     * @inheritdoc
     */
    protected function processArrival(FleetMission $mission): void
    {
        // **Le stationnement d'une flotte engagee ne s'acheve pas avant le combat.** La bataille est
        // calculee avec elle a la fermeture et appliquee a l'echeance ; la renvoyer entre les deux la
        // ferait combattre et rentrer. Le travailleur repassera : le combat termine, elle rentre.
        if (resolve(EngagedFleetCheck::class)->isEngaged($mission)) {
            return;
        }

        // Note: Arrival messages are sent earlier when the fleet physically arrives (start of hold time)
        // via FleetMissionService::sendAcsDefendArrivalMessages()
        // This method is called after the hold time expires to create the return mission

        // Create and start the return mission.
        // Unit counts on the mission record may have been updated by battle processing if the fleet
        // was attacked during hold time, so getFleetUnits() returns the correct post-battle survivors.
        $this->startReturn($mission, $this->fleetMissionService->getResources($mission), $this->fleetMissionService->getFleetUnits($mission));

        // Mark the arrival mission as processed
        $mission->processed = 1;
        $mission->save();
    }

    /**
     * Renvoie la flotte si le combat sur sa cible n'a pas de place pour elle.
     *
     * Une Defense ACS ne stationne jamais hors photographie : refusee a la fermeture, ou arrivee
     * apres elle, elle repart avec sa raison — celle de la fermeture si elle existe, `RallyClosed`
     * sinon. Pendant le ralliement elle attend son jugement ; sans combat, elle stationne comme
     * toujours. Le retour part maintenant et dure le trajet aller, comme un rappel.
     *
     * @return bool Vrai si la flotte a ete renvoyee.
     */
    public function turnBackIfTheCombatHasNoPlaceForIt(FleetMission $mission, int $now): bool
    {
        if ($mission->planet_id_to === null || (int)$mission->processed === 1) {
            return false;
        }

        $barriere = CelestialBodyCombatBarrier::query()->where('target_body_id', $mission->planet_id_to)->first();

        if ($barriere === null) {
            return false;
        }

        $combat = CombatInstance::query()->find($barriere->combat_instance_id);

        if ($combat === null || $combat->status === CombatState::Rallying || $combat->status->isFinal()) {
            return false;
        }

        $inscrite = CombatParticipant::query()
            ->where('combat_instance_id', $combat->id)
            ->where('fleet_mission_id', $mission->id)
            ->exists();

        if ($inscrite) {
            return false;
        }

        // La raison de la fermeture, si elle l'a jugee ; sinon elle est arrivee apres.
        CombatOutboxMessage::query()->firstOrCreate(
            [
                'combat_instance_id' => $combat->id,
                'participant_key' => CombatParticipantKey::forFleet($mission->id),
                'kind' => CombatOutboxKind::RallyRefused->value,
            ],
            [
                'payload' => [
                    'reason' => CombatReasonCode::RallyClosed->value,
                    'target_body_id' => $combat->target_planet_id,
                    'galaxy' => $combat->galaxy,
                    'system' => $combat->system,
                    'position' => $combat->position,
                    'group_fleets' => 1,
                ],
                'available_at' => $now,
            ]
        );

        $arriveePhysique = $mission->time_arrival - ($mission->time_holding ?? 0);

        $mission->time_arrival = $now;
        $mission->time_holding = 0;
        $mission->processed = 1;
        $mission->save();

        $this->startReturn(
            $mission,
            $this->fleetMissionService->getResources($mission),
            $this->fleetMissionService->getFleetUnits($mission),
            $arriveePhysique - $now
        );

        return true;
    }

    /**
     * @inheritdoc
     */
    protected function processReturn(FleetMission $mission): void
    {
        // Load the destination planet (where ships are returning to)
        if ($mission->planet_id_to === null) {
            throw new RuntimeException('ACS Defend return mission has no target planet.');
        }
        $destination_planet = $this->planetServiceFactory->make($mission->planet_id_to, true);
        if ($destination_planet === null) {
            throw new RuntimeException('ACS Defend return mission target planet does not exist.');
        }
        $targetPlayer = $destination_planet->getPlayer();
        if ($targetPlayer === null) {
            throw new RuntimeException('ACS Defend return mission target planet has no owner.');
        }

        // Return units to the destination planet
        $destination_planet->addUnits($this->fleetMissionService->getFleetUnits($mission));

        // Add resources to the destination planet (if any).
        $return_resources = $this->fleetMissionService->getResources($mission);
        if ($return_resources->any()) {
            $destination_planet->addResources($return_resources);
        }

        // Send message to player that the return mission has arrived.
        $this->sendFleetReturnMessage($mission, $targetPlayer);

        // Mark the return mission as processed
        $mission->processed = 1;
        $mission->save();
    }
}
