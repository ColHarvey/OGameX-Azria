<?php

namespace OGame\GameMissions;

use OGame\Combat\Services\CombatResolutionService;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Combat\Support\LootContextForMission;
use OGame\Combat\Support\OperationKey;
use OGame\Combat\Support\ResourceDiagnosticsJournal;
use OGame\Combat\Support\SealedResourceDiagnostics;
use OGame\Enums\FleetMissionStatus;
use OGame\Enums\FleetSpeedType;
use OGame\GameMissions\Abstracts\GameMission;
use OGame\GameMissions\BattleEngine\PhpBattleEngine;
use OGame\GameMissions\BattleEngine\RustBattleEngine;
use OGame\GameMissions\Models\MissionPossibleStatus;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;
use OGame\Services\PlanetService;
use OGame\Services\WreckFieldService;
use RuntimeException;
use Throwable;

class AttackMission extends GameMission
{
    protected static string $name = 'Attack';
    protected static int $typeId = 1;
    protected static bool $hasReturnMission = true;
    protected static bool $blockedByServerAttackBlock = true;
    protected static FleetSpeedType $fleetSpeedType = FleetSpeedType::war;
    protected static FleetMissionStatus $friendlyStatus = FleetMissionStatus::Hostile;

    /**
     * @inheritdoc
     */
    public function isMissionPossible(PlanetService $planet, Coordinate $targetCoordinate, PlanetType $targetType, UnitCollection $units): MissionPossibleStatus
    {
        $parentCheck = parent::isMissionPossible($planet, $targetCoordinate, $targetType, $units);
        if (!$parentCheck->possible) {
            return $parentCheck;
        }

        // Attack mission is only possible for planets and moons.
        if (!in_array($targetType, [PlanetType::Planet, PlanetType::Moon])) {
            return new MissionPossibleStatus(false);
        }

        // If target planet does not exist, the mission is not possible.
        $targetPlanet = $this->planetServiceFactory->makeForCoordinate($targetCoordinate, true, $targetType);
        if ($targetPlanet === null) {
            return new MissionPossibleStatus(false);
        }

        // Destroyed moons cannot be attacked; destroyed planets can.
        if ($destroyedCheck = $this->checkDestroyedTarget($targetPlanet, $targetType, true)) {
            return $destroyedCheck;
        }

        // If planet belongs to current player, the mission is not possible.
        if ($ownPlanetCheck = $this->checkOwnPlanet($planet, $targetPlanet)) {
            return $ownPlanetCheck;
        }

        // If target player is in vacation mode, the mission is not possible.
        if ($vacationCheck = $this->checkTargetVacationMode($targetPlanet)) {
            return $vacationCheck;
        }

        // Legor's planet (Arakis at 1:1:2) cannot be attacked
        if ($adminCheck = $this->checkAdminProtection($targetPlanet, __('This planet belongs to an administrator and cannot be attacked.'))) {
            return $adminCheck;
        }

        // If all checks pass, the mission is possible.
        return new MissionPossibleStatus(true);
    }

