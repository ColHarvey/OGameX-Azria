<?php

namespace OGame\Services;

use Exception;
use OGame\Combat\Services\FleetDispositionRegistry;
use OGame\Combat\Services\FleetMovementGate;
use OGame\Models\CombatParticipant;
use OGame\Models\FleetMission;
use OGame\Models\FleetUnion;

/**
 * Class FleetUnionService.
 *
 * Handles fleet union creation and management for ACS Attack missions.
 *
 * @package OGame\Services
 */
class FleetUnionService
{
    /**
     * Maximum delay percentage (30% of remaining time).
     */
    private const MAX_DELAY_PERCENTAGE = 0.30;

    /**
     * FleetUnionService constructor.
     */
    public function __construct(
        private readonly BuddyService $buddyService,
        private readonly AllianceService $allianceService,
    ) {
    }

    /**
     * Create a new fleet union from an existing attack mission.
     *
     * @param FleetMission $mission The initial attack mission to convert to a union
     * @param string|null $name Optional name for the union
     * @return FleetUnion
     * @throws Exception
     */
    public function createUnion(FleetMission $mission, string|null $name = null): FleetUnion
    {
        // Une union sans sa mission fondatrice n'est pas une union : elle apparaitrait dans la
        // liste des unions a rejoindre, vide, et sans moyen d'y entrer. Les deux ecritures ne
        // font donc qu'une.
        //
        // **Dans l'ordre global, comme tout ce qui touche a ce a quoi une flotte est liee.** La
        // porte prend la barriere du corps vise avant d'ecrire le lien d'union : une porte
        // concurrente — rappel, arrivee — ne peut plus tenir l'ancien lien pendant que celui-ci
        // s'ecrit. L'objet de l'appelant est ensuite aligne sur la ligne ecrite.
        //
        // **Les validations vivent dans la fermeture tenue, sur la mission relue.** Faites sur le
        // modele recu, avant la porte, elles ne prouvaient rien : un rappel ou une arrivee pouvait
        // gagner entre elles et l'ecriture, et l'union se creait quand meme pour une flotte partie
        // ou engagee. L'interface peut prevalider ; la preuve metier est ici, et nulle part avant.
        return resolve(FleetMovementGate::class)->decideUnderLock($mission, function (FleetMission $tenue) use ($mission, $name): FleetUnion {
            // Validate mission type (must be attack - type 1)
            if ($tenue->mission_type !== 1) {
                throw new Exception(__('t_acs.error_invalid_mission_type'));
            }

            // Validate mission is still in flight
            if ($tenue->processed || $tenue->canceled) {
                throw new Exception(__('t_acs.error_mission_not_active'));
            }

            // Validate mission is not already in a union
            if ($tenue->isInUnion()) {
                throw new Exception(__('t_acs.error_already_in_union'));
            }

            $this->refuseIfTheCombatHoldsIt($tenue);

            // **Depuis la ligne tenue, pas depuis le modele recu** : c'est elle qui dit ou la flotte
            // va et quand elle arrive.
            $union = FleetUnion::create([
                'user_id' => $tenue->user_id,
                'name' => $name,
                'galaxy_to' => $tenue->galaxy_to,
                'system_to' => $tenue->system_to,
                'position_to' => $tenue->position_to,
                'planet_type_to' => $tenue->type_to,
                'time_arrival' => $tenue->time_arrival,
                'max_fleets' => 16,
                'max_players' => 5,
            ]);

            // Link the mission to the union and convert to ACS Attack
            $tenue->union_id = $union->id;
            $tenue->union_slot = 1; // Initiator always gets slot 1
            $tenue->mission_type = 2; // Convert to ACS Attack
            $tenue->save();

            $this->alignOn($mission, $tenue);

            return $union;
        });
    }

