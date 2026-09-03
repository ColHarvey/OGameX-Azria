<?php

namespace Tests\Unit\Combat;

use OGame\Combat\Admission\AdmissionBudget;
use OGame\Combat\Admission\AdmissionVerdict;
use OGame\Combat\Admission\AttackAdmissionSelector;
use OGame\Combat\Admission\AttackCandidateGroup;
use OGame\Combat\Admission\CandidateMission;
use OGame\Combat\Admission\DefensiveAdmissionSelector;
use OGame\Combat\Admission\FoundingGroup;
use OGame\Combat\Decisions\ArrivalDecision;
use OGame\Combat\Decisions\ArrivingAssets;
use OGame\Combat\Decisions\CombatDecisionMatrix;
use OGame\Combat\Decisions\CombatSituation;
use OGame\Combat\Enums\ActorKind;
use OGame\Combat\Enums\CombatMissionAction;
use OGame\Combat\Enums\CombatMissionKind;
use OGame\Combat\Enums\CombatReasonCode;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Enums\FlightLeg;
use OGame\Combat\Enums\SnapshotObligation;
use OGame\Combat\Enums\TargetScope;
use OGame\Combat\Support\CombatRallyWindow;
use OGame\Combat\Support\ReturnPlan;
use OGame\Models\Planet\Coordinate;
use Tests\UnitTestCase;

/**
 * Les scenarios d'arrivee, portes sur le chemin canonique.
 *
 * ## Pourquoi ce fichier existe
 *
 * `CombatRallyWindow::decideArrival()` repondait a une seule question — « qu'advient-il de cette
 * flotte ? » — en melangeant deux mecanismes que le chemin canonique separe :
 *
 *     le mouvement de la flotte           -> CombatDecisionMatrix
 *     l'appartenance a un camp, sous verrou -> AttackAdmissionSelector, DefensiveAdmissionSelector
 *
 * Les faits arrivaient en outre par un objet de sept booleens que l'appelant remplissait lui-meme.
 * Personne ne verifiait qu'ils etaient vrais, ni qu'ils avaient ete lus sous verrou, ni qu'ils
 * dataient de l'ouverture. Les selecteurs, eux, recoivent des lignes relues et figees.
 *
 * Les preuves sont donc **transferees avant la suppression**, scenario par scenario et sous les
 * memes noms : ce fichier reprend les quinze essais d'arrivee de `CombatRallyWindowTest`, ecrits
 * contre la matrice et les deux selecteurs. `decideArrival()` et `CombatArrival` ne partent
 * qu'ensuite. Les essais d'echeance — `closesAt()`, `closesAfterWithdrawal()`, le compte a rebours
 * — restent ou ils sont : cette regle-la ne bouge pas.
 *
 * ## Deux differences de vocabulaire, et elles sont voulues
 *
 * **`OpensRally` a disparu, et c'est un progres.** `CombatMissionAction` nomme le camp que la
 * flotte prend, pas l'effet de bord sur l'instance : ouvrir ou rejoindre se deduit de l'etat de la
 * cible. Une arrivee attaquante sur un corps libre rend donc `JoinAttack`, et l'orchestrateur
 * ouvre.
 *
 * **Le booleen `windowStillOpen` a disparu aussi.** L'ancienne signature demandait l'etat *et* un
 * drapeau, ce qui laissait exprimer l'impossible — `Active` avec une fenetre ouverte. La fermeture
 * est desormais gardee deux fois, par deux mecanismes independants : la matrice, parce que l'etat
 * n'est plus `Rallying` ; le selecteur, parce que l'arrivee planifiee depasse le plafond temporel.
 * Les deux sont eprouves ci-dessous.
 *
 * ## Ce que le chemin canonique ajoute
 *
 * Une raison. `CombatArrivalOutcome` avait quatre valeurs et aucun motif : un joueur econduit
 * lisait « rappele », sans savoir s'il etait arrive trop tard, hors alliance, ou de trop. Chaque
 * refus porte maintenant son code — voir le dernier essai de ce fichier.
 */
class RallyArrivalCoverageTest extends UnitTestCase
{
    private const int OPENING = 1_000;

    private const int TARGET_BODY = 77;

    private const int CREATOR = 10;

    private const int ALLIANCE = 500;

