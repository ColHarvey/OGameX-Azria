<?php

namespace OGame\Combat\Services;

use Illuminate\Support\Facades\DB;
use OGame\Combat\Admission\AdmissionBudget;
use OGame\Combat\Admission\AdmissionVerdict;
use OGame\Combat\Admission\AttackAdmissionSelector;
use OGame\Combat\Admission\AttackCandidateGroup;
use OGame\Combat\Admission\CandidateMission;
use OGame\Combat\Admission\DefensiveAdmissionSelector;
use OGame\Combat\Admission\DefensiveRallyCandidate;
use OGame\Combat\Admission\FoundingGroup;
use OGame\Combat\Admission\FrozenAllianceMembership;
use OGame\Combat\Admission\RallyGrouping;
use OGame\Combat\Enums\ActorKind;
use OGame\Combat\Enums\CombatMissionKind;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Support\ActorKindResolver;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Models\CelestialBodyCombatBarrier;
use OGame\Models\CombatInstance;
use OGame\Models\CombatParticipant;
use OGame\Models\Planet;
use OGame\Models\User;

/**
 * La fermeture du ralliement : l'instant ou la photographie se prend.
 *
 * ## Ce qui se decide ici, et une seule fois
 *
 * Le ralliement est une **phase d'admission, pas un combat commence**. Rien n'est calcule tant que
 * la fenetre est ouverte ; a sa fermeture, les candidates sont arbitrees, la photographie est prise,
 * et plus rien ne bouge.
 *
 * ## L'ordre des verrous n'est pas negociable
 *
 *     1. barriere du corps celeste
 *     2. instance de combat
 *     3. union, puis missions par identifiant croissant
 *     4. reservation de butin
 *
 * Deux transactions qui les prennent dans un ordre different se bloquent mutuellement. Cet ordre est
 * ecrit dans la migration de la barriere, parce qu'elle en est le premier maillon, et il est suivi
 * ici.
 *
 * ## Rien n'est relu dans le monde courant
 *
 * Les versions de regle, l'alliance qui gouverne, les appartenances, les budgets et l'heure qui fait
 * foi viennent tous de l'ouverture. Deux heures separent les deux moments : un joueur a pu changer
 * d'alliance, un administrateur ajuster une limite, une regle etre versionnee. **Aucun de ces
 * changements ne doit toucher une bataille deja engagee.**
 *
 * Seuls les faits qui *doivent* changer sont relus : un rappel survenu depuis, et l'existence des
 * missions.
 *
 * ## Fermer deux fois ne fait rien de plus
 *
 * Un message de file peut etre livre deux fois, un worker reprendre apres un redemarrage. Le statut
 * tranche sous verrou, et la contrainte d'unicite des participants ferme la porte que le statut
 * laisserait entrouverte si deux transactions arrivaient ensemble.
 */
final class RallyClosureService
{
    public function __construct(
        private RallyCandidateReader $reader = new RallyCandidateReader(),
        private AttackAdmissionSelector $attackSelector = new AttackAdmissionSelector(),
        private DefensiveAdmissionSelector $defenceSelector = new DefensiveAdmissionSelector(),
        private RallyGrouping $grouping = new RallyGrouping(),
    ) {
    }

    /**
     * Ferme le ralliement de ce combat, si l'heure est venue et si personne ne l'a fait avant.
     *
     * @param int $combatInstanceId
     * @param int $now L'instant du traitement.
     * @return RallyClosureOutcome
     */
    public function close(int $combatInstanceId, int $now): RallyClosureOutcome
    {
        return DB::transaction(function () use ($combatInstanceId, $now): RallyClosureOutcome {
            $combat = CombatInstance::query()->whereKey($combatInstanceId)->lockForUpdate()->first();

            if ($combat === null) {
                return RallyClosureOutcome::unknownCombat();
            }

            // **La barriere d'abord.** L'ordre des verrous commence par elle ; le combat ne se
            // verrouille qu'ensuite. L'inverse ferait s'attendre deux fermetures concurrentes.
            $barriere = CelestialBodyCombatBarrier::query()
                ->where('combat_instance_id', $combat->id)
                ->lockForUpdate()
                ->first();

            if ($barriere === null) {
                return RallyClosureOutcome::unknownCombat();
            }

            if ($combat->status !== CombatState::Rallying) {
                return RallyClosureOutcome::alreadyClosed();
            }

            // L'echeance a ete calculee a l'ouverture. Fermer avant elle exclurait des flottes qu'on
            // avait promis d'attendre.
            if ($now < $barriere->owned_through_effect_at) {
                return RallyClosureOutcome::tooEarly();
            }

            return $this->closeUnderLock($combat, $barriere->opened_at);
        });
    }

    /**
     * Prononce les admissions et fige la photographie, verrous en main.
     */
    private function closeUnderLock(CombatInstance $combat, int $openedAt): RallyClosureOutcome
    {
        $corps = $combat->target_planet_id ?? 0;

        // **Tout vient de l'ouverture.** Relire l'alliance ou les budgets dans le monde courant
        // ferait deriver une bataille engagee il y a deux heures.
        $appartenances = FrozenAllianceMembership::fromStorage($combat->frozen_alliance_membership);
        $budget = new AdmissionBudget($combat->max_fleets, $combat->max_players);

        $candidates = $this->reader->read($corps, $openedAt, $appartenances, 0);

        $attaquants = $this->admitAttackers($combat, $corps, $openedAt, $candidates, $appartenances, $budget);
        $defenseurs = $this->admitDefenders($corps, $openedAt, $candidates);

        $this->registerParticipants($combat, $attaquants->admitted(), CombatParticipant::SIDE_ATTACKER);
        $this->registerParticipants($combat, $defenseurs->admitted(), CombatParticipant::SIDE_DEFENDER);

        $combat->status = CombatState::Active;
        $combat->fleets_admitted = $this->fleetsOf($attaquants) + $this->fleetsOf($defenseurs);
        $combat->players_admitted = $this->playersOf($attaquants, $defenseurs);
        $combat->save();

        return RallyClosureOutcome::closed($attaquants, $defenseurs);
    }

