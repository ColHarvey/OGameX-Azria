<?php

namespace OGame\Combat\Admission;

use OGame\Combat\Enums\ActorKind;
use OGame\Combat\Enums\CombatReasonCode;
use OGame\Combat\Enums\FlightLeg;
use OGame\Combat\Exceptions\ContradictoryAdmissionInput;
use OGame\Combat\Support\CombatRallyWindow;

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
 *     1. le corps vise
 *     2. l'etat de la candidate : deja en vol, puis rappelee
 *     3. la cible ou la candidate pilotee par le serveur
 *     4. le groupe fondateur ferme
 *     5. l'alliance figee
 *     6. le plafond temporel
 *     7. les budgets, flottes avant joueurs
 *
 * **Les impossibilites permanentes passent avant les limites circonstancielles.** Le plafond
 * temporel etait teste avant la cible non renforcable et avant l'alliance : un allie econduit
 * lisait « trop tard » alors qu'il n'aurait jamais pu entrer, quel que fut son horaire.
 *
 * ## La raison ne depend pas de l'ordre des lignes
 *
 * Rendre le premier defaut rencontre en parcourant les missions du groupe faisait dependre le
 * message de l'ordre dans lequel la base avait rendu les lignes : deux permutations du meme groupe
 * ACS donnaient deux raisons. Chaque categorie est donc evaluee sur **tout le groupe**, et la
 * premiere categorie presente dans l'ordre ci-dessus l'emporte.
 *
 * ## Un combat contre le serveur ne se rejoint pas
 *
 * La regle vaut **dans les deux sens**, et chacun a son controle :
 *
 *     candidate pilotee par le serveur -> elle ne rejoint pas le camp d'un joueur
 *     cible pilotee par le serveur     -> l'ouvreur y va seul, ses propres vagues comprises
 *
 * Le second n'est pas une precaution de style. Sans lui, un rassemblement de seize flottes
 * tomberait sur une base pirate et le contenu solo du jeu deviendrait une formalite
 * d'alliance. C'est `targetActor` qui porte le fait, fige a l'ouverture comme le reste.
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
     *
     * **Une seule source, et elle est ailleurs.** Le nombre appartient a la regle de la fenetre ;
     * le repeter ici en ferait deux, et deux nombres egaux finissent par ne plus l'etre.
     */
    public const int MAX_WINDOW_SECONDS = CombatRallyWindow::WINDOW_SECONDS;

    /**
     * Le verdict d'admission du camp attaquant.
     *
     * @param FoundingGroup $founding Le groupe qui a ouvert, et l'alliance qui gouverne.
     * @param int $targetBodyId Le corps **exact** attaque.
     * @param ActorKind $targetActor Qui tient la cible, fige a l'ouverture. Hors `Player`, l'ouvreur
     *                               attaque seul, ses propres vagues comprises.
     * @param int $openedAt L'instant d'ouverture, en secondes.
     * @param array<int, AttackCandidateGroup> $candidates Les groupes candidats, dans n'importe quel ordre.
     * @param AdmissionCeiling $ceiling La limite au-dela de laquelle une candidate arrive trop tard :
     *        plafond de la fenetre quand on calcule l'echeance, echeance persistee quand on juge.
     * @return AdmissionVerdict
     */
    public function select(
        FoundingGroup $founding,
        int $targetBodyId,
        ActorKind $targetActor,
        int $openedAt,
        array $candidates,
        AdmissionCeiling $ceiling,
    ): AdmissionVerdict {
        // **La limite vient de l'appelant, et elle n'est pas la meme aux deux passages.** Au calcul
        // de l'echeance c'est le plafond de la fenetre ; au verdict c'est l'echeance persistee.
        $plafondTemporel = $ceiling->instant;

        $flottesRestantes = $founding->budget->maxFleets - $founding->fleetCount();
        $joueursPresents = [];

        foreach ($founding->distinctPlayers() as $joueur) {
            $joueursPresents[$joueur] = true;
        }

        $admissions = [];

        foreach ($this->inDeterministicOrder($candidates) as $groupe) {
            $refus = $this->whyItCannotJoin($groupe, $founding, $targetBodyId, $targetActor, $openedAt, $plafondTemporel);

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
     * La premiere categorie presente l'emporte, dans l'ordre de priorite documente en tete de
     * classe. Chaque categorie est evaluee sur **tout le groupe** : un groupe ACS ne doit pas
     * changer de message selon l'ordre dans lequel ses missions ont ete lues.
     *
     * @param AttackCandidateGroup $group
     * @param FoundingGroup $founding
     * @param int $targetBodyId
     * @param ActorKind $targetActor
     * @param int $openedAt
     * @param int $ceiling
     * @return CombatReasonCode|null
     */
    private function whyItCannotJoin(
        AttackCandidateGroup $group,
        FoundingGroup $founding,
        int $targetBodyId,
        ActorKind $targetActor,
        int $openedAt,
        int $ceiling,
    ): CombatReasonCode|null {
        $this->refuseContradictoryInput($group);

        $categories = [
            // 1. Une planete et sa lune partagent leurs coordonnees : viser l'une n'est pas viser
            //    l'autre.
            [CombatReasonCode::WrongTargetBody, $this->anyMission(
                $group,
                static fn (CandidateMission $m): bool => $m->targetBodyId !== $targetBodyId
            )],

            // 2. Le ralliement rassemble ce qui volait deja ; il n'ouvre pas une fenetre de
            //    lancement. Puis : une candidate rappelee ne rejoint plus rien.
            [CombatReasonCode::NotAlreadyInFlight, $this->anyMission(
                $group,
                static fn (CandidateMission $m): bool => !$m->inFlightAtOpening
            )],
            [CombatReasonCode::CandidateRecalled, $this->anyMission(
                $group,
                static fn (CandidateMission $m): bool => $m->recalled
            )],

            // 3. Un combat pilote par le serveur ne se rassemble pas, dans un sens comme dans
            //    l'autre : ni une candidate pilotee par le serveur, ni un renfort exterieur sur une
            //    cible qui l'est.
            [CombatReasonCode::NpcSideNotReinforceable, $this->anyMission(
                $group,
                static fn (CandidateMission $m): bool => $m->actor !== ActorKind::Player
            ) || ($targetActor !== ActorKind::Player && $this->anyMission(
                $group,
                static fn (CandidateMission $m): bool => $m->userId !== $founding->creatorUserId
            ))],

            // 4. Le createur a rappele sa flotte : les membres deja lances continuent, personne
            //    d'autre n'entre.
            [CombatReasonCode::RallyClosed, !$founding->stillAcceptsNewMembers],

            // 5. L'alliance figee a l'ouverture est le seul titre d'un tiers.
            [CombatReasonCode::AllianceNotEligible, $this->anyMission(
                $group,
                static fn (CandidateMission $m): bool => !$founding->admitsAutomatically($m)
            )],

            // 6. **Une egalite avec le plafond compte pour apres**, comme partout ailleurs.
            [CombatReasonCode::RallyWindowLimit,
                $group->scheduledArrivalAt() >= $ceiling || $group->scheduledArrivalAt() < $openedAt],
        ];

        foreach ($categories as [$raison, $presente]) {
            if ($presente) {
                return $raison;
            }
        }

        return null;
    }

    /**
     * Arrete une entree que la matrice n'aurait jamais du deleguer ici.
     *
     * La matrice ne delegue au selecteur attaquant que les allers `Attack`, `AcsAttack` et
     * `MoonDestruction`. Un retour, ou un genre qui n'ouvre pas de combat, ne peut donc pas arriver
     * sur un chemin sain.
     *
     * **Ce n'est pas un refus a montrer au joueur.** Le rendre sous `NoCombatEffect` masquerait un
     * defaut d'integration derriere un message anodin, et la flotte repartirait avec une raison
     * plausible pendant que la cause resterait invisible.
     *
     * @param AttackCandidateGroup $group
     * @return void
     */
    private function refuseContradictoryInput(AttackCandidateGroup $group): void
    {
        foreach ($group->missions as $mission) {
            if ($mission->leg !== FlightLeg::Outbound || !$mission->mission->opensCombat()) {
                throw new ContradictoryAdmissionInput(
                    'selecteur d admission attaquante',
                    $mission->mission->value . ' / ' . $mission->leg->value,
                    'mission ' . $mission->missionId
                );
            }
        }
    }

    /**
     * Si au moins une mission du groupe presente ce defaut.
     *
     * @param AttackCandidateGroup $group
     * @param callable(CandidateMission): bool $predicate
     * @return bool
     */
    private function anyMission(AttackCandidateGroup $group, callable $predicate): bool
    {
        foreach ($group->missions as $mission) {
            if ($predicate($mission)) {
                return true;
            }
        }

        return false;
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