    /**
     * Une arrivee sur un corps celeste au repos ouvre la fenetre.
     *
     * Sur le chemin canonique : la flotte prend le camp attaquant, et comme rien ne tient le corps,
     * c'est elle qui ouvre. Aucune photographie n'existe encore, donc aucune obligation.
     */
    public function testAnArrivalOnAQuietBodyOpensTheWindow(): void
    {
        $verdict = $this->verdictOn(CombatMissionKind::Attack, FlightLeg::Outbound, null);

        $this->assertSame(
            CombatMissionAction::JoinAttack,
            $verdict->movement->action(),
            'A fleet arriving where nothing is happening must take the attacking side and open the rally.'
        );

        $this->assertSame(
            SnapshotObligation::NotConcerned,
            $verdict->snapshot,
            'An arrival on a quiet body was asked to settle its place in a snapshot that does not exist.'
        );
    }

    /**
     * Un combat termine ne retient plus rien : l'arrivee suivante ouvre une nouvelle fenetre.
     */
    public function testAFinishedCombatDoesNotHoldTheBody(): void
    {
        foreach ([CombatState::Resolved, CombatState::Cancelled] as $etat) {
            $this->assertSame(
                CombatMissionAction::JoinAttack,
                $this->movementOn(CombatMissionKind::Attack, FlightLeg::Outbound, $etat)->action(),
                "The state {$etat->value} still holds the body, so a later attack could never begin."
            );
        }
    }

    /**
     * Une fois la fenetre fermee, une attaque fait demi-tour.
     *
     * **Deux gardes independantes, et il faut les deux.** L'ancienne signature portait un booleen
     * `windowStillOpen` a cote de l'etat ; le chemin canonique n'en a plus besoin :
     *
     *     la fenetre s'est fermee, l'etat a change   -> la matrice renvoie la flotte
     *     l'etat n'a pas encore change, l'arrivee est tardive -> le selecteur la refuse
     *
     * La seconde garde compte autant que la premiere : entre l'echeance et le passage du worker de
     * fermeture, une cible peut encore porter `Rallying` alors que sa fenetre est close.
     */
    public function testOnceTheWindowIsClosedAnAttackTurnsBack(): void
    {
        $mouvement = $this->movementOn(CombatMissionKind::Attack, FlightLeg::Outbound, CombatState::Active);

        $this->assertSame(
            CombatMissionAction::ReturnToOrigin,
            $mouvement->action(),
            'An attack joined after the window closed, which would change a result already computed.'
        );

        $this->assertSame(CombatReasonCode::RallyClosed, $mouvement->reason());

        // La seconde garde : l'etat dit encore « ralliement », mais l'arrivee tombe sur le plafond.
        $this->assertSame(
            CombatReasonCode::RallyWindowLimit,
            $this->refusalOf($this->admissionOf(
                $this->aFoundingGroupOf(1),
                $this->aCandidateGroup(userId: self::CREATOR, arrivesAt: self::OPENING + CombatRallyWindow::WINDOW_SECONDS)
            )),
            'A fleet scheduled exactly at the ceiling was admitted while the state still read rallying.'
        );
    }

    /**
     * Un retour ou un deploiement arrive apres la fermeture se pose, mais ne combat pas.
     *
     * Le renvoyer serait absurde : il rentre chez lui. C'est ce cas qui impose que les pertes
     * soient appliquees comme une difference sur la photo, jamais en remplacant le contenu du
     * corps celeste.
     *
     * Pendant `Resolving`, la decision est **differee, pas suspendue** : la continuation porte deja
     * ce qu'on en fera, et c'est exactement la meme chose.
     */
    public function testAReturningFleetLandsWithoutFighting(): void
    {
        foreach ([CombatState::Active, CombatState::Resolving] as $etat) {
            foreach ([
                [CombatMissionKind::Attack, FlightLeg::Return],
                [CombatMissionKind::Deployment, FlightLeg::Outbound],
            ] as [$mission, $etape]) {
                $reglee = $this->settledOf($this->movementOn($mission, $etape, $etat));

                $this->assertSame(
                    CombatMissionAction::LandOutsideSnapshot,
                    $reglee->action(),
                    "A returning fleet was turned away from its own planet during state {$etat->value}."
                );

                $this->assertSame(CombatReasonCode::OwnFleetComingHome, $reglee->reason());
            }
        }
    }