    /**
     * Une flotte que le combat tient n'entre dans aucune union, et n'en fonde aucune.
     *
     * Trois liens le disent : le combat que l'arrivee lui a rattache, l'inscription que la
     * fermeture a ecrite, la disposition qui lui impose un mouvement. Chacun est lu sur la mission
     * **tenue** ; sur le modele recu, il decrirait un passe. La jointure ou la creation modifierait
     * sinon une flotte dont le sort est deja decide ailleurs — et decalerait l'heure d'arrivee de
     * toute une union pour une flotte qui ne viendra pas.
     *
     * Le message est celui d'une mission qui n'est plus disponible : pour le joueur, c'est
     * exactement cela.
     *
     * @throws Exception
     */
    private function refuseIfTheCombatHoldsIt(FleetMission $tenue): void
    {
        if ($tenue->combat_instance_id !== null) {
            throw new Exception(__('t_acs.error_mission_not_active'));
        }

        if (CombatParticipant::query()->where('fleet_mission_id', $tenue->id)->exists()) {
            throw new Exception(__('t_acs.error_mission_not_active'));
        }

        if (resolve(FleetDispositionRegistry::class)->pendingFor($tenue) !== null) {
            throw new Exception(__('t_acs.error_mission_not_active'));
        }
    }

    /**
     * Aligne l'objet de l'appelant sur la ligne ecrite sous verrou.
     *
     * La porte decide et ecrit sur une mission relue ; l'objet que l'appelant tenait decrirait
     * sinon un passe — et un `save()` ulterieur de sa part rejouerait des attributs perimes.
     */
    private function alignOn(FleetMission $appelant, FleetMission $tenue): void
    {
        $appelant->setRawAttributes($tenue->getAttributes(), true);
    }

    /**
     * Join an existing union with a fleet mission.
     *
     * @param FleetUnion $union The union to join
     * @param FleetMission $mission The fleet mission joining the union
     * @return void
     * @throws Exception
     */
    public function joinUnion(FleetUnion $union, FleetMission $mission): void
    {
        // ## Pourquoi une transaction et un verrou de ligne
        //
        // Trois ecritures se suivent : l'heure d'arrivee de l'union, celle de tous ses membres, et
        // le rattachement de la nouvelle mission. Une panne entre les deux premieres et la
        // troisieme laissait l'union decalee **et le rejoignant dehors** : tous les membres
        // arrivaient plus tard pour rien.
        //
        // Les budgets et le numero de creneau, eux, sont lus puis ecrits. Sans verrou de ligne,
        // deux jointures simultanees a quinze flottes passent toutes les deux, et obtiennent le
        // meme creneau. Le verrou est pris **avant** la premiere lecture qui decide.
        //
        // `lockForUpdate()` ne compile rien sur SQLite : la grammaire de Laravel n'y emet pas de
        // `FOR UPDATE`. Les essais gardent donc exactement le meme comportement, et MariaDB obtient
        // un vrai verrou.
        //
        // **L'union se prend a sa place dans l'ordre global, pas en premier.** La jointure la
        // verrouillait avant tout le reste ; la porte des mouvements prend barriere, instances,
        // unions puis mission — et une jointure qui commencerait par l'union pourrait attendre une
        // porte qui tient deja la barriere et attend cette union. L'union visee est donnee a la
        // porte pour qu'elle entre dans la famille des unions, a son rang.
        resolve(FleetMovementGate::class)->decideUnderLock(
            $mission,
            function (FleetMission $tenue) use ($union, $mission): void {
                if (FleetUnion::query()->whereKey($union->getKey())->doesntExist()) {
                    throw new Exception(__('t_acs.error_not_found'));
                }

                // L'instance de l'appelant porte desormais l'etat verrouille : les compteurs lus
                // ci-dessous sont ceux de la ligne que nous tenons, pas ceux d'une lecture anterieure.
                $union->refresh();

                $this->joinUnderLock($union, $tenue);
                $this->alignOn($mission, $tenue);
            },
            [(int)$union->getKey()]
        );
    }

