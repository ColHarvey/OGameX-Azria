<?php

namespace OGame\Combat\Admission;

use InvalidArgumentException;
use OGame\Combat\Enums\ActorKind;
use OGame\Combat\Enums\CombatMissionKind;
use OGame\Combat\Enums\CombatReasonCode;
use OGame\Combat\Enums\FlightLeg;

/**
 * Qui rejoint le camp defenseur, avec ses propres budgets.
 *
 * ## Il n'existe aucune union defensive, et il n'en faut pas
 *
 * La caracterisation l'a montre : `AcsDefendMission` ne touche jamais `union_id`, et
 * `FleetUnion` est purement offensive. Fabriquer une fausse union defensive ajouterait une
 * synchronisation de vitesse que la Defense ACS n'a pas et n'a jamais eue. Une Defense ACS reste une
 * **mission independante**.
 *
 * Consequence : ce budget-ci n'a aucun comportement existant a preserver. Il est cree, et c'est une
 * decision de jeu.
 *
 * ## Les regles, arretees
 *
 * - **5 joueurs defenseurs distincts au maximum, le proprietaire de la cible compris.** Il reste
 *   donc au plus **quatre** joueurs exterieurs ;
 * - **16 missions externes de Defense ACS au maximum** ;
 * - la garnison locale, les defenses et les ressources ne consomment **aucun** emplacement de
 *   flotte : elles sont deja la, elles n'arrivent pas ;
 * - chaque Defense ACS consomme un emplacement ; plusieurs defenses d'un meme joueur consomment
 *   plusieurs flottes et **un seul joueur** ;
 * - retours et deploiements personnels ne consomment rien : ce sont les vaisseaux du proprietaire
 *   qui rentrent chez lui, pas un renfort ;
 * - l'autorisation copain ou alliance de la Defense ACS est **figee au lancement** — les regles
 *   d'OGameX s'appliquent la, en amont, et ce selecteur ne les rejuge pas ;
 * - admise seulement si elle volait deja et arrive avant la fermeture ; **apres la fermeture elle
 *   repart**, elle ne stationne jamais hors photographie.
 */
final class DefensiveAdmissionSelector
{
    /**
     * Le nombre de joueurs defenseurs distincts, **proprietaire de la cible compris**.
     */
    public const int MAX_DEFENDING_PLAYERS = 5;

    /**
     * Le nombre de missions externes de Defense ACS.
     */
    public const int MAX_EXTERNAL_DEFENCE_FLEETS = 16;

    /**
     * Le verdict d'admission du camp defenseur.
     *
     * @param int $targetOwnerUserId Le proprietaire de la cible : il occupe d'office un des cinq.
     * @param int $targetBodyId Le corps **exact** defendu.
     * @param int $openedAt L'instant d'ouverture, en secondes.
     * @param array<int, CandidateMission> $candidates Les Defenses ACS candidates.
     * @return AdmissionVerdict
     */
    public function select(
        int $targetOwnerUserId,
        int $targetBodyId,
        int $openedAt,
        array $candidates,
    ): AdmissionVerdict {
        if ($targetOwnerUserId < 1) {
            throw new InvalidArgumentException(
                'Un corps defendu a un proprietaire persiste : c est lui qui occupe le premier des cinq '
                . 'emplacements de joueur.'
            );
        }

        $plafondTemporel = $openedAt + AttackAdmissionSelector::MAX_WINDOW_SECONDS;

        // **Le proprietaire occupe d'office un emplacement de joueur**, et aucun emplacement de
        // flotte : sa garnison est deja sur place, elle n'arrive pas.
        $joueursPresents = [$targetOwnerUserId => true];
        $flottesRestantes = self::MAX_EXTERNAL_DEFENCE_FLEETS;

        $admissions = [];

        foreach ($this->inDeterministicOrder($candidates) as $candidate) {
            $groupe = AttackCandidateGroup::ofASingleFleet($candidate);

            $refus = $this->whyItCannotDefend($candidate, $targetBodyId, $openedAt, $plafondTemporel);

            if ($refus !== null) {
                $admissions[] = GroupAdmission::refuse($groupe, $refus);

                continue;
            }

            if ($flottesRestantes < 1) {
                $admissions[] = GroupAdmission::refuse($groupe, CombatReasonCode::FleetLimitReached);

                continue;
            }

            $nouveauJoueur = !isset($joueursPresents[$candidate->userId]);

            if ($nouveauJoueur && count($joueursPresents) >= self::MAX_DEFENDING_PLAYERS) {
                $admissions[] = GroupAdmission::refuse($groupe, CombatReasonCode::PlayerLimitReached);

                continue;
            }

            $flottesRestantes--;
            $joueursPresents[$candidate->userId] = true;

            $admissions[] = GroupAdmission::admit($groupe);
        }

        return new AdmissionVerdict($openedAt, new AdmissionBudget(
            self::MAX_EXTERNAL_DEFENCE_FLEETS,
            self::MAX_DEFENDING_PLAYERS
        ), $admissions);
    }

    /**
     * Pourquoi cette candidate ne peut pas defendre, ou `null` si elle le peut.
     *
     * @param CandidateMission $candidate
     * @param int $targetBodyId
     * @param int $openedAt
     * @param int $ceiling
     * @return CombatReasonCode|null
     */
    private function whyItCannotDefend(
        CandidateMission $candidate,
        int $targetBodyId,
        int $openedAt,
        int $ceiling,
    ): CombatReasonCode|null {
        if ($candidate->targetBodyId !== $targetBodyId) {
            return CombatReasonCode::WrongTargetBody;
        }

        // **Seulement une veritable Defense ACS.** Un retour, un deploiement ou une garnison locale
        // ne consomme aucun emplacement : ce sont les vaisseaux du proprietaire, pas un renfort.
        if ($candidate->mission !== CombatMissionKind::AcsDefend || $candidate->leg !== FlightLeg::Outbound) {
            return CombatReasonCode::NoCombatEffect;
        }

        if ($candidate->actor !== ActorKind::Player) {
            return CombatReasonCode::NpcSideNotReinforceable;
        }

        if (!$candidate->inFlightAtOpening) {
            return CombatReasonCode::NotAlreadyInFlight;
        }

        if ($candidate->recalled) {
            return CombatReasonCode::CandidateRecalled;
        }

        // Une egalite avec le plafond compte pour apres. Au-dela, la candidate repart : elle ne
        // stationne jamais hors photographie.
        if ($candidate->scheduledArrivalAt >= $ceiling || $candidate->scheduledArrivalAt < $openedAt) {
            return CombatReasonCode::RallyWindowLimit;
        }

        return null;
    }

    /**
     * Les candidates triees par arrivee planifiee, puis par identifiant de mission.
     *
     * @param array<int, CandidateMission> $candidates
     * @return array<int, CandidateMission>
     */
    private function inDeterministicOrder(array $candidates): array
    {
        $ordonnees = array_values($candidates);

        usort(
            $ordonnees,
            static fn (CandidateMission $a, CandidateMission $b): int
                => [$a->scheduledArrivalAt, $a->missionId] <=> [$b->scheduledArrivalAt, $b->missionId]
        );

        return $ordonnees;
    }
}