    /**
     * Un combat en cours ou en resolution ne prend aucun combattant.
     *
     * Les deux camps sont couverts : l'attaque comme la Defense ACS repartent. Une defense tardive
     * ne stationne jamais, immunisee, au-dessus d'une bataille en cours.
     */
    public function testARunningCombatTakesNoFighterIn(): void
    {
        $combattantes = [
            CombatMissionKind::Attack,
            CombatMissionKind::AcsAttack,
            CombatMissionKind::MoonDestruction,
            CombatMissionKind::AcsDefend,
        ];

        foreach ([CombatState::Active, CombatState::Resolving] as $etat) {
            foreach ($combattantes as $mission) {
                $reglee = $this->settledOf($this->movementOn($mission, FlightLeg::Outbound, $etat));

                $this->assertSame(
                    CombatMissionAction::ReturnToOrigin,
                    $reglee->action(),
                    "A {$mission->value} joined a combat in state {$etat->value}, whose result is already frozen."
                );

                $this->assertSame(CombatReasonCode::RallyClosed, $reglee->reason());
            }
        }
    }

    /**
     * Une vague du meme attaquant rejoint le combat : c'est la raison d'etre de la fenetre.
     */
    public function testAWaveFromTheSameAttackerJoinsTheRally(): void
    {
        $this->assertNull(
            $this->refusalOf($this->admissionOf(
                $this->aFoundingGroupOf(1),
                $this->aCandidateGroup(userId: self::CREATOR)
            )),
            'A second wave from the same attacker must fight in the same battle.'
        );
    }

    /**
     * Un allie de l'alliance attaquante rejoint le combat.
     */
    public function testAnAllyOfTheAttackingAllianceJoinsTheRally(): void
    {
        $this->assertNull(
            $this->refusalOf($this->admissionOf(
                $this->aFoundingGroupOf(1),
                $this->aCandidateGroup(userId: 21, allianceId: self::ALLIANCE)
            )),
            'An ally of the attacking alliance must be able to join.'
        );
    }

    /**
     * Sans alliance chez l'attaquant initial, personne d'autre que lui ne rejoint.
     *
     * L'alliance est le seul titre d'un tiers. S'il n'y en a pas, il n'y a pas de titre — et un
     * allie declare d'un joueur sans alliance n'existe pas.
     */
    public function testWithNoAllianceNobodyButTheInitiatorJoins(): void
    {
        $sansAlliance = $this->aFoundingGroupOf(1, governingAlliance: null);

        $this->assertSame(
            CombatReasonCode::AllianceNotEligible,
            $this->refusalOf($this->admissionOf(
                $sansAlliance,
                $this->aCandidateGroup(userId: 21, allianceId: self::ALLIANCE)
            )),
            'Someone joined the battle of an attacker who belongs to no alliance at all.'
        );

        // Et meme un candidat qui se declarerait « sans alliance, comme lui » n'entre pas : le
        // titre vient de l'alliance qui gouverne, pas d'une egalite de valeurs nulles.
        $this->assertSame(
            CombatReasonCode::AllianceNotEligible,
            $this->refusalOf($this->admissionOf(
                $sansAlliance,
                $this->aCandidateGroup(userId: 21, allianceId: null)
            )),
            'Two players without an alliance were treated as allies of one another.'
        );

        $this->assertNull(
            $this->refusalOf($this->admissionOf(
                $sansAlliance,
                $this->aCandidateGroup(userId: self::CREATOR, allianceId: null)
            )),
            'An attacker without an alliance could not even send his own second wave.'
        );
    }

    /**
     * Un attaquant independant n'est jamais fait allie par accident.
     *
     * **C'est la regle la plus importante de ce fichier.** Deux ennemis qui visent la meme cible
     * n'ont pas decide de s'allier : mettre leurs flottes dans la meme bataille calculerait leurs
     * pertes ensemble et partagerait leur butin.
     */
    public function testAnIndependentAttackerNeverBecomesAnAllyByAccident(): void
    {
        $this->assertSame(
            CombatReasonCode::AllianceNotEligible,
            $this->refusalOf($this->admissionOf(
                $this->aFoundingGroupOf(1),
                $this->aCandidateGroup(userId: 21, allianceId: self::ALLIANCE + 1)
            )),
            'An unrelated attacker joined the battle, and would share losses and loot with someone who never agreed to it.'
        );
    }