    /**
     * Le verdict du camp attaquant.
     *
     * @param array<int, CandidateMission> $candidates
     */
    private function admitAttackers(
        CombatInstance $combat,
        int $targetBodyId,
        int $openedAt,
        array $candidates,
        FrozenAllianceMembership $membership,
        AdmissionBudget $budget,
    ): AdmissionVerdict {
        $combattantes = $this->grouping->fightingShapesOnly($candidates);

        [$fondatrices, $autres] = $this->grouping->splitFounding(
            $combattantes,
            $combat->mission_id,
            $combat->union_id
        );

        if ($fondatrices === []) {
            // L'ouvreur n'est plus la : sa mission a ete rappelee ou traitee. Le combat n'a plus de
            // groupe fondateur, et personne ne rejoint un camp qui n'existe pas.
            return new AdmissionVerdict($openedAt, $budget, []);
        }

        return $this->attackSelector->select(
            new FoundingGroup(
                $combat->founding_creator_id ?? 0,
                $membership->allianceId,
                $fondatrices,
                $budget
            ),
            $targetBodyId,
            $this->actorHolding($targetBodyId),
            $openedAt,
            $this->grouping->intoGroups($autres)
        );
    }

    /**
     * Le verdict du camp defenseur.
     *
     * @param array<int, CandidateMission> $candidates
     */
    private function admitDefenders(
        int $targetBodyId,
        int $openedAt,
        array $candidates,
    ): AdmissionVerdict {
        $proprietaire = $this->ownerOf($targetBodyId);

        if ($proprietaire < 1) {
            // Un corps sans proprietaire persiste n a pas de camp defenseur : le selecteur exige
            // cet identifiant, parce que c est lui qui occupe le premier des cinq emplacements.
            return new AdmissionVerdict($openedAt, AdmissionBudget::canonical(), []);
        }

        // **L aiguillage, pas un refus.** Un retour, un deploiement personnel ou la garnison
        // locale ne sont pas des renforts refuses : ils ne sont pas candidats. Le type le dit,
        // et le selecteur n a donc plus de raison anodine a rendre pour eux.
        $defenses = DefensiveRallyCandidate::ofAll($candidates);

        return $this->defenceSelector->select(
            $proprietaire,
            $targetBodyId,
            $openedAt,
            $defenses
        );
    }

    /**
     * Inscrit les flottes admises comme participants.
     *
     * **L'unicite ferme ce que le statut laisse entrouvert.** Deux fermetures concurrentes
     * franchiraient toutes deux le controle de statut si elles le lisaient au meme instant ; la
     * contrainte sur `(combat_instance_id, participant_key)` refuse alors la seconde inscription.
     *
     * @param array<int, AttackCandidateGroup> $groups
     */
    private function registerParticipants(CombatInstance $combat, array $groups, string $side): void
    {
        foreach ($groups as $groupe) {
            foreach ($groupe->missions as $mission) {
                CombatParticipant::query()->updateOrCreate(
                    [
                        'combat_instance_id' => $combat->id,
                        'participant_key' => CombatParticipantKey::forFleet($mission->missionId),
                    ],
                    [
                        'player_id' => $mission->userId,
                        'fleet_mission_id' => $mission->missionId,
                        'side' => $side,
                        'participant_type' => $this->participantTypeOf($mission),
                    ]
                );
            }
        }
    }

    /**
     * Le genre de participant que cette mission apporte.
     */
    private function participantTypeOf(CandidateMission $mission): string
    {
        return match ($mission->mission) {
            CombatMissionKind::AcsAttack => CombatParticipant::TYPE_ACS_ATTACK,
            CombatMissionKind::AcsDefend => CombatParticipant::TYPE_ACS_DEFEND,
            default => CombatParticipant::TYPE_ATTACK_FLEET,
        };
    }

    /**
     * Combien de flottes ce verdict admet.
     */
    private function fleetsOf(AdmissionVerdict $verdict): int
    {
        $total = 0;

        foreach ($verdict->admitted() as $groupe) {
            $total += $groupe->fleetCount();
        }

        return $total;
    }

    /**
     * Combien de joueurs distincts les deux verdicts admettent.
     */
    private function playersOf(
        AdmissionVerdict $attackers,
        AdmissionVerdict $defenders,
    ): int {
        $joueurs = [];

        foreach ([$attackers, $defenders] as $verdict) {
            foreach ($verdict->admitted() as $groupe) {
                foreach ($groupe->distinctPlayers() as $joueur) {
                    $joueurs[$joueur] = true;
                }
            }
        }

        return count($joueurs);
    }

    /**
     * Le proprietaire du corps vise.
     */
    private function ownerOf(int $targetBodyId): int
    {
        $planete = Planet::find($targetBodyId);

        return $planete === null ? 0 : $planete->user_id;
    }

    /**
     * Le genre d'acteur qui tient le corps vise.
     */
    private function actorHolding(int $targetBodyId): ActorKind
    {
        $proprietaire = User::find($this->ownerOf($targetBodyId));

        return $proprietaire === null ? ActorKind::Player : ActorKindResolver::of($proprietaire);
    }
}