    /**
     * Le corps de la jointure, sous le verrou de la ligne d'union.
     *
     * ## Les validations que cette methode avait perdues
     *
     * `createUnion()` en fait trois que celle-ci ne faisait pas : le genre de mission, l'etat de la
     * mission, et l'appartenance a une union. Leur absence n'etait pas une decision — un transport
     * pouvait devenir une attaque groupee, une mission deja traitee pouvait rejoindre, et une
     * mission deja dans une autre union changeait d'union en laissant un trou dans les creneaux de
     * la premiere.
     *
     * @param FleetUnion $union
     * @param FleetMission $mission
     * @return void
     * @throws Exception
     */
    private function joinUnderLock(FleetUnion $union, FleetMission $mission): void
    {
        // Seule une attaque rejoint une union : simple (type 1), qu'on convertit, ou deja etiquetee
        // attaque groupee (type 2) par l'envoi qui vient de la creer.
        if ($mission->mission_type !== 1 && $mission->mission_type !== 2) {
            throw new Exception(__('t_acs.error_invalid_mission_type'));
        }

        // Une mission arrivee ou annulee n'a plus rien a rejoindre.
        if ($mission->processed || $mission->canceled) {
            throw new Exception(__('t_acs.error_mission_not_active'));
        }

        // **Un retour ne rejoint pas une union.** `startReturn()` recopie le `mission_type` du
        // parent : un retour d'attaque se presente donc comme une attaque, et franchissait tous les
        // controles ci-dessus. Une flotte qui rentre aurait consomme un creneau sur seize — et
        // puisque la jointure aligne l'union sur l'arrivee la plus tardive, elle aurait retarde
        // toute l'attaque groupee.
        //
        // Le lien vers la mission prolongee est le seul fait qui distingue les deux.
        if ($mission->parent_id !== null) {
            throw new Exception(__('t_acs.error_returning_fleet'));
        }

        // Deja dans une union : la deplacer laisserait un creneau vide dans la premiere.
        if ($mission->isInUnion()) {
            throw new Exception(__('t_acs.error_already_in_union'));
        }

        // Tenue par un combat — rattachee, inscrite ou deja renvoyee — elle ne rejoint rien.
        $this->refuseIfTheCombatHoldsIt($mission);

        // Validate union hasn't reached max fleets
        if ($union->hasReachedMaxFleets()) {
            throw new Exception(__('t_acs.error_max_fleets_reached'));
        }

        // Validate union hasn't reached max players (if this is a new player)
        $isNewPlayer = !$union->activeFleetMissions()
            ->where('user_id', $mission->user_id)
            ->exists();

        if ($isNewPlayer && $union->hasReachedMaxPlayers()) {
            throw new Exception(__('t_acs.error_max_players_reached'));
        }

        // Validate player is ally or buddy of union creator
        $creatorUserId = $union->user_id;
        $joiningUserId = $mission->user_id;

        if (!$this->isAllyOrBuddy($creatorUserId, $joiningUserId)) {
            throw new Exception(__('t_acs.error_not_buddy_or_ally'));
        }

        // Validate fleet targets the same location as the union
        if ($mission->galaxy_to !== $union->galaxy_to
            || $mission->system_to !== $union->system_to
            || $mission->position_to !== $union->position_to
            || $mission->type_to !== $union->planet_type_to) {
            throw new Exception(__('t_ingame.fleet.err_union_target_mismatch'));
        }

        // Validate fleet can arrive within delay limit
        $maxArrival = $union->time_arrival + $this->getMaxDelayTime($union);
        if ($mission->time_arrival > $maxArrival) {
            throw new Exception(__('t_acs.error_exceeds_delay_limit'));
        }

        // Get next available slot
        $nextSlot = $union->activeFleetMissions()->max('union_slot') + 1;

        // Link mission to union
        $mission->union_id = $union->id;
        $mission->union_slot = $nextSlot;
        $mission->mission_type = 2; // ACS Attack

        // Adjust arrival time to match union (if fleet arrives earlier)
        if ($mission->time_arrival < $union->time_arrival) {
            $mission->time_arrival = $union->time_arrival;
        } else {
            // Fleet arrives later - update union arrival time (within delay limit)
            $union->time_arrival = $mission->time_arrival;
            $union->save();

            // Also sync all existing union members to the new (later) arrival time.
            // The mission itself has not been saved yet (union_id not in DB), so
            // activeFleetMissions() only returns already-joined missions.
            $union->activeFleetMissions()->update(['time_arrival' => $mission->time_arrival]);
        }

        $mission->save();
    }