    /**
     * Personne ne vient preter main-forte a un pirate.
     *
     * La regle vaut dans les deux sens, et chacun a son controle :
     *
     *     candidate pilotee par le serveur -> elle ne rejoint pas le camp d'un joueur
     *     cible pilotee par le serveur     -> l'ouvreur y va seul, ses propres vagues comprises
     *
     * L'ancienne regle portait le second sens dans un booleen `targetIsNpcHeld` ; le selecteur le
     * recoit maintenant comme un fait fige de la cible, et refuse avec une raison qui le nomme.
     */
    public function testNobodyReinforcesAPirate(): void
    {
        // Le sens « cible pirate » : un allie en regle est refuse quand meme.
        $this->assertSame(
            CombatReasonCode::NpcSideNotReinforceable,
            $this->refusalOf($this->admissionOf(
                $this->aFoundingGroupOf(1),
                $this->aCandidateGroup(userId: 21, allianceId: self::ALLIANCE),
                ActorKind::Npc
            )),
            'A player joined an attack on a server-driven account, so a sixteen-fleet rally can fall on a pirate base.'
        );

        // Mais l'ouvreur continue d'envoyer ses propres vagues : ce sont ses flottes, pas un renfort.
        $this->assertNull(
            $this->refusalOf($this->admissionOf(
                $this->aFoundingGroupOf(1),
                $this->aCandidateGroup(userId: self::CREATOR),
                ActorKind::Npc
            )),
            'The opener could not send his own second wave against a pirate he was already attacking.'
        );

        // Le sens inverse : une flotte pilotee par le serveur ne rejoint pas le camp d'un joueur.
        $this->assertSame(
            CombatReasonCode::NpcSideNotReinforceable,
            $this->refusalOf($this->admissionOf(
                $this->aFoundingGroupOf(1),
                $this->aCandidateGroup(userId: 21, allianceId: self::ALLIANCE, actor: ActorKind::Npc)
            )),
            'A server-driven fleet joined a player rally.'
        );
    }

    /**
     * La seizieme flotte passe, la dix-septieme non.
     */
    public function testTheSixteenthFleetPassesAndTheSeventeenthDoesNot(): void
    {
        $this->assertNull(
            $this->refusalOf($this->admissionOf(
                $this->aFoundingGroupOf(AdmissionBudget::CANONICAL_FLEETS - 1),
                $this->aCandidateGroup(userId: self::CREATOR)
            )),
            'The sixteenth fleet was refused, one too early.'
        );

        $this->assertSame(
            CombatReasonCode::FleetLimitReached,
            $this->refusalOf($this->admissionOf(
                $this->aFoundingGroupOf(AdmissionBudget::CANONICAL_FLEETS),
                $this->aCandidateGroup(userId: self::CREATOR)
            )),
            'A seventeenth fleet joined the side.'
        );
    }

    /**
     * Cinq joueurs distincts au total, attaquant initial compris.
     *
     * Le compte inclut celui qui a ouvert le combat. Compter « l'initiateur plus cinq allies »
     * ferait six joueurs et contournerait la limite ACS du jeu.
     */
    public function testTheLimitIsFiveDistinctPlayersIncludingTheInitiator(): void
    {
        // A ouvre, B, C et D rejoignent : quatre joueurs, il reste une place.
        $this->assertNull(
            $this->refusalOf($this->admissionOf(
                $this->aFoundingGroupOf(4, players: 4),
                $this->aCandidateGroup(userId: 90, allianceId: self::ALLIANCE)
            )),
            'The fifth player was refused, one too early.'
        );

        // A, B, C, D et E : la limite est atteinte, F est refuse.
        $this->assertSame(
            CombatReasonCode::PlayerLimitReached,
            $this->refusalOf($this->admissionOf(
                $this->aFoundingGroupOf(5, players: 5),
                $this->aCandidateGroup(userId: 90, allianceId: self::ALLIANCE)
            )),
            'A sixth player joined the side, so the rally window works around the ACS limit.'
        );
    }

    /**
     * Un camp complet en joueurs accepte encore les vagues de ceux qui y sont deja.
     *
     * **La distinction que ce test protege** : la limite compte des joueurs, pas des flottes. Une
     * seconde vague n'amene personne de nouveau. La refuser interdirait a l'attaquant initial
     * d'envoyer la sienne des que cinq joueurs se battent — soit exactement ce que la fenetre de
     * ralliement existe pour permettre.
     */
    public function testAFullSideStillAcceptsWavesFromThoseAlreadyInIt(): void
    {
        $complet = $this->aFoundingGroupOf(5, players: 5);

        $this->assertNull(
            $this->refusalOf($this->admissionOf($complet, $this->aCandidateGroup(userId: self::CREATOR))),
            'The initiator could not send another of his own fleets because five players were already fighting.'
        );

        $this->assertNull(
            $this->refusalOf($this->admissionOf(
                $complet,
                $this->aCandidateGroup(userId: self::CREATOR + 1, allianceId: self::ALLIANCE)
            )),
            'An ally already in the battle could not send a second wave.'
        );
    }

