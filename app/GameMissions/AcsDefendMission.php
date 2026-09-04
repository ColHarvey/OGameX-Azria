<?php

namespace OGame\GameMissions;

use OGame\Combat\Enums\CombatReasonCode;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Services\EngagedFleetCheck;
use OGame\Combat\Services\FleetMovementGate;
use OGame\Combat\Services\RefusedFleetHomecoming;
use OGame\Combat\Support\RefusedFleetVerdict;
use OGame\Combat\Support\ReturnOrder;
use OGame\Enums\FleetMissionStatus;
use OGame\Enums\FleetSpeedType;
use OGame\GameMissions\Abstracts\GameMission;
use OGame\GameMissions\Models\MissionPossibleStatus;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\CelestialBodyCombatBarrier;
use OGame\Models\CombatInstance;
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
        // **L'expiration du stationnement passe par la meme porte que le rappel et le demi-tour.**
        // Les trois creent un retour ; les laisser lire chacun son propre modele en laissait deux
        // creer deux retours pour une seule flotte.
        resolve(FleetMovementGate::class)->decideUnderLock($mission, function (FleetMission $tenue): void {
            $this->sendHomeWhenTheHoldIsOver($tenue);
        });
    }

    /**
     * Renvoie la flotte au bout de son stationnement, si rien ne l'a deja fait partir.
     *
     * Appele **uniquement** derriere `FleetMovementGate`, sur la mission tenue sous verrou.
     */
    private function sendHomeWhenTheHoldIsOver(FleetMission $mission): void
    {
        // **Le stationnement d'une flotte engagee ne s'acheve pas avant le combat.** La bataille est
        // calculee avec elle a la fermeture et appliquee a l'echeance ; la renvoyer entre les deux la
        // ferait combattre et rentrer. Le travailleur repassera : le combat termine, elle rentre.
        if (resolve(EngagedFleetCheck::class)->isEngaged($mission)) {
            return;
        }

        // **Une flotte deja partie ne repart pas.** Un rappel ou un demi-tour accorde entre le
        // chargement du modele et ce verrou a pose ce drapeau ; le lire ici est tout l'objet de la
        // relecture, et sans lui la flotte recevrait une seconde mission retour.
        if ((int)$mission->processed === 1) {
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
     * Ce qu'une Defense ACS fait a son arrivee physique : retenue si le corps rallie, demi-tour si
     * le combat n'a pas de place pour elle, stationnement sinon.
     *
     * ## La seule entree, et pourquoi elle prend la porte elle-meme
     *
     * Les deux decisions qu'elle enchaine sont privees : rien ne peut retenir ou renvoyer une
     * flotte avec un `FleetMission` qui n'a pas ete relu sous verrou. Une garde de texte sur
     * l'appelant disait la meme chose ; le type, lui, l'impose a tout appelant futur.
     *
     * @return bool Vrai si la flotte a ete renvoyee, et qu'il n'y a plus rien a traiter.
     */
    public function settleArrival(FleetMission $mission, int $now): bool
    {
        return resolve(FleetMovementGate::class)->decideUnderLock($mission, function (FleetMission $tenue) use ($now): bool {
            // Posee sur un corps en ralliement : retenue jusqu'au verdict de l'admission.
            $this->holdIfTheBodyIsRallying($tenue);

            return $this->turnBackIfTheCombatHasNoPlaceForIt($tenue, $now);
        });
    }

    /**
     * Retient la flotte si le corps qu'elle rejoint est en ralliement.
     *
     * ## Pourquoi a l'arrivee physique, et pas a la fermeture
     *
     * Des qu'elle est posee sur le corps, la flotte fait partie de l'etat de ce corps : la laisser
     * partir — rappelee par son proprietaire, ou parce que son stationnement s'acheve — la ferait
     * disparaitre d'une photographie qu'elle a contribue a composer. Le verdict d'admission viendra a
     * la fermeture ; jusque-la elle est tenue, et la fermeture libere celles qu'elle refuse.
     *
     * Le lien est celui que l'arrivee d'une attaque pose deja : `EngagedFleetCheck` le lit, donc le
     * rappel et l'expiration sont fermes par la meme porte.
     */
    private function holdIfTheBodyIsRallying(FleetMission $mission): void
    {
        if ($mission->planet_id_to === null || $mission->combat_instance_id !== null) {
            return;
        }

        $barriere = CelestialBodyCombatBarrier::query()->where('target_body_id', $mission->planet_id_to)->first();

        if ($barriere === null) {
            return;
        }

        $combat = CombatInstance::query()->find($barriere->combat_instance_id);

        if ($combat === null || $combat->status !== CombatState::Rallying) {
            return;
        }

        $mission->combat_instance_id = $combat->id;
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
    private function turnBackIfTheCombatHasNoPlaceForIt(FleetMission $mission, int $now): bool
    {
        if ($mission->planet_id_to === null || (int)$mission->processed === 1) {
            return false;
        }

        // **La disposition suffit, meme si le combat est termine et la barriere levee.** C'est tout
        // l'objet de l'avoir ecrite : une flotte dont le stationnement s'acheve longtemps apres la
        // bataille retrouve sa decision, et ne stationne pas hors photographie faute de barriere a
        // interroger.
        return resolve(RefusedFleetHomecoming::class)->sendHome(
            $mission,
            $now,
            // Le depart et la destination viennent de l'ordre : un renfort refuse a la fermeture
            // repart de la fermeture, quel que soit le retard du travailleur qui l'execute.
            $this->returnOfARefusedFleet(),
            // **Jamais jugee** : elle s'est posee apres la fermeture, personne ne l'a vue. Le combat
            // decide alors, et sa decision s'ecrit avant d'etre executee — comme celle d'une
            // refusee, pour que les deux chemins se relisent de la meme facon.
            function (FleetMission $tenue): RefusedFleetVerdict|null {
                $combat = $this->theCombatThatHasNoPlaceForIt($tenue);

                return $combat === null ? null : new RefusedFleetVerdict(
                    $combat,
                    CombatReasonCode::RallyClosed,
                    ReturnOrder::physicalArrivalOf($tenue)
                );
            }
        );
    }

    /**
     * Le combat qui tient ce corps et n'a pas de place pour cette flotte, s'il y en a un.
     *
     * Il faut une barriere vivante : c'est le seul cas ou une flotte peut n'avoir jamais ete jugee.
     * Une fois le combat termine, toute flotte qui devait repartir porte deja sa disposition.
     */
    private function theCombatThatHasNoPlaceForIt(FleetMission $mission): CombatInstance|null
    {
        $barriere = CelestialBodyCombatBarrier::query()->where('target_body_id', $mission->planet_id_to)->first();

        if ($barriere === null) {
            return null;
        }

        $combat = CombatInstance::query()->find($barriere->combat_instance_id);

        if ($combat === null || $combat->status === CombatState::Rallying || $combat->status->isFinal()) {
            return null;
        }

        $inscrite = CombatParticipant::query()
            ->where('combat_instance_id', $combat->id)
            ->where('fleet_mission_id', $mission->id)
            ->exists();

        return $inscrite ? null : $combat;
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
