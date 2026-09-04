<?php

namespace OGame\Combat\Admission;

use InvalidArgumentException;
use OGame\Combat\Enums\ActorKind;
use OGame\Combat\Enums\CombatReasonCode;

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
     * **Il ne recoit que des renforts.** `DefensiveRallyCandidate` ne se construit pas pour une
     * autre forme ; un retour, un deploiement personnel ou la garnison locale n'arrivent donc jamais
     * ici, et ce selecteur n'a aucune raison anodine a rendre pour eux.
     *
     * @param int $targetOwnerUserId Le proprietaire de la cible : il occupe d'office un des cinq.
     * @param int $targetBodyId Le corps **exact** defendu.
     * @param int $openedAt L'instant d'ouverture, en secondes.
     * @param array<int, DefensiveRallyCandidate> $candidates Les renforts candidats.
     * @param AdmissionCeiling $ceiling La limite au-dela de laquelle un renfort arrive trop tard.
     * @return AdmissionVerdict
     */
    public function select(
        int $targetOwnerUserId,
        int $targetBodyId,
        int $openedAt,
        array $candidates,
        AdmissionCeiling $ceiling,
    ): AdmissionVerdict {
        if ($targetOwnerUserId < 1) {
            throw new InvalidArgumentException(
                'Un corps defendu a un proprietaire persiste : c est lui qui occupe le premier des cinq '
                . 'emplacements de joueur.'
            );
        }

        // **La limite vient de l'appelant** : plafond de la fenetre au calcul, echeance persistee
        // au verdict. Les confondre laissait entrer une candidate qui n'arrivait qu'apres la
        // photographie, des qu'un rappel liberait sa place entre les deux passages.
        $plafondTemporel = $ceiling->instant;

        // **Le proprietaire occupe d'office un emplacement de joueur**, et aucun emplacement de
        // flotte : sa garnison est deja sur place, elle n'arrive pas.
        $joueursPresents = [$targetOwnerUserId => true];
        $flottesRestantes = self::MAX_EXTERNAL_DEFENCE_FLEETS;

        $admissions = [];

        foreach ($this->inDeterministicOrder($candidates) as $renfort) {
            $candidate = $renfort->mission;
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
     * Pourquoi ce renfort ne peut pas defendre, ou `null` s'il le peut.
     *
     * Le genre et le sens de vol ne sont plus controles ici : le type les a deja garantis. Ne
     * subsistent que les refus qui **se racontent au joueur** — mauvaise cible, camp non
     * renforcable, flotte pas encore partie, rappel, fenetre depassee.
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
        if ($candidate->scheduledArrivalAt >= $ceiling) {
            return CombatReasonCode::RallyWindowLimit;
        }

        // **Arrivee avant l'ouverture : presente, ou partie.** Une Defense ACS qui stationne encore a
        // l'ouverture est une force presente — elle compose la photographie comme la garnison. Une
        // arrivee anterieure sans stationnement en cours n'a rien a faire ici.
        if ($candidate->scheduledArrivalAt < $openedAt && !$candidate->isStillHoldingAt($openedAt)) {
            return CombatReasonCode::RallyWindowLimit;
        }

        return null;
    }

    /**
     * Les renforts tries par arrivee planifiee, puis par identifiant de mission.
     *
     * @param array<int, DefensiveRallyCandidate> $candidates
     * @return array<int, DefensiveRallyCandidate>
     */
    private function inDeterministicOrder(array $candidates): array
    {
        $ordonnees = array_values($candidates);

        usort(
            $ordonnees,
            static fn (DefensiveRallyCandidate $a, DefensiveRallyCandidate $b): int
                => [$a->mission->scheduledArrivalAt, $a->mission->missionId]
                <=> [$b->mission->scheduledArrivalAt, $b->mission->missionId]
        );

        return $ordonnees;
    }
}