    /**
     * Le plafond de seize flottes ne connait aucune exception.
     *
     * Contrairement a la limite de joueurs, celle-ci s'applique meme a l'attaquant initial : ce
     * sont bien des flottes qu'elle compte.
     */
    public function testTheSixteenFleetCeilingHasNoException(): void
    {
        $plein = $this->aFoundingGroupOf(AdmissionBudget::CANONICAL_FLEETS, players: 5);

        foreach ([self::CREATOR, 90] as $joueur) {
            $this->assertSame(
                CombatReasonCode::FleetLimitReached,
                $this->refusalOf($this->admissionOf(
                    $plein,
                    $this->aCandidateGroup(userId: $joueur, allianceId: self::ALLIANCE)
                )),
                'A seventeenth fleet joined the side.'
            );
        }
    }

    /**
     * Un renfort defenseur arrive a temps participe toujours.
     *
     * Cote defenseur, la matrice delegue au selecteur defensif, qui a ses propres listes et ses
     * propres budgets. Les deux etapes sont eprouvees, sans quoi une delegation vers un mecanisme
     * qui refuserait tout passerait pour une admission.
     */
    public function testADefenderReinforcementArrivingInTimeAlwaysJoins(): void
    {
        $this->assertSame(
            CombatMissionAction::SelectByDefenceAdmission,
            $this->movementOn(CombatMissionKind::AcsDefend, FlightLeg::Outbound, CombatState::Rallying)->action(),
            'A defence arriving during the rally was decided without the defensive selector.'
        );

        $verdict = (new DefensiveAdmissionSelector())->select(
            7,
            self::TARGET_BODY,
            self::OPENING,
            [$this->aCandidate(
                missionId: 900,
                userId: 21,
                arrivesAt: self::OPENING + 10,
                mission: CombatMissionKind::AcsDefend
            )]
        );

        $this->assertSame(
            [],
            $verdict->refused(),
            'A defender reinforcement arriving before the window closes must take part.'
        );
    }

    /**
     * Aucune combinaison ne rend une issue inattendue.
     *
     * Les cinq invariants de l'ancien balayage sont repartis sur les deux mecanismes, parce que
     * c'est la ou ils vivent desormais :
     *
     *     mouvement       -> les 396 cases de la matrice
     *     appartenance    -> le produit cartesien des faits d'admission
     *
     * Les deux balayages sont exhaustifs sur leur propre domaine ; l'ancien ne l'etait que sur des
     * booleens que personne ne verifiait.
     */
    public function testNoCombinationProducesAnUnexpectedOutcome(): void
    {
        $this->assertNoMatrixCellMisplacesAFleet();
        $this->assertNoAdmissionEscapesItsConditions();
    }

    /**
     * Chaque refus porte la raison que l'ancienne issue ne savait pas dire.
     *
     * `CombatArrivalOutcome::RecalledToOrigin` couvrait sept refus differents sous un seul mot. Un
     * joueur econduit lisait « rappele » sans savoir s'il etait arrive trop tard, hors alliance, de
     * trop, ou sur le mauvais corps — et l'interface n'avait aucun moyen de le lui dire.
     *
     * **Le retour a quitte cette table**, et c'est un progres du meme ordre : il n'est pas un refus
     * a montrer, mais une entree que la matrice ne delegue jamais au selecteur attaquant. Il est
     * arrete et audite — voir `AdmissionSelectorTest::testAContradictoryShapeIsStoppedRatherThanRefused`.
     */
    public function testEachRefusalNowCarriesTheReasonTheOldOutcomeCouldNotExpress(): void
    {
        $fondation = $this->aFoundingGroupOf(1);

        $attendus = [
            'mauvais corps' => [
                $this->aCandidateGroup(userId: 21, allianceId: self::ALLIANCE, targetBodyId: self::TARGET_BODY + 1),
                CombatReasonCode::WrongTargetBody,
            ],
            'pas encore en vol' => [
                $this->aCandidateGroup(userId: 21, allianceId: self::ALLIANCE, inFlightAtOpening: false),
                CombatReasonCode::NotAlreadyInFlight,
            ],
            'rappelee' => [
                $this->aCandidateGroup(userId: 21, allianceId: self::ALLIANCE, recalled: true),
                CombatReasonCode::CandidateRecalled,
            ],
            'trop tard' => [
                $this->aCandidateGroup(
                    userId: 21,
                    allianceId: self::ALLIANCE,
                    arrivesAt: self::OPENING + CombatRallyWindow::WINDOW_SECONDS
                ),
                CombatReasonCode::RallyWindowLimit,
            ],
            'hors alliance' => [
                $this->aCandidateGroup(userId: 21, allianceId: self::ALLIANCE + 1),
                CombatReasonCode::AllianceNotEligible,
            ],
        ];

        $obtenus = [];

        foreach ($attendus as $nom => [$candidate, $_]) {
            $obtenus[$nom] = $this->refusalOf($this->admissionOf($fondation, $candidate));
        }

        $this->assertSame(
            array_map(static fn (array $cas): CombatReasonCode => $cas[1], $attendus),
            $obtenus,
            'Two different refusals ended up carrying the same reason, which is exactly what the old outcome did.'
        );

        // Les budgets et la cible pirate completent la liste : huit raisons distinctes la ou
        // l'ancienne regle n'en avait aucune.
        $this->assertSame(
            [
                CombatReasonCode::FleetLimitReached,
                CombatReasonCode::PlayerLimitReached,
                CombatReasonCode::NpcSideNotReinforceable,
            ],
            [
                $this->refusalOf($this->admissionOf(
                    $this->aFoundingGroupOf(AdmissionBudget::CANONICAL_FLEETS),
                    $this->aCandidateGroup(userId: self::CREATOR)
                )),
                $this->refusalOf($this->admissionOf(
                    $this->aFoundingGroupOf(5, players: 5),
                    $this->aCandidateGroup(userId: 90, allianceId: self::ALLIANCE)
                )),
                $this->refusalOf($this->admissionOf(
                    $fondation,
                    $this->aCandidateGroup(userId: 21, allianceId: self::ALLIANCE),
                    ActorKind::Npc
                )),
            ]
        );
    }

