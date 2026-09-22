<?php

namespace Tests\Feature;

use OGame\Models\FleetMission;
use OGame\Models\Patrol;
use OGame\Models\Resources;
use OGame\Patrol\Enums\PatrolState;

/**
 * Le montage d une bataille en espace libre : une patrouille posee avec sa cargaison, et une attaquante
 * arrivee sur son point.
 *
 * ## Pourquoi il vit a part
 *
 * Deux bancs en ont besoin — celui des effets du reglement, et celui de la conservation des cargaisons. Un
 * montage recopie diverge : une cargaison posee d un cote seulement, et les deux bancs ne parlent plus de la
 * meme bataille.
 */
trait StagesASpatialBattle
{
    /**
     * Monte une patrouille posee, avec son segment, ses unites et sa cargaison.
     *
     * @param array<string, int> $unites
     * @param Resources|null $cargaison Ce que le segment porte ; par defaut 4000 de metal et 2000 de cristal.
     * @return array{0: Patrol, 1: FleetMission}
     */
    protected function unePatrouillePosee(array $unites, int $x = 120, int $y = -80, int $galaxie = 4, int $systeme = 77, Resources|null $cargaison = null, int|null $proprietaire = null): array
    {
        // **Un banc qui exige une issue precise nomme son defenseur.** Par defaut on garde le comportement
        // d origine — le premier compte venu du processus —, mais un essai dont la conclusion depend des
        // caracteristiques peut passer le sien, pose et tenu par lui.
        $defenseur = $proprietaire ?? $this->getSecondPlayerId();

        $patrouille = Patrol::forceCreate([
            'user_id' => $defenseur,
            'home_planet_id' => null,
            'state' => PatrolState::Stationed,
            'galaxy' => $galaxie,
            'system' => $systeme,
            'x' => $x,
            'y' => $y,
            'fuel_reserve' => 5000.0,
            'upkeep_paid_at' => null,
            'order_version' => 1,
        ]);

        $segment = new FleetMission();
        $segment->user_id = $defenseur;
        $segment->patrol_id = (int)$patrouille->id;
        $segment->mission_type = 11;
        $segment->galaxy_to = $galaxie;
        $segment->system_to = $systeme;
        $segment->x_to = $x;
        $segment->y_to = $y;
        $segment->time_departure = (int)now()->timestamp - 3600;
        $segment->time_arrival = (int)now()->timestamp - 1800;
        $segment->processed = 0;
        $segment->canceled = 0;
        $segment->metal = $cargaison?->metal->get() ?? 4000;
        $segment->crystal = $cargaison?->crystal->get() ?? 2000;
        $segment->deuterium = $cargaison?->deuterium->get() ?? 0;

        foreach ($unites as $type => $nombre) {
            $segment->{$type} = $nombre;
        }

        $segment->save();

        $patrouille->forceFill(['current_mission_id' => (int)$segment->id])->save();

        return [$patrouille, $segment];
    }

    /**
     * Monte une flotte attaquante arrivee sur le point.
     *
     * @param array<string, int> $unites
     * @param Resources|null $cargaison Ce que l attaquante emporte ; par defaut rien.
     */
    protected function uneAttaqueArrivee(Patrol $cible, FleetMission $segment, array $unites, Resources|null $cargaison = null): FleetMission
    {
        $mission = new FleetMission();
        $mission->user_id = $this->currentUserId;
        $mission->mission_type = 1;
        $mission->planet_id_from = $this->planetService->getPlanetId();
        $mission->planet_id_to = null;
        $mission->target_patrol_id = (int)$cible->id;
        $mission->target_patrol_owner_id = (int)$cible->user_id;
        $mission->galaxy_from = 1;
        $mission->system_from = 1;
        $mission->position_from = 1;
        $mission->galaxy_to = (int)$cible->galaxy;
        $mission->system_to = (int)$cible->system;
        $mission->x_to = (int)$segment->x_to;
        $mission->y_to = (int)$segment->y_to;
        $mission->time_departure = (int)now()->timestamp - 600;
        $mission->time_arrival = (int)now()->timestamp;
        $mission->processed = 0;
        $mission->canceled = 0;
        $mission->metal = $cargaison?->metal->get() ?? 0;
        $mission->crystal = $cargaison?->crystal->get() ?? 0;
        $mission->deuterium = $cargaison?->deuterium->get() ?? 0;

        foreach ($unites as $type => $nombre) {
            $mission->{$type} = $nombre;
        }

        $mission->save();

        return $mission;
    }
}
