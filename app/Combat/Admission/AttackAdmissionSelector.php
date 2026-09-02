<?php

namespace OGame\Combat\Admission;

use OGame\Combat\Enums\ActorKind;
use OGame\Combat\Enums\CombatReasonCode;
use OGame\Combat\Enums\FlightLeg;

/**
 * Qui rejoint le camp attaquant, decide **en une fois** pour tout le groupe.
 *
 * ## Pourquoi jamais flotte par flotte
 *
 * Un budget se consomme. Traiter les candidates une par une, chacune dans sa transaction, laisserait
 * deux workers prendre ensemble la derniere place — et la seconde admission ne verrait pas la
 * premiere. La selection est donc **collective, prise une seule fois, puis persistee**.
 *
 * Ce selecteur est pur : il recoit des faits deja relus sous verrou et rend un verdict. C'est
 * `RallyClosureService` qui detient le verrou et ecrit.
 *
 * ## L'ordre des controles n'est pas indifferent
 *
 * Il decide **quelle raison le joueur lira**. Les controles d'identite passent avant les budgets :
 * un joueur etranger a l'alliance doit lire « pas allie », pas « limite atteinte » — la seconde
 * laisserait croire qu'il aurait pu entrer en arrivant plus tot.
 *
 *     1. corps exact, sens de vol, acteur autorise
 *     2. deja en vol a l'ouverture, non rappelee
 *     3. plafond temporel
 *     4. meme joueur ou alliance figee
 *     5. budgets, dans l'ordre deterministe
 *
 * ## Un groupe entier, ou rien
 *
 * Une attaque ACS deja en vol arrive ensemble. La decouper briserait une attaque coordonnee que ses
 * joueurs ont organisee et payee : elle est admise entierement, ou renvoyee entierement.
 */
final class AttackAdmissionSelector
{
    /**
     * La duree maximale d'une fenetre de ralliement, en secondes.
     */
    public const int MAX_WINDOW_SECONDS = 60;

    /**
     * Le verdict d'admission du camp attaquant.
     *
     * @param FoundingGroup $founding Le groupe qui a ouvert, et l'alliance qui gouverne.
     * @param int $targetBodyId Le corps **exact** attaque.
     * @param int $openedAt L'instant d'ouverture, en secondes.
     * @param array<int, AttackCandidateGroup> $candidates Les groupes candidats, dans n'importe quel ordre.
     * @return AdmissionVerdict
     */
    public function select(
        FoundingGroup $founding,
        int $targetBodyId,
        int $openedAt,
        array $candidates,
    ): AdmissionVerdict {
        $plafondTemporel = $openedAt + self::MAX_WINDOW_SECONDS;

        $flottesRestantes = $founding->budget->maxFleets - $founding->fleetCount();
        $joueursPresents = [];

        foreach ($founding->distinctPlayers() as $joueur) {
            $joueursPresents[$joueur] = true;
        }

        $admissions = [];

        foreach ($this->inDeterministicOrder($candidates) as $groupe) {
            $refus = $this->whyItCannotJoin($groupe, $founding, $targetBodyId, $openedAt, $plafondTemporel);

            if ($refus !== null) {
                $admissions[] = GroupAdmission::refuse($groupe, $refus);

                continue;
            }

            // **Le groupe entier doit tenir.** Un groupe qui deborderait d'une seule flotte est
            // renvoye en entier : on ne decoupe jamais une attaque coordonnee.
            if ($groupe->fleetCount() > $flottesRestantes) {
                $admissions[] = GroupAdmission::refuse($groupe, CombatReasonCode::FleetLimitReached);

                continue;
            }

            $nouveauxJoueurs = [];

            foreach ($groupe->distinctPlayers() as $joueur) {
                if (!isset($joueursPresents[$joueur])) {
                    $nouveauxJoueurs[$joueur] = true;
                }
            }

            if (count($joueursPresents) + count($nouveauxJoueurs) > $founding->budget->maxPlayers) {
                $admissions[] = GroupAdmission::refuse($groupe, CombatReasonCode::PlayerLimitReached);

                continue;
            }

            $flottesRestantes -= $groupe->fleetCount();
            $joueursPresents += $nouveauxJoueurs;

            $admissions[] = GroupAdmission::admit($groupe);
        }

        return new AdmissionVerdict($openedAt, $founding->budget, $admissions);
    }

    /**
     * Pourquoi ce groupe ne peut pas rejoindre, ou `null` s'il le peut.
     *
     * Le premier refus rencontre gagne : l'ordre des controles decide la raison que le joueur lira.
     *
     * @param AttackCandidateGroup $group
     * @param FoundingGroup $founding
     * @param int $targetBodyId
     * @param int $openedAt
     * @param int $ceiling
     * @return CombatReasonCode|null
     */
    private function whyItCannotJoin(
        AttackCandidateGroup $group,
        FoundingGroup $founding,
        int $targetBodyId,
        int $openedAt,
        int $ceiling,
    ): CombatReasonCode|null {
        foreach ($group->missions as $mission) {
            // Une planete et sa lune partagent leurs coordonnees : viser l'une n'est pas viser
            // l'autre.
            if ($mission->targetBodyId !== $targetBodyId) {
                return CombatReasonCode::WrongTargetBody;
            }

            // Un pirate ouvre seul et n'est pas renforcable, dans un sens comme dans l'autre.
            if ($mission->actor !== ActorKind::Player) {
                return CombatReasonCode::NpcSideNotReinforceable;
            }

            // Un retour n'a rien a rallier : il rentre chez lui.
            if ($mission->leg !== FlightLeg::Outbound || !$mission->mission->opensCombat()) {
                return CombatReasonCode::NoCombatEffect;
            }

            // Le ralliement rassemble ce qui volait deja ; il n'ouvre pas une fenetre de lancement.
            if (!$mission->inFlightAtOpening) {
                return CombatReasonCode::NotAlreadyInFlight;
            }

            if ($mission->recalled) {
                return CombatReasonCode::CandidateRecalled;
            }
        }

        // **Une egalite avec le plafond compte pour apres**, comme partout ailleurs.
        if ($group->scheduledArrivalAt() >= $ceiling || $group->scheduledArrivalAt() < $openedAt) {
            return CombatReasonCode::RallyWindowLimit;
        }

        // Le createur a rappele sa flotte : les membres deja lances continuent, personne d'autre
        // n'entre.
        if (!$founding->stillAcceptsNewMembers) {
            return CombatReasonCode::RallyClosed;
        }

        foreach ($group->missions as $mission) {
            if (!$founding->admitsAutomatically($mission)) {
                return CombatReasonCode::AllianceNotEligible;
            }
        }

        return null;
    }

    /**
     * Les groupes tries par arrivee planifiee, puis par identite stable.
     *
     * @param array<int, AttackCandidateGroup> $candidates
     * @return array<int, AttackCandidateGroup>
     */
    private function inDeterministicOrder(array $candidates): array
    {
        $ordonnes = array_values($candidates);

        usort(
            $ordonnes,
            static fn (AttackCandidateGroup $a, AttackCandidateGroup $b): int => $a->compareTo($b)
        );

        return $ordonnes;
    }
}