    /**
     * Aucune des 396 cases ne place une flotte la ou elle n'a rien a faire.
     *
     * Quatre invariants, transposes de l'ancien balayage :
     *
     *     JoinAttack               -> seulement sur un corps libre
     *     SelectBy...Admission     -> seulement pendant le ralliement
     *     LandOutsideSnapshot      -> seulement une photo prise, et un retour ou un deploiement
     *     corps libre              -> ouvre si la mission ouvre, passe normalement sinon
     */
    private function assertNoMatrixCellMisplacesAFleet(): void
    {
        $matrice = new CombatDecisionMatrix();
        $examinees = 0;

        foreach (CombatSituation::all() as $situation) {
            if (!$situation->isPossible() || $situation->scope() === TargetScope::DeepSpace) {
                continue;
            }

            $examinees++;

            $mouvement = $matrice->verdictOf($situation, $this->aPossibleReturn(), ArrivingAssets::fleetWithCargo())->movement;
            $reglee = $this->settledOf($mouvement);
            $corpsLibre = $situation->targetState === null || !$situation->targetState->locksTargetBody();

            if ($mouvement->action() === CombatMissionAction::JoinAttack) {
                $this->assertTrue($corpsLibre, 'A new rally opened on a body already held by a combat: ' . $situation->describe());
            }

            if (in_array($mouvement->action(), [
                CombatMissionAction::SelectByAttackAdmission,
                CombatMissionAction::SelectByDefenceAdmission,
            ], true)) {
                $this->assertSame(
                    CombatState::Rallying,
                    $situation->targetState,
                    'An admission was demanded outside the rally: ' . $situation->describe()
                );
            }

            if ($reglee->action() === CombatMissionAction::LandOutsideSnapshot) {
                $this->assertContains(
                    $situation->targetState,
                    [CombatState::Active, CombatState::Resolving],
                    'A fleet was set aside from a snapshot that had not been taken: ' . $situation->describe()
                );

                $this->assertTrue(
                    $situation->leg === FlightLeg::Return || $situation->mission === CombatMissionKind::Deployment,
                    'A fleet landed without fighting although it was neither returning nor deploying: ' . $situation->describe()
                );
            }

            if ($corpsLibre) {
                $ouvre = $situation->leg === FlightLeg::Outbound && $situation->mission->opensCombat();

                $this->assertSame(
                    $ouvre ? CombatMissionAction::JoinAttack : CombatMissionAction::AllowNormally,
                    $mouvement->action(),
                    'An arrival on a free body did something other than open a rally or proceed: ' . $situation->describe()
                );
            }
        }

        $this->assertSame(360, $examinees, 'The exhaustive sweep no longer covers every celestial-body situation.');
    }

