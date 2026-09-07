<?php

namespace OGame\Galaxy;

use Illuminate\Support\Facades\Date;
use OGame\Enums\FleetMissionStatus;
use OGame\Models\FleetMission;
use OGame\Services\FleetMissionService;
use OGame\Services\PlayerService;

/**
 * Les mouvements de flotte qu'un joueur a le droit de voir dans un systeme donne.
 *
 * ## D'ou vient le droit
 *
 * D'un seul endroit, qui existait avant la carte : `FleetMissionService::getActiveFleetMissionsForCurrentPlayer()`
 * — les missions que le joueur a envoyees, et celles qui visent l'une de ses planetes. C'est
 * exactement ce que la boite d'evenements lui montre deja. La carte n'elargit rien : elle **filtre**
 * cet ensemble aux missions dont l'origine ou la destination est dans le systeme affiche.
 *
 * Ce que ce filtre refuse, et que le cahier des charges interdit : la mission d'un tiers vers un
 * tiers, meme dans le systeme regarde ; la flotte d'un allie qui ne vise pas le joueur ; toute
 * phalange gratuite. Une mission invisible n'est pas masquee — elle **n'est pas envoyee**.
 *
 * ## Ce qui voyage, et ce qui reste au serveur
 *
 * Les deux instants autoritatifs (depart, arrivee) et l'heure du serveur, pour que le navigateur
 * interpole une position sans jamais la calculer lui-meme. Le genre de mission et son camp, pour
 * l'icone et la couleur. Les coordonnees des deux bouts, pour tracer. **Ni unites, ni ressources,
 * ni cargaison** : la carte n'en a pas besoin, et une charge utile qui les porterait pour rien
 * serait une charge utile qui les revelerait un jour par erreur.
 */
final class FleetMovementProjection
{
    public function __construct(
        private readonly FleetMissionService $fleetMissionService,
        private readonly PlayerService $player,
    ) {
    }

    /**
     * Les mouvements visibles du joueur qui touchent le systeme demande.
     *
     * @return array<int, array<string, mixed>>
     */
    public function inSystem(int $galaxy, int $system): array
    {
        $mouvements = [];

        foreach ($this->fleetMissionService->getActiveFleetMissionsForCurrentPlayer() as $mission) {
            if (!$this->touches($mission, $galaxy, $system)) {
                continue;
            }

            $mouvements[] = $this->project($mission);
        }

        return $mouvements;
    }

    /**
     * L'heure du serveur, publiee avec les mouvements : c'est elle que le navigateur suit, jamais
     * la sienne — deux horloges qui divergent feraient arriver une flotte avant l'heure.
     */
    public function serverNow(): int
    {
        return (int)Date::now()->timestamp;
    }

    /**
     * Une mission touche un systeme si elle en part ou y arrive.
     */
    private function touches(FleetMission $mission, int $galaxy, int $system): bool
    {
        $part = (int)$mission->galaxy_from === $galaxy && (int)$mission->system_from === $system;
        $arrive = (int)$mission->galaxy_to === $galaxy && (int)$mission->system_to === $system;

        return $part || $arrive;
    }

    /**
     * @return array<string, mixed>
     */
    private function project(FleetMission $mission): array
    {
        return [
            'id' => (int)$mission->id,
            'mission_type' => (int)$mission->mission_type,
            'label' => $this->fleetMissionService->missionTypeToLabel((int)$mission->mission_type),
            'side' => $this->sideOf($mission)->value,
            'is_return' => !empty($mission->parent_id),
            'from' => [
                'galaxy' => (int)$mission->galaxy_from,
                'system' => (int)$mission->system_from,
                'position' => (int)$mission->position_from,
                'type' => (int)$mission->type_from,
            ],
            'to' => [
                'galaxy' => (int)$mission->galaxy_to,
                'system' => (int)$mission->system_to,
                'position' => (int)$mission->position_to,
                'type' => (int)$mission->type_to,
            ],
            'time_departure' => (int)$mission->time_departure,
            'time_arrival' => (int)$mission->time_arrival,
        ];
    }

    /**
     * Le camp d'une mission, vu par le joueur qui regarde.
     *
     * La regle est celle de la boite d'evenements (`FleetEventsController::determineFriendly()`),
     * reprise telle quelle : une mission qui n'est pas la sienne est hostile si elle attaque,
     * espionne, detruit une lune ou lance un missile ; neutre si elle transporte ou defend ;
     * amie dans tous les autres cas — dont toutes les siennes.
     */
    private function sideOf(FleetMission $mission): FleetMissionStatus
    {
        if ((int)$mission->user_id === $this->player->getId()) {
            return FleetMissionStatus::Friendly;
        }

        return match ((int)$mission->mission_type) {
            1, 2, 6, 9, 10 => FleetMissionStatus::Hostile,
            3, 5 => FleetMissionStatus::Neutral,
            default => FleetMissionStatus::Friendly,
        };
    }
}