    /**
     * @inheritdoc
     * @throws Throwable
     */
    protected function processArrival(FleetMission $mission): void
    {
        // In a union, only the initiator (slot 1) should execute the battle.
        // Non-initiator missions are collected by collectAttackingFleets() and their
        // return missions are handled by the multi-attacker processing block.
        if ($mission->isInUnion() && $mission->union_slot !== 1) {
            return;
        }

        if ($mission->planet_id_to === null) {
            throw new RuntimeException('Attack mission has no target planet.');
        }
        $defenderPlanet = $this->planetServiceFactory->make($mission->planet_id_to, true);
        if ($defenderPlanet === null) {
            throw new RuntimeException('Attack mission target planet does not exist.');
        }
        $defenderPlayer = $defenderPlanet->getPlayer();
        if ($defenderPlayer === null) {
            throw new RuntimeException('Attack mission target planet has no owner.');
        }

        // Troisieme verrou du mode vacances, et le seul de ce projet qui corrige un
        // comportement existant au lieu d'en emprunter un.
        //
        // canActivateVacationMode() ne regarde que les flottes sortantes du joueur : rien
        // ne l'empeche donc de partir en conge alors qu'une flotte hostile est deja en
        // route, et processArrival() ne recontrolait pas. La sequence « le raid part, le
        // joueur part en conge, le raid arrive » aboutissait au combat.
        //
        // La correction est volontairement bornee aux PNJ : le combat entre joueurs est un
        // comportement de jeu qui se discute, pas un defaut a corriger au passage. Face a
        // une faction, en revanche, la promesse « en conge, on ne risque rien » doit tenir
        // precisement quand le joueur en a le plus besoin.
        if ($defenderPlayer->isInVacationMode() && CombatResolutionService::isNpcAttack($mission)) {
            $mission->processed = 1;
            $mission->save();

            $this->startReturn(
                $mission,
                $this->fleetMissionService->getResources($mission),
                $this->fleetMissionService->getFleetUnits($mission)
            );

            return;
        }

        // Trigger defender planet update to make sure the battle uses up-to-date info.
        $defenderPlanet->update();

        // Collect all attacking fleets (single or union)
        $attackerFleets = $this->collectAttackingFleets($mission);

        // Get the initiator (first fleet) for things like origin planet
        $initiatorFleet = $attackerFleets[0];
        $attackerPlayer = $initiatorFleet->player;

        // Collect all defending fleets (planet owner + ACS defend fleets)
        $defenders = $this->collectDefendingFleets($defenderPlanet);

        // **Une attaque pille.** Les faits sont photographies ici, une fois, et pour les deux
        // moteurs : l'inactivite de la cible et le fret engage doivent etre les memes quel que
        // soit le moteur configure.
        $lootContext = LootContextForMission::lootingOrDegraded($attackerFleets, $defenderPlanet, 'attack', $mission->id);

        // Execute the battle logic using configured battle engine
        switch ($this->settings->battleEngine()) {
            case 'php':
                $battleEngine = new PhpBattleEngine($attackerFleets, $defenderPlanet, $defenders, $this->settings, $lootContext);
                break;
            case 'rust':
            default:
                // Default to RustBattleEngine if no specific engine is configured
                $battleEngine = new RustBattleEngine($attackerFleets, $defenderPlanet, $defenders, $this->settings, $lootContext);
                break;
        }

        $battleEngine->setRetreatAfterDefenderRetreat((bool)$mission->retreat_after_defender_retreat);
        $battleResult = $battleEngine->simulateBattle();

        // Set the attacker's origin planet ID on the battle result for the battle report.
        if ($mission->planet_id_from === null) {
            throw new RuntimeException('Attack mission has no origin planet.');
        }
        // Retenu dans une variable locale : la planete d'origine est utilisee plus bas, apres
        // des appels qui prennent la mission en parametre, et la garantie de non-nullite
        // obtenue ci-dessus ne survit pas a ces appels.
        $originPlanetId = $mission->planet_id_from;
        $battleResult->attackerPlanetId = $originPlanetId;

        // L'application du resultat vit dans CombatResolutionService.
        //
        // Cette methode faisait 515 lignes et melait quatre roles : garder, collecter, simuler,
        // appliquer. Les trois premiers restent ici — ils decrivent ce qu'est une attaque. Le
        // quatrieme n'a rien de propre a l'attaque : il applique un resultat deja calcule, et
        // c'est ce qui permettra un jour de l'appliquer plus tard qu'a l'arrivee.
        //
        // startReturn() est protected ici : la fermeture la rend accessible au service sans
        // elargir sa visibilite pour tout le monde.
        $resolution = resolve(CombatResolutionService::class)->resolve(
            $mission,
            $battleResult,
            $defenderPlanet,
            $defenderPlayer,
            $attackerFleets,
            $attackerPlayer,
            $defenders,
            $originPlanetId,
            $this,
            function (FleetMission $retourDe, Resources $ressources, UnitCollection $unites, int $tempsSupplementaire = 0, array|null $epaves = null, int|null $dureeImposee = null): void {
                $this->startReturn($retourDe, $ressources, $unites, $tempsSupplementaire, $epaves, $dureeImposee);
            },
        );

        // **Le seul journal de l operation, et la fusion de ses deux sources.**
        //
        // Le moteur a fige ses diagnostics dans le resultat ; la resolution a rendu les siens
        // separement, parce qu ils appartiennent a l application du resultat et non a son calcul.
        // La mission est le seul appelant qui voie l attaque entiere, et le seul a connaitre son
        // identite.
        // **Chaque source est scellee avant la fusion, jamais apres.** Fusionner deux collections
        // brutes puis apposer une cle effacerait justement l'information qui permet de constater
        // qu'elles ne viennent pas de la meme operation.
        $operation = OperationKey::forFleetMission($mission);

        ResourceDiagnosticsJournal::report(
            SealedResourceDiagnostics::seal($operation, $battleResult->resourceDiagnostics)
                ->mergedWith(SealedResourceDiagnostics::seal($operation, $resolution->diagnostics)),
            ['target_body' => CombatParticipantKey::forBody($defenderPlanet)]
        );
    }

    /**
     * @inheritdoc
     */
    protected function processReturn(FleetMission $mission): void
    {
        // Load the target planet
        if ($mission->planet_id_to === null) {
            throw new RuntimeException('Attack return mission has no target planet.');
        }
        $target_planet = $this->planetServiceFactory->make($mission->planet_id_to, true);
        if ($target_planet === null) {
            throw new RuntimeException('Attack return mission target planet does not exist.');
        }
        $targetPlayer = $target_planet->getPlayer();
        if ($targetPlayer === null) {
            throw new RuntimeException('Attack return mission target planet has no owner.');
        }

        // Attack return trip: add back the units to the source planet. Then we're done.
        $target_planet->addUnits($this->fleetMissionService->getFleetUnits($mission));

        // Add resources to the origin planet (if any).
        $return_resources = $this->fleetMissionService->getResources($mission);
        if ($return_resources->any()) {
            $target_planet->addResources($return_resources);
        }

        // Create wreck field at origin planet if data exists (General class perk)
        // The wreck field is created from the attacker's lost ships and appears at the origin planet
        if (!empty($mission->wreck_field_data) && is_array($mission->wreck_field_data)) {
            $wreckFieldService = new WreckFieldService($targetPlayer, $this->settings);

            // Determine coordinates for wreck field
            // If returning to a moon, create wreck field at the planet's coordinates
            $wreckFieldCoordinates = $target_planet->isMoon()
                ? $target_planet->planet()->getPlanetCoordinates()
                : $target_planet->getPlanetCoordinates();

            // Create wreck field at origin planet
            $wreckFieldService->createWreckField(
                $wreckFieldCoordinates,
                $mission->wreck_field_data,
                $targetPlayer->getId()
            );
        }

        // Send message to player that the return mission has arrived.
        $this->sendFleetReturnMessage($mission, $targetPlayer);

        // Mark the return mission as processed
        $mission->processed = 1;
        $mission->save();
    }
}