    /**
     * Aucune admission ne s'affranchit de ses conditions.
     *
     * Le produit cartesien remplace les booleens de l'ancien objet d'arrivee par les faits que le
     * selecteur recoit reellement : l'identite du candidat, son alliance a l'ouverture, l'alliance
     * qui gouverne, le genre de la cible, la composition du groupe fondateur, l'heure planifiee et
     * le rappel.
     */
    private function assertNoAdmissionEscapesItsConditions(): void
    {
        $axes = [
            'joueur' => [self::CREATOR, 21],
            'allianceCandidat' => [self::ALLIANCE, self::ALLIANCE + 1, null],
            'allianceGouvernante' => [self::ALLIANCE, null],
            'cible' => [ActorKind::Player, ActorKind::Npc],
            'fondation' => [[1, 1], [5, 5], [AdmissionBudget::CANONICAL_FLEETS, 5]],
            'arrivee' => [self::OPENING + 10, self::OPENING + CombatRallyWindow::WINDOW_SECONDS],
            'rappelee' => [true, false],
        ];

        $combinaisons = $this->cartesianProduct($axes);

        foreach ($combinaisons as $cas) {
            [$flottes, $joueurs] = $cas['fondation'];

            $fondation = $this->aFoundingGroupOf($flottes, players: $joueurs, governingAlliance: $cas['allianceGouvernante']);

            $candidat = $this->aCandidateGroup(
                userId: $cas['joueur'],
                allianceId: $cas['allianceCandidat'],
                arrivesAt: $cas['arrivee'],
                recalled: $cas['rappelee'],
            );

            $verdict = $this->admissionOf($fondation, $candidat, $cas['cible']);
            $refus = $this->refusalOf($verdict);
            $decrit = json_encode(array_map(
                static fn (mixed $valeur): mixed => $valeur instanceof ActorKind ? $valeur->value : $valeur,
                $cas
            ));

            if ($refus !== null) {
                continue;
            }

            $this->assertTrue(
                $cas['joueur'] === self::CREATOR
                    || ($cas['allianceGouvernante'] !== null && $cas['allianceCandidat'] === $cas['allianceGouvernante']),
                'An attacker with no claim joined the rally: ' . $decrit
            );

            $this->assertTrue(
                $cas['cible'] === ActorKind::Player || $cas['joueur'] === self::CREATOR,
                'A player reinforced an attack on a server-driven account: ' . $decrit
            );

            $this->assertLessThan(
                self::OPENING + CombatRallyWindow::WINDOW_SECONDS,
                $cas['arrivee'],
                'A fleet arriving at or after the ceiling was admitted: ' . $decrit
            );

            $this->assertFalse($cas['rappelee'], 'A recalled fleet was admitted: ' . $decrit);

            $this->assertLessThanOrEqual(
                AdmissionBudget::CANONICAL_FLEETS,
                $flottes + 1,
                'The fleet budget was exceeded: ' . $decrit
            );

            $nouveauJoueur = !in_array($cas['joueur'], $fondation->distinctPlayers(), true);

            $this->assertFalse(
                $nouveauJoueur && count($fondation->distinctPlayers()) >= AdmissionBudget::CANONICAL_PLAYERS,
                'A new player joined a side that had already reached the ACS player limit: ' . $decrit
            );
        }

        $this->assertCount(288, $combinaisons, 'The exhaustive sweep no longer covers every combination.');
    }

    /**
     * Toutes les combinaisons possibles des axes donnes.
     *
     * @param array<string, array<int, mixed>> $axes
     * @return array<int, array<string, mixed>>
     */
    private function cartesianProduct(array $axes): array
    {
        $combinaisons = [[]];

        foreach ($axes as $nom => $valeurs) {
            $etendues = [];

            foreach ($combinaisons as $partielle) {
                foreach ($valeurs as $valeur) {
                    $etendues[] = $partielle + [$nom => $valeur];
                }
            }

            $combinaisons = $etendues;
        }

        return $combinaisons;
    }

    /**
     * Le verdict complet de la matrice pour cette situation.
     */
    private function verdictOn(
        CombatMissionKind $mission,
        FlightLeg $leg,
        CombatState|null $state,
    ): \OGame\Combat\Decisions\ArrivalVerdict {
        return (new CombatDecisionMatrix())->verdictOf(
            new CombatSituation($mission, $leg, ActorKind::Player, $state),
            $this->aPossibleReturn(),
            ArrivingAssets::fleetWithCargo()
        );
    }

