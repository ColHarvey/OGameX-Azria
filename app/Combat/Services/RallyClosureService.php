<?php

namespace OGame\Combat\Services;

use Illuminate\Database\QueryException;
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
use OGame\Combat\Enums\CombatOutboxKind;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Enums\LootReservationState;
use OGame\Combat\Enums\SnapshotContribution;
use OGame\Combat\Exceptions\ContradictorySnapshotInclusion;
use OGame\Combat\Projection\SnapshotProjectionRegistry;
use OGame\Combat\Support\ActorKindResolver;
use OGame\Combat\Support\CombatEventIdentity;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Combat\Support\SnapshotContributionSet;
use OGame\Models\CelestialBodyCombatBarrier;
use OGame\Models\CombatInstance;
use OGame\Models\CombatLootReservation;
use OGame\Models\CombatOutboxMessage;
use OGame\Models\CombatParticipant;
use OGame\Models\CombatSnapshotInclusion;
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
    /**
     * Le registre des projections connues.
     *
     * Resolu dans le corps plutot que dans la signature : une valeur par defaut de parametre ne peut
     * pas appeler une methode. Le registre reste injectable — un essai en fournit un qui ne connait
     * pas la version d'un combat, pour prouver que le rejeu s'arrete au lieu de deviner.
     */
    private SnapshotProjectionRegistry $projections;

    public function __construct(
        private RallyCandidateReader $reader = new RallyCandidateReader(),
        private AttackAdmissionSelector $attackSelector = new AttackAdmissionSelector(),
        private DefensiveAdmissionSelector $defenceSelector = new DefensiveAdmissionSelector(),
        private RallyGrouping $grouping = new RallyGrouping(),
        SnapshotProjectionRegistry|null $projections = null,
    ) {
        $this->projections = $projections ?? SnapshotProjectionRegistry::default();
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
            // **La barriere d'abord, et par l'identifiant de combat** — pas apres avoir verrouille
            // l'instance pour en tirer la cle.
            //
            // Ce code faisait l'inverse pendant que le commentaire affirmait cet ordre-ci. Le
            // desaccord n'etait pas documentaire : l'ordre global fixe par la migration de barriere
            // est corps -> combat -> union -> missions, et une jointure ou une resolution qui le
            // suivrait aurait attendu la barriere pendant que la fermeture attendait l'instance.
            // Deux transactions, deux verrous, chacune tenant celui que l'autre demande.
            $barriere = CelestialBodyCombatBarrier::query()
                ->where('combat_instance_id', $combatInstanceId)
                ->lockForUpdate()
                ->first();

            if ($barriere === null) {
                return RallyClosureOutcome::unknownCombat();
            }

            $combat = CombatInstance::query()->whereKey($combatInstanceId)->lockForUpdate()->first();

            if ($combat === null) {
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

            return $this->closeUnderLock($combat, $barriere->opened_at, $barriere->owned_through_effect_at);
        });
    }

    /**
     * Prononce les admissions et fige la photographie, verrous en main.
     */
    private function closeUnderLock(CombatInstance $combat, int $openedAt, int $closedAt): RallyClosureOutcome
    {
        $corps = $combat->target_planet_id ?? 0;

        // **Tout vient de l'ouverture.** Relire l'alliance ou les budgets dans le monde courant
        // ferait deriver une bataille engagee il y a deux heures.
        $appartenances = FrozenAllianceMembership::fromStorage($combat->frozen_alliance_membership);
        $budget = new AdmissionBudget($combat->max_fleets, $combat->max_players);

        $candidates = $this->reader->read($corps, $openedAt, $appartenances, 0);

        $combattantes = $this->grouping->fightingShapesOnly($candidates);

        [$fondatrices, $autres] = $this->grouping->splitFounding(
            $combattantes,
            $combat->mission_id,
            $combat->union_id
        );

        // **Le groupe fondateur n'est pas « admis », il ouvre.** Le selecteur ne le juge donc pas et
        // ne le rend pas dans son verdict — c'est juste de sa part, il n'a rien a decider sur lui.
        //
        // Mais la fermeture, elle, doit l'inscrire : sans cela l'attaquant qui a lance la bataille
        // ne serait ni participant, ni dans la photographie, ni compte dans les budgets consommes.
        // Le combat se serait ouvert sans son attaquant.
        $groupesFondateurs = $fondatrices === [] ? [] : $this->grouping->intoGroups($fondatrices);

        $attaquants = $this->admitAttackers($combat, $corps, $openedAt, $fondatrices, $autres, $appartenances, $budget);
        $defenseurs = $this->admitDefenders($corps, $openedAt, $candidates);

        $cotesAttaquants = array_merge($groupesFondateurs, $attaquants->admitted());

        $this->registerParticipants($combat, $cotesAttaquants, CombatParticipant::SIDE_ATTACKER);
        $this->registerParticipants($combat, $defenseurs->admitted(), CombatParticipant::SIDE_DEFENDER);

        $this->recordInclusions($combat, $cotesAttaquants, SnapshotContribution::AttackingFleet);
        $this->recordInclusions($combat, $defenseurs->admitted(), SnapshotContribution::DefendingFleet);

        // **Dans la meme transaction que la photographie.** Une flotte renvoyee dont le message
        // serait perdu ressemblerait a une panne : le joueur a paye le carburant, attendu
        // l arrivee, et rien ne s est passe.
        $this->announceRefusals($combat, $attaquants, $closedAt);
        $this->announceRefusals($combat, $defenseurs, $closedAt);

        $this->sealReservation($combat, $closedAt);

        $combat->status = CombatState::Active;
        $combat->fleets_admitted = $this->countFleets($cotesAttaquants)
            + $this->countFleets($defenseurs->admitted());
        $combat->players_admitted = $this->countPlayers($cotesAttaquants, $defenseurs->admitted());
        $combat->save();

        return RallyClosureOutcome::closed($attaquants, $defenseurs);
    }

    /**
     * Le verdict du camp attaquant.
     *
     * Il ne porte que sur les **candidates** : le groupe fondateur lui est donne comme un fait, pas
     * soumis a son jugement.
     *
     * @param array<int, CandidateMission> $founding
     * @param array<int, CandidateMission> $others
     */
    private function admitAttackers(
        CombatInstance $combat,
        int $targetBodyId,
        int $openedAt,
        array $founding,
        array $others,
        FrozenAllianceMembership $membership,
        AdmissionBudget $budget,
    ): AdmissionVerdict {
        if ($founding === []) {
            // L'ouvreur n'est plus la : sa mission a ete rappelee ou traitee. Le combat n'a plus de
            // groupe fondateur, et personne ne rejoint un camp qui n'existe pas.
            return new AdmissionVerdict($openedAt, $budget, []);
        }

        return $this->attackSelector->select(
            new FoundingGroup(
                $combat->founding_creator_id ?? 0,
                $membership->allianceId,
                $founding,
                $budget
            ),
            $targetBodyId,
            $this->actorHolding($targetBodyId),
            $openedAt,
            $this->grouping->intoGroups($others)
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
     * Ce que chaque flotte admise apporte a la photographie.
     *
     * ## Pourquoi une seconde table, alors que les participants sont deja inscrits
     *
     * `combat_participants` dit **qui** se bat. Une inclusion dit **ce qu'un evenement a apporte**,
     * et repond a une question differente : cet evenement figure-t-il deja dans cette photographie ?
     * Les confondre coute cher dans les deux sens — une arrivee appliquee au monde mais absente de
     * la photographie serait perdue pour la bataille ; comptee sans avoir ete appliquee, elle ferait
     * combattre des vaisseaux qui ne sont pas la.
     *
     * ## Creer, ou relire et comparer — jamais ecraser
     *
     * `updateOrCreate()` etait le mauvais outil. Sur un rejeu, il aurait **ecrase en silence** une
     * contribution differente : deux verites sur un meme fait, et c'est la derniere arrivee qui
     * l'emporte, sans que rien ne le dise.
     *
     * Trois issues seulement, et elles sont exhaustives :
     *
     *     l evenement n existe pas          -> on l inscrit
     *     il existe avec le meme ensemble   -> rien a faire, c est un rejeu
     *     il existe avec autre chose        -> contradiction, et elle s arrete
     *
     * @param array<int, AttackCandidateGroup> $groups
     */
    private function recordInclusions(
        CombatInstance $combat,
        array $groups,
        SnapshotContribution $contribution,
    ): void {
        $projection = $this->frozenProjectionOf($combat);
        $apport = SnapshotContributionSet::ofOne($contribution);

        foreach ($groups as $groupe) {
            foreach ($groupe->missions as $mission) {
                $this->includeOnce(
                    $combat,
                    CombatEventIdentity::forFleetArrival($mission->missionId),
                    $apport,
                    $projection
                );
            }
        }
    }

    /**
     * Inscrit cet evenement dans la photographie, ou verifie qu'il y figure a l'identique.
     *
     * L'unicite en base ferme la porte que la lecture laisserait entrouverte si deux transactions
     * arrivaient ensemble : la seconde recoit une violation de contrainte, relit, et applique la
     * meme comparaison. Un doublon devient alors soit un rejeu silencieux, soit une contradiction —
     * jamais une seconde ligne.
     */
    private function includeOnce(
        CombatInstance $combat,
        string $eventIdentity,
        SnapshotContributionSet $contributions,
        string $projection,
    ): void {
        $existante = CombatSnapshotInclusion::query()
            ->where('combat_instance_id', $combat->id)
            ->where('event_identity', $eventIdentity)
            ->first();

        if ($existante !== null) {
            $this->ensureSameInclusion($existante, $eventIdentity, $contributions);

            return;
        }

        try {
            CombatSnapshotInclusion::query()->create([
                'combat_instance_id' => $combat->id,
                'event_identity' => $eventIdentity,
                'projection_version' => $projection,
                'contributions' => $contributions->toStorage(),
                // L'heure de l'ouverture, pas celle du worker : deux fermetures du meme combat
                // doivent ecrire la meme photographie.
                'included_at' => $combat->started_at ?? 0,
            ]);
        } catch (QueryException $course) {
            $gagnante = CombatSnapshotInclusion::query()
                ->where('combat_instance_id', $combat->id)
                ->where('event_identity', $eventIdentity)
                ->first();

            if ($gagnante === null) {
                throw $course;
            }

            $this->ensureSameInclusion($gagnante, $eventIdentity, $contributions);
        }
    }

    /**
     * Exige que l'inclusion deja inscrite dise exactement la meme chose.
     *
     * @throws ContradictorySnapshotInclusion Si elle dit autre chose.
     */
    private function ensureSameInclusion(
        CombatSnapshotInclusion $existante,
        string $eventIdentity,
        SnapshotContributionSet $contributions,
    ): void {
        $inscrites = SnapshotContributionSet::fromStorage($existante->contributions);

        if ($inscrites->equals($contributions)) {
            return;
        }

        throw new ContradictorySnapshotInclusion(
            $eventIdentity,
            $inscrites->toStorage(),
            $contributions->toStorage()
        );
    }

    /**
     * La projection gelee de ce combat, verifiee connue.
     *
     * **Verifiee avant toute ecriture, et non apres.** Une inclusion ecrite sous une projection que
     * l'instance ne porte pas signifierait autre chose que ce qu'elle dit — et comme la projection
     * ne fait plus partie de la clef d'unicite, rien d'autre ne l'attraperait.
     */
    private function frozenProjectionOf(CombatInstance $combat): string
    {
        // **Par le registre, comme les quatre autres versions.** Une projection que ce code ne sait
        // plus lire arrete la fermeture au lieu de deviner.
        return $this->projections->forVersion((string)$combat->projection_version)->version();
    }

    /**
     * Chaque flotte renvoyee apprend pourquoi.
     *
     * ## Pourquoi le message est ecrit ici, et pas envoye ici
     *
     * Resoudre un ralliement fait deux choses : figer la photographie, et en informer les joueurs.
     * Les faire separement laisse deux pannes, mauvaises toutes les deux — une flotte renvoyee sans
     * explication, ou une explication pour un renvoi qui n'a pas eu lieu.
     *
     * Le message est donc **ecrit dans la transaction de la fermeture** et envoye plus tard par un
     * lecteur separe. Si la transaction est annulee, le message part avec elle ; si elle passe, il
     * existe et finira par sortir, meme apres un redemarrage.
     *
     * ## Une ligne par flotte, et non par joueur
     *
     * Chaque mission est un mouvement distinct qui repart de son cote. Un joueur dont trois flottes
     * d'une meme attaque coordonnee sont renvoyees recoit trois avis — comme pour trois retours
     * ordinaires, parce que ce sont bien trois flottes qui rentrent.
     *
     * ## L'heure est celle de l'echeance, pas celle du worker
     *
     * `available_at` prend l'echeance de fermeture. Prendre l'instant du traitement ferait dependre
     * la boite du moment ou le worker s'est reveille : deux fermetures du meme combat ecriraient
     * deux heures differentes, et l'unicite ne les distinguerait pas pour autant.
     *
     * ## Le texte n'est pas ici
     *
     * La charge porte le **fait** — la raison, le corps, les coordonnees, la taille du groupe. Le
     * texte se compose a l'envoi, depuis les cles de traduction : le figer maintenant le rendrait
     * insensible a la langue du joueur.
     */
    private function announceRefusals(CombatInstance $combat, AdmissionVerdict $verdict, int $closedAt): void
    {
        foreach ($verdict->refused() as $admission) {
            $raison = $admission->refusal;

            if ($raison === null) {
                // Un refus sans raison serait un refus qu'on ne sait pas raconter. Le selecteur en
                // rend toujours une ; le verifier evite d'ecrire un message vide si cela changeait.
                continue;
            }

            foreach ($admission->group->missions as $mission) {
                CombatOutboxMessage::query()->updateOrCreate(
                    [
                        'combat_instance_id' => $combat->id,
                        'participant_key' => CombatParticipantKey::forFleet($mission->missionId),
                        'kind' => CombatOutboxKind::RallyRefused->value,
                    ],
                    [
                        'payload' => [
                            'reason' => $raison->value,
                            'target_body_id' => $combat->target_planet_id,
                            'galaxy' => $combat->galaxy,
                            'system' => $combat->system,
                            'position' => $combat->position,
                            // La taille du groupe : « ta vague de cinq est repartie entiere » ne se
                            // raconte pas comme « ta flotte est repartie ».
                            'group_fleets' => count($admission->group->missions),
                        ],
                        'available_at' => $closedAt,
                    ]
                );
            }
        }
    }

    /**
     * La reservation passe de « ouverte » a « scellee ».
     *
     * ## Ce que le scellement veut dire
     *
     * Tant que le ralliement court, la borne peut encore monter : une cargaison livree, un
     * Decouvreur admis. A la fermeture, la photographie est prise et **la borne ne bouge plus** —
     * c'est ce que le scellement enregistre.
     *
     * ## Pourquoi la transition est demandee a l'enumeration
     *
     * `OPEN → SEALED` est permise, `SETTLED → SEALED` ne l'est pas. Ecrire l'etat sans le demander
     * laisserait une reprise scellera une reservation deja reglee — le butin preleve, puis la borne
     * refigee sur des ressources qui ont deja change de mains.
     *
     * Une reservation deja scellee n'est pas une faute : la fermeture peut etre rejouee, et la
     * seconde tentative constate au lieu de lever.
     *
     * ## L'heure est celle de l'echeance
     *
     * Comme pour les avis de refus : prendre l'instant du worker ferait dependre le scellement du
     * moment de son reveil, et deux fermetures du meme combat ecriraient deux heures.
     */
    private function sealReservation(CombatInstance $combat, int $closedAt): void
    {
        $reservation = CombatLootReservation::query()
            ->where('combat_instance_id', $combat->id)
            ->lockForUpdate()
            ->first();

        if ($reservation === null) {
            // Un combat ouvert avant que la reservation n'existe. La fermeture n'invente pas une
            // borne apres coup : elle serait calculee sur un stock qui a deja bouge.
            return;
        }

        $etat = $reservation->state;

        if ($etat === LootReservationState::Sealed || !$etat->canTransitionTo(LootReservationState::Sealed)) {
            return;
        }

        $reservation->state = LootReservationState::Sealed;
        $reservation->sealed_at = $closedAt;
        $reservation->save();
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
     * Combien de flottes ces groupes portent.
     *
     * **Des groupes, et non un verdict.** Le verdict ignore le groupe fondateur — il n'a rien a
     * decider sur lui — et compter depuis lui sous-estimait donc les budgets consommes de toute la
     * vague d'ouverture.
     *
     * @param array<int, AttackCandidateGroup> $groups
     */
    private function countFleets(array $groups): int
    {
        $total = 0;

        foreach ($groups as $groupe) {
            $total += $groupe->fleetCount();
        }

        return $total;
    }

    /**
     * Combien de joueurs distincts ces groupes reunissent, les deux camps confondus.
     *
     * @param array<int, AttackCandidateGroup> $attackers
     * @param array<int, AttackCandidateGroup> $defenders
     */
    private function countPlayers(array $attackers, array $defenders): int
    {
        $joueurs = [];

        foreach ([$attackers, $defenders] as $cote) {
            foreach ($cote as $groupe) {
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
