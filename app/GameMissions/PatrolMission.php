<?php

namespace OGame\GameMissions;

use OGame\Enums\FleetMissionStatus;
use OGame\Enums\FleetSpeedType;
use OGame\GameMissions\Abstracts\GameMission;
use OGame\GameMissions\Models\MissionPossibleStatus;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Planet\Coordinate;
use OGame\Services\PlanetService;
use RuntimeException;

/**
 * Un segment de patrouille : le vol d une patrouille vers un point libre de l espace (genre 11).
 *
 * ## Ce qu un segment est, et n est pas
 *
 * C est une ligne de `fleet_missions` comme les autres : elle porte les unites et la cargaison,
 * occupe un creneau de flotte, passe la porte des mouvements, s annonce sur la carte. Ce qu elle
 * n est pas : la patrouille elle-meme. L identite durable — etat, point, reserve, version d ordre,
 * base d attache — vit dans `patrols`, et c est le service des mouvements de patrouille qui regle
 * l arrivee d un segment (poser la patrouille, la facturer, la faire repartir), pas le traitement
 * generique des missions. Voir le journal §114 et les revues 117 a 121 de Codex.
 *
 * ## Ce qui est possible depuis une planete
 *
 * Partir vers un point spatial (type 5), ou vers le point de reference d une planete ou d une lune
 * — la patrouille se pose alors *pres* du corps, jamais dessus. Un champ de debris ou l espace
 * profond de l expedition ne sont pas des destinations : l un est un objet a recycler, l autre une
 * mission a part entiere. Et une patrouille ne part pas sans un vaisseau capable de voler.
 */
class PatrolMission extends GameMission
{
    protected static string $name = 'Patrol';
    protected static int $typeId = 11;
    protected static bool $hasReturnMission = false;
    protected static FleetSpeedType $fleetSpeedType = FleetSpeedType::holding;
    protected static FleetMissionStatus $friendlyStatus = FleetMissionStatus::Friendly;

    /**
     * @inheritdoc
     */
    public function isMissionPossible(PlanetService $planet, Coordinate $targetCoordinate, PlanetType $targetType, UnitCollection $units): MissionPossibleStatus
    {
        $parentCheck = parent::isMissionPossible($planet, $targetCoordinate, $targetType, $units);
        if (!$parentCheck->possible) {
            return $parentCheck;
        }

        // L interrupteur du chantier : eteint, la mission n existe pas pour le joueur.
        if (!$this->settings->patrolsEnabled()) {
            return new MissionPossibleStatus(false);
        }

        if (!in_array($targetType, [PlanetType::SpatialPoint, PlanetType::Planet, PlanetType::Moon], true)) {
            return new MissionPossibleStatus(false);
        }

        if ($units->getAmount() === 0) {
            return new MissionPossibleStatus(false);
        }

        // Un satellite solaire ou un foreur a une vitesse nulle : la duree d un vol serait infinie.
        $player = $planet->getPlayer();
        if ($player === null) {
            return new MissionPossibleStatus(false);
        }

        foreach ($units->units as $unit) {
            if ($unit->unitObject->properties->speed->calculate($player)->totalValue <= 0) {
                return new MissionPossibleStatus(false, __('t_ingame.patrol.refusal_immobile_unit'));
            }
        }

        return new MissionPossibleStatus(true);
    }

    /**
     * @inheritdoc
     */
    protected function processArrival(FleetMission $mission): void
    {
        throw new RuntimeException('L arrivee d un segment de patrouille est reglee par le service des mouvements de patrouille, jamais par le traitement generique (mission ' . $mission->id . ').');
    }

    /**
     * @inheritdoc
     */
    protected function processReturn(FleetMission $mission): void
    {
        throw new RuntimeException('Le retour d un segment de patrouille est regle par le service des mouvements de patrouille, jamais par le traitement generique (mission ' . $mission->id . ').');
    }
}