    /**
     * Le mouvement decide par la matrice pour cette situation.
     */
    private function movementOn(
        CombatMissionKind $mission,
        FlightLeg $leg,
        CombatState|null $state,
    ): ArrivalDecision {
        return $this->verdictOn($mission, $leg, $state)->movement;
    }

    /**
     * La decision reellement appliquee : la continuation si l'evenement est differe, sinon elle-meme.
     *
     * Un report ne suspend jamais la decision. La distinguer de son objet ferait croire qu'un
     * evenement differe reste a trancher, et c'est precisement l'erreur que la matrice existe pour
     * ne pas commettre.
     */
    private function settledOf(ArrivalDecision $decision): ArrivalDecision
    {
        return $decision->continuation() ?? $decision;
    }

    /**
     * Le verdict d'admission attaquante pour un unique groupe candidat.
     */
    private function admissionOf(
        FoundingGroup $founding,
        AttackCandidateGroup $candidate,
        ActorKind $targetActor = ActorKind::Player,
    ): AdmissionVerdict {
        return (new AttackAdmissionSelector())->select(
            $founding,
            self::TARGET_BODY,
            $targetActor,
            self::OPENING,
            [$candidate]
        );
    }

    /**
     * La raison du refus unique d'un verdict, ou `null` si le groupe a ete admis.
     */
    private function refusalOf(AdmissionVerdict $verdict): CombatReasonCode|null
    {
        $refuses = $verdict->refused();

        if ($refuses === []) {
            return null;
        }

        $this->assertCount(1, $refuses, 'This helper reads a single candidate group.');

        return $refuses[0]->refusal;
    }

    /**
     * Un groupe fondateur, de la taille et de la composition voulues.
     *
     * Les flottes sont distribuees en tourniquet sur les joueurs demandes, le createur en premier :
     * seize flottes sur cinq joueurs donnent bien cinq joueurs distincts.
     */
    private function aFoundingGroupOf(
        int $fleets,
        int $players = 1,
        int|null $governingAlliance = self::ALLIANCE,
        bool $stillAcceptsNewMembers = true,
    ): FoundingGroup {
        $membres = [];

        for ($rang = 0; $rang < $fleets; $rang++) {
            $membres[] = $this->aCandidate(
                missionId: 100 + $rang,
                userId: self::CREATOR + ($rang % $players),
                arrivesAt: self::OPENING,
            );
        }

        return new FoundingGroup(
            self::CREATOR,
            $governingAlliance,
            $membres,
            AdmissionBudget::canonical(),
            $stillAcceptsNewMembers
        );
    }

    /**
     * Un groupe candidat d'une seule flotte.
     */
    private function aCandidateGroup(
        int $userId,
        int|null $allianceId = self::ALLIANCE,
        int $arrivesAt = self::OPENING + 10,
        ActorKind $actor = ActorKind::Player,
        CombatMissionKind $mission = CombatMissionKind::Attack,
        FlightLeg $leg = FlightLeg::Outbound,
        int $targetBodyId = self::TARGET_BODY,
        bool $inFlightAtOpening = true,
        bool $recalled = false,
    ): AttackCandidateGroup {
        return AttackCandidateGroup::ofASingleFleet($this->aCandidate(
            missionId: 900,
            userId: $userId,
            arrivesAt: $arrivesAt,
            allianceId: $allianceId,
            actor: $actor,
            mission: $mission,
            leg: $leg,
            targetBodyId: $targetBodyId,
            inFlightAtOpening: $inFlightAtOpening,
            recalled: $recalled,
        ));
    }

    /**
     * Une mission candidate, alliee et admissible par defaut.
     */
    private function aCandidate(
        int $missionId,
        int $userId,
        int $arrivesAt,
        int|null $allianceId = self::ALLIANCE,
        ActorKind $actor = ActorKind::Player,
        CombatMissionKind $mission = CombatMissionKind::Attack,
        FlightLeg $leg = FlightLeg::Outbound,
        int $targetBodyId = self::TARGET_BODY,
        bool $inFlightAtOpening = true,
        bool $recalled = false,
    ): CandidateMission {
        return new CandidateMission(
            $missionId,
            $userId,
            $allianceId,
            $actor,
            $mission,
            $leg,
            $targetBodyId,
            $arrivesAt,
            $inFlightAtOpening,
            $recalled
        );
    }

    /**
     * Un plan de retour praticable, vers la planete mere.
     */
    private function aPossibleReturn(): ReturnPlan
    {
        return ReturnPlan::toHomeworld(1, new Coordinate(1, 1, 1), 1);
    }
}
