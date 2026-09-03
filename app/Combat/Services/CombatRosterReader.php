<?php

namespace OGame\Combat\Services;

use Illuminate\Support\Collection;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\BattleEngine\Models\AttackerFleet;
use OGame\GameMissions\BattleEngine\Models\DefenderFleet;
use OGame\Models\CombatInstance;
use OGame\Models\CombatParticipant;
use OGame\Models\FleetMission;
use OGame\Services\FleetMissionService;
use RuntimeException;

/**
 * L'effectif d'un combat, relu depuis ses participants.
 *
 * ## Une seule source, deux moments
 *
 * La cloture inscrit les participants, puis calcule la bataille ; l'echeance applique le resultat.
 * Si chacun assemblait ses flottes a sa facon, un ecart entre les deux passerait inapercu jusqu'a
 * ce qu'un retour manque a un joueur. Les deux lisent donc **la meme table**, par ce lecteur.
 *
 * ## Ce que le lecteur ne fait pas
 *
 * Il ne verrouille rien. L'appelant tient l'ordre global des verrous et l'a deja pris quand il
 * appelle : le lecteur relit des lignes que la transaction tient deja.
 *
 * ## La garnison n'est pas un participant
 *
 * Les defenses et la flotte stationnaire du corps attaque n'ont pas de mission, donc pas de ligne
 * dans `combat_participants` : elles sont toujours la, par definition, et se lisent sur le corps.
 * Les participants defensifs sont les renforts venus en ACS Defendre.
 */
final class CombatRosterReader
{
    public function __construct(
        private FleetMissionService|null $fleetMissions = null,
        private PlayerServiceFactory|null $players = null,
        private PlanetServiceFactory|null $planets = null,
    ) {
    }

    /**
     * L'effectif, ou un refus qui nomme ce qui manque.
     */
    public function forCombat(CombatInstance $combat): CombatRoster
    {
        $cible = $this->planets()->make((int)$combat->target_planet_id, true);

        if ($cible === null) {
            throw new RuntimeException('Le combat ' . $combat->id . ' vise un corps ' . $combat->target_planet_id . ' qui n existe plus.');
        }

        $proprietaireCible = $cible->getPlayer();

        if ($proprietaireCible === null) {
            throw new RuntimeException('Le corps ' . $combat->target_planet_id . ' attaque par le combat ' . $combat->id . ' n a pas de proprietaire.');
        }

        $attaquantes = $this->missionIdsOf($combat, CombatParticipant::SIDE_ATTACKER);
        $defensives = $this->missionIdsOf($combat, CombatParticipant::SIDE_DEFENDER);

        if (!in_array($combat->mission_id, $attaquantes, true)) {
            throw new RuntimeException('La mission initiatrice ' . $combat->mission_id . ' n est pas inscrite parmi les attaquants du combat ' . $combat->id . '.');
        }

        $missions = FleetMission::query()
            ->whereIn('id', array_merge($attaquantes, $defensives))
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        $flottes = [];
        $origines = [];

        foreach ($this->initiatorFirst($attaquantes, $combat->mission_id) as $id) {
            $mission = $this->missionOf($missions, $id, $combat);

            $flottes[] = AttackerFleet::fromFleetMission(
                $mission,
                $this->fleetMissions(),
                $this->players(),
                $id === $combat->mission_id
            );

            // Le corps d'ou cette flotte est partie : son chantier spatial fixera la taille du
            // champ d'epaves si son proprietaire est General. Charge une fois par corps.
            $origine = $mission->planet_id_from;

            if ($origine !== null && !isset($origines[$origine])) {
                $corps = $this->planets()->make((int)$origine, true);

                if ($corps !== null) {
                    $origines[$origine] = $corps;
                }
            }
        }

        // La garnison d'abord : elle est le camp defenseur meme quand personne n'est venu en renfort.
        $defenseurs = [DefenderFleet::fromPlanet($cible)];
        foreach ($defensives as $id) {
            $defenseurs[] = DefenderFleet::fromFleetMission(
                $this->missionOf($missions, $id, $combat),
                $this->fleetMissions(),
                $this->players()
            );
        }

        $initiatrice = $this->missionOf($missions, $combat->mission_id, $combat);

        if ($initiatrice->planet_id_from === null) {
            throw new RuntimeException('La mission initiatrice ' . $initiatrice->id . ' n a pas de planete d origine.');
        }

        return new CombatRoster(
            $flottes,
            $defenseurs,
            $cible,
            $proprietaireCible,
            $this->players()->make($initiatrice->user_id, true),
            $initiatrice,
            array_values($origines)
        );
    }

    /**
     * Les missions inscrites d'un camp, par identifiant croissant.
     *
     * L'ordre est celui des verrous : un appelant qui verrouille dans cet ordre et lit dans le
     * meme ne peut pas se croiser avec lui-meme.
     *
     * @return array<int, int>
     */
    public function missionIdsOf(CombatInstance $combat, string $side): array
    {
        $identifiants = $combat->participants()
            ->where('side', $side)
            ->whereNotNull('fleet_mission_id')
            ->pluck('fleet_mission_id')
            ->map(static fn (mixed $id): int => (int)$id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $identifiants;
    }

    /**
     * @param array<int, int> $ids
     * @return array<int, int>
     */
    private function initiatorFirst(array $ids, int $initiator): array
    {
        $autres = array_values(array_filter($ids, static fn (int $id): bool => $id !== $initiator));

        return [$initiator, ...$autres];
    }

    /**
     * @param Collection<int, FleetMission> $missions
     */
    private function missionOf(Collection $missions, int $id, CombatInstance $combat): FleetMission
    {
        $mission = $missions->get($id);

        if (!$mission instanceof FleetMission) {
            throw new RuntimeException('La mission ' . $id . ' inscrite au combat ' . $combat->id . ' n existe plus.');
        }

        return $mission;
    }

    private function fleetMissions(): FleetMissionService
    {
        return $this->fleetMissions ??= resolve(FleetMissionService::class);
    }

    private function players(): PlayerServiceFactory
    {
        return $this->players ??= resolve(PlayerServiceFactory::class);
    }

    private function planets(): PlanetServiceFactory
    {
        return $this->planets ??= resolve(PlanetServiceFactory::class);
    }
}
