<?php

namespace OGame\Combat\Services;

use Illuminate\Support\Collection;
use OGame\Combat\Enums\CombatMissionKind;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Exceptions\IncoherentCombatEnrolment;
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
     * L'effectif engage d'un combat, confronte aux deux liens qui le decrivent.
     *
     * ## Pourquoi deux liens, et ce que chacun ne suffit pas a dire
     *
     * L'inscription (`combat_participants`) fixe le camp dans la photographie ; la colonne
     * `combat_instance_id` de la mission dit qui est **retenu** sur le corps, et c'est elle que la
     * porte des mouvements relit pour refuser un rappel. Une sortie d'exploitation doit rendre les
     * deux ensembles a la fois : rendre ce que l'un nomme laisserait ce que l'autre nomme pose sur
     * un corps qui vient d'etre libere.
     *
     * La cle etrangere de l'inscription garantit qu'un lien pointe vers une mission reelle. Elle ne
     * garantit pas qu'il en reste un : une mission effacee laisse son inscription avec un lien vide,
     * et la lecture qui ecarte les liens vides rendait alors un effectif plus court d'une flotte,
     * sans que rien ne le dise. Verifier la seule presence de l'initiatrice ne voyait pas cela.
     *
     * ## Les deux inclusions exigees, et pourquoi ce n'est pas une egalite
     *
     * **Tout ce qui est retenu est inscrit.** Une mission liee au combat mais absente de l'effectif
     * ne serait rendue par personne.
     *
     * **Toute inscrite existe, et n'appartient a aucun autre combat.** Sa mission peut en revanche
     * ne pas encore porter le lien : la fermeture inscrit une vague dont l'arrivee precede la
     * cloture meme si son travailleur n'est pas encore passe, et c'est ce passage qui pose la
     * colonne. Exiger l'egalite refuserait cette annulation-la pour un retard normal.
     *
     * ## Le ralliement n'a pas encore de photographie
     *
     * Avant la fermeture, personne n'est inscrit : le lien porte seul l'effectif, et le camp se lit
     * du genre de la mission. Un combat en ralliement qui porterait deja des inscriptions, ou un
     * combat ferme qui n'en porterait aucune, est une contradiction et arrete tout.
     *
     * ## Ce que cette lecture ne couvre pas
     *
     * Une ligne apparue **apres** cette lecture — le fantome — n'y figure pas. Le refermer demande
     * un verrou de portee et une epreuve MariaDB ; ici, l'appelant tient deja les verrous de l'ordre
     * global, et cette lecture ne pretend pas les remplacer.
     *
     * @return array{0: array<int, int>, 1: array<int, int>} Les attaquantes, puis les defensives.
     */
    public function enrolmentOf(CombatInstance $combat): array
    {
        $inscriptions = $combat->participants()->get(['side', 'fleet_mission_id']);

        $orphelines = $inscriptions->filter(static fn (CombatParticipant $ligne): bool => $ligne->fleet_mission_id === null)->count();

        if ($orphelines > 0) {
            throw IncoherentCombatEnrolment::becauseAnEnrolmentLostItsFleet((int)$combat->id, $orphelines);
        }

        $retenues = FleetMission::query()
            ->where('combat_instance_id', $combat->id)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int)$id)
            ->all();

        if ($combat->status === CombatState::Rallying) {
            if ($inscriptions->isNotEmpty()) {
                throw IncoherentCombatEnrolment::becauseARallyingCombatAlreadyHasARoster((int)$combat->id, $inscriptions->count());
            }

            return $this->sidesOfTheHeldFleets($retenues);
        }

        if ($inscriptions->isEmpty()) {
            throw IncoherentCombatEnrolment::becauseAClosedCombatHasNoRoster((int)$combat->id);
        }

        $camps = [CombatParticipant::SIDE_ATTACKER => [], CombatParticipant::SIDE_DEFENDER => []];
        $vues = [];

        foreach ($inscriptions as $ligne) {
            $identifiant = (int)$ligne->fleet_mission_id;

            if (isset($vues[$identifiant])) {
                throw IncoherentCombatEnrolment::becauseAFleetIsEnrolledTwice((int)$combat->id, $identifiant);
            }

            $vues[$identifiant] = true;
            $camps[$ligne->side][] = $identifiant;
        }

        // **Tout ce que le combat retient figure dans son effectif.** Une mission liee sans
        // inscription resterait posee sur un corps que l'annulation vient de liberer.
        $sansInscription = array_values(array_diff($retenues, array_keys($vues)));

        if ($sansInscription !== []) {
            throw IncoherentCombatEnrolment::becauseFleetsAreHeldWithoutBeingEnrolled((int)$combat->id, $sansInscription);
        }

        // **Aucune inscrite n'appartient a un autre combat.** Le lien vide reste permis : c'est le
        // travailleur en retard, pas une contradiction.
        $liens = FleetMission::query()
            ->whereIn('id', array_keys($vues))
            ->whereNotNull('combat_instance_id')
            ->where('combat_instance_id', '!=', $combat->id)
            ->get(['id', 'combat_instance_id']);

        foreach ($liens as $etrangere) {
            throw IncoherentCombatEnrolment::becauseAnEnrolledFleetBelongsToAnotherCombat(
                (int)$combat->id,
                (int)$etrangere->id,
                (int)$etrangere->combat_instance_id
            );
        }

        sort($camps[CombatParticipant::SIDE_ATTACKER]);
        sort($camps[CombatParticipant::SIDE_DEFENDER]);

        return [$camps[CombatParticipant::SIDE_ATTACKER], $camps[CombatParticipant::SIDE_DEFENDER]];
    }

    /**
     * Les camps des flottes retenues pendant le ralliement, lus du genre de leur mission.
     *
     * Ce n'est pas un second moteur de decision : la fermeture range ses candidates par le meme
     * genre, et l'enumeration l'impose de facon exhaustive.
     *
     * @param array<int, int> $retenues
     * @return array{0: array<int, int>, 1: array<int, int>}
     */
    private function sidesOfTheHeldFleets(array $retenues): array
    {
        $attaquantes = [];
        $defensives = [];

        $missions = FleetMission::query()
            ->whereIn('id', $retenues === [] ? [0] : $retenues)
            ->orderBy('id')
            ->get(['id', 'mission_type']);

        foreach ($missions as $mission) {
            if (CombatMissionKind::fromMissionType((int)$mission->mission_type)->reinforcesTheDefence()) {
                $defensives[] = (int)$mission->id;

                continue;
            }

            $attaquantes[] = (int)$mission->id;
        }

        return [$attaquantes, $defensives];
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