    /**
     * Get the maximum delay time allowed for joining fleets.
     * This is 30% of the remaining flight time.
     *
     * @param FleetUnion $union
     * @return int Delay time in seconds
     */
    public function getMaxDelayTime(FleetUnion $union): int
    {
        $remainingTime = $union->getRemainingTime();
        return (int) floor($remainingTime * self::MAX_DELAY_PERCENTAGE);
    }

    /**
     * Handle a fleet being recalled from a union.
     *
     * @param FleetMission $mission The mission being recalled
     * @return void
     */
    public function handleFleetRecall(FleetMission $mission): void
    {
        // Le retrait, le compactage des creneaux et le transfert de propriete ne font qu'une
        // ecriture : a moitie appliques, ils laisseraient des numeros non consecutifs que rien ne
        // rattrape ensuite, et une union dont le proprietaire n'est plus celui du creneau 1.
        //
        // Et dans l'ordre global : le rappel d'une flotte engagee passe deja par la porte des
        // mouvements ; celle-ci s'y imbrique, et tout autre appelant y entre par la meme voie.
        // **« Est-elle dans une union ? » se lit sur la ligne tenue** : l'appelant vivant tient deja
        // la mission relue, mais la methode est publique, et un appelant futur ne doit pas pouvoir
        // en faire un contournement.
        resolve(FleetMovementGate::class)->decideUnderLock($mission, function (FleetMission $tenue) use ($mission): void {
            if (!$tenue->isInUnion()) {
                $this->alignOn($mission, $tenue);

                return;
            }

            $this->recallUnderLock($tenue);
            $this->alignOn($mission, $tenue);
        });
    }

    /**
     * Le corps du rappel, dans la transaction.
     *
     * @param FleetMission $mission
     * @return void
     */
    private function recallUnderLock(FleetMission $mission): void
    {
        /** @var FleetUnion $union */
        $union = $mission->union;

        // Remove from union and revert to regular attack
        $mission->union_id = null;
        $mission->union_slot = null;
        $mission->mission_type = 1;
        $mission->save();

        // Check if union is now empty
        if ($union->activeFleetMissions()->count() === 0) {
            // Delete the empty union
            $union->delete();
            return;
        }

        // Compact remaining slots: renumber by current slot order starting from 1
        $remainingMissions = $union->activeFleetMissions()
            ->orderBy('union_slot')
            ->get();

        $slot = 1;
        foreach ($remainingMissions as $remainingMission) {
            if ($remainingMission->union_slot !== $slot) {
                $remainingMission->union_slot = $slot;
                $remainingMission->save();
            }
            $slot++;
        }

        // Update union ownership to the new slot 1 fleet's owner
        $newInitiator = $union->activeFleetMissions()->where('union_slot', 1)->first();
        if ($newInitiator !== null && $union->user_id !== $newInitiator->user_id) {
            $union->user_id = $newInitiator->user_id;
            $union->save();
        }
    }

    /**
     * Check if a player can join a union (ally/buddy of creator).
     * Used for UI filtering to show available unions.
     *
     * @param FleetUnion $union
     * @param int $playerId
     * @return bool
     */
    public function canPlayerJoinUnion(FleetUnion $union, int $playerId): bool
    {
        return $this->isAllyOrBuddy($union->user_id, $playerId);
    }

    /**
     * Check if two players are allies or buddies.
     *
     * @param int $userId1
     * @param int $userId2
     * @return bool
     */
    private function isAllyOrBuddy(int $userId1, int $userId2): bool
    {
        // Same player is always allowed
        if ($userId1 === $userId2) {
            return true;
        }

        // Check if buddies
        if ($this->buddyService->areBuddies($userId1, $userId2)) {
            return true;
        }

        // Check if in same alliance
        if ($this->allianceService->arePlayersInSameAlliance($userId1, $userId2)) {
            return true;
        }

        return false;
    }
}
