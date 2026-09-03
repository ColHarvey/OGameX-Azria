<?php

namespace Tests\Unit\Combat;

use OGame\Combat\Admission\AdmissionBudget;
use OGame\Combat\Admission\AdmissionVerdict;
use OGame\Combat\Admission\AttackAdmissionSelector;
use OGame\Combat\Admission\AttackCandidateGroup;
use OGame\Combat\Admission\CandidateMission;
use OGame\Combat\Admission\DefensiveAdmissionSelector;
use OGame\Combat\Admission\FoundingGroup;
use OGame\Combat\Admission\GroupAdmission;
use OGame\Combat\Enums\ActorKind;
use OGame\Combat\Enums\CombatMissionKind;
use OGame\Combat\Enums\CombatReasonCode;
use OGame\Combat\Enums\FlightLeg;
use OGame\Combat\Exceptions\ContradictoryAdmissionInput;
use OGame\Combat\Support\CombatRallyWindow;
use Tests\UnitTestCase;

/**
 * Les deux selecteurs d'admission, et la fenetre qu'ils partagent.
 *
 * ## Les valeurs ne viennent pas des noms de colonnes
 *
 * 16 flottes et 5 joueurs, **l'ouvreur compris dans les deux** : c'est le comportement mesure de
 * `FleetUnion::hasReachedMaxFleets()` et `hasReachedMaxPlayers()`, qui comparent avec `>=` sur des
 * missions actives incluant celle de l'initiateur. Les frontieres 16/17 et 5/6 sont donc figees ici.
 *
 * ## L'ouverture de reference
 *
 * A **1000**, plafond temporel a 1060. Comme partout, une egalite avec une barriere compte pour
 * « apres ».
 */
class AdmissionSelectorTest extends UnitTestCase
{
    private const int OPENING = 1_000;

    private const int TARGET_BODY = 77;

    private const int CREATOR = 10;

    private const int ALLIANCE = 500;

    /**
     * L'ouvreur consomme une flotte et un joueur.
     *
     * Mesure du code existant : il recoit `union_slot = 1` et reste une mission active, donc
     * `activeFleetMissions()->count()` l'inclut.
     */
    public function testTheOpenerConsumesOneFleetAndOnePlayer(): void
    {
        $fondateur = $this->aFoundingGroup();

        $this->assertSame(1, $fondateur->fleetCount(), 'The opener does not consume a fleet.');
        $this->assertSame([self::CREATOR], $fondateur->distinctPlayers());

        $verdict = $this->selectAttack([], $fondateur);

        $this->assertSame([], $verdict->admitted());
        $this->assertNull($verdict->latestAdmittedArrival(), 'An empty side asks for no delay.');
    }

    /**
     * La seizieme flotte passe, la dix-septieme est refusee.
     */
    public function testTheSixteenthFleetPassesAndTheSeventeenthIsRefused(): void
    {
        // L'ouvreur occupe la premiere place : quinze candidates completent les seize.
        $candidates = [];

        for ($i = 1; $i <= 16; $i++) {
            $candidates[] = AttackCandidateGroup::ofASingleFleet(
                $this->aCandidate(missionId: $i, userId: self::CREATOR, arrivesAt: self::OPENING + $i)
            );
        }

        $verdict = $this->selectAttack($candidates, $this->aFoundingGroup());

        $this->assertCount(15, $verdict->admitted(), 'The sixteenth fleet, opener included, was refused.');
        $this->assertCount(1, $verdict->refused());
        $this->assertSame(CombatReasonCode::FleetLimitReached, $verdict->refused()[0]->refusal);
    }

    /**
     * Le cinquieme joueur passe, le sixieme est refuse.
     */
    public function testTheFifthPlayerPassesAndTheSixthIsRefused(): void
    {
        $candidates = [];

        // Le createur est le premier joueur ; quatre allies completent les cinq, un cinquieme est de
        // trop.
        foreach ([21, 22, 23, 24, 25] as $rang => $joueur) {
            $candidates[] = AttackCandidateGroup::ofASingleFleet(
                $this->aCandidate(
                    missionId: 100 + $rang,
                    userId: $joueur,
                    arrivesAt: self::OPENING + 1 + $rang
                )
            );
        }

        $verdict = $this->selectAttack($candidates, $this->aFoundingGroup());

        $this->assertCount(4, $verdict->admitted());
        $this->assertCount(1, $verdict->refused());
        $this->assertSame(CombatReasonCode::PlayerLimitReached, $verdict->refused()[0]->refusal);
    }

    /**
     * Plusieurs flottes d'un meme joueur consomment plusieurs flottes et un seul joueur.
     *
     * Mesure de `FleetUnionService::joinUnion()` : `$isNewPlayer` court-circuite le controle des
     * joueurs quand le joueur a deja une mission active dans l'union.
     */
    public function testSeveralFleetsOfOnePlayerConsumeSeveralFleetsButOnePlayer(): void
    {
        $candidates = [];

        // Quatre allies, plus trois vagues supplementaires d'un seul d'entre eux.
        foreach ([21, 22, 23, 24] as $rang => $joueur) {
            $candidates[] = AttackCandidateGroup::ofASingleFleet(
                $this->aCandidate(missionId: 200 + $rang, userId: $joueur, arrivesAt: self::OPENING + 1 + $rang)
            );
        }

        foreach ([1, 2, 3] as $vague) {
            $candidates[] = AttackCandidateGroup::ofASingleFleet(
                $this->aCandidate(missionId: 300 + $vague, userId: 21, arrivesAt: self::OPENING + 20 + $vague)
            );
        }

        $verdict = $this->selectAttack($candidates, $this->aFoundingGroup());

        $this->assertCount(7, $verdict->admitted(), 'A further wave of an existing player was refused.');
        $this->assertSame([], $verdict->refused());
    }

    /**
     * Un copain hors alliance ne rejoint pas automatiquement.
     *
     * ## Deux mecanismes, et il ne faut pas les confondre
     *
     * L'ACS **formelle** d'OGameX reste permise entre copains : elle s'organise avant le combat, et
     * un copain deja present dans l'union appartient au groupe fondateur. Le **ralliement
     * automatique**, lui, est reserve au createur et a son alliance figee.
     */
    public function testABuddyOutsideTheAllianceDoesNotRallyAutomatically(): void
    {
        $copain = $this->aCandidate(missionId: 400, userId: 99, arrivesAt: self::OPENING + 5, allianceId: null);

        $verdict = $this->selectAttack(
            [AttackCandidateGroup::ofASingleFleet($copain)],
            $this->aFoundingGroup()
        );

        $this->assertSame([], $verdict->admitted());
        $this->assertSame(CombatReasonCode::AllianceNotEligible, $verdict->refused()[0]->refusal);
    }

    /**
     * Un copain deja present dans l'union fondatrice reste dans le combat.
     *
     * Il n'est pas une candidate : il est **membre**. Le selecteur ne le rejuge jamais.
     */
    public function testABuddyAlreadyInTheFoundingUnionStaysInTheCombat(): void
    {
        $fondateur = new FoundingGroup(
            self::CREATOR,
            self::ALLIANCE,
            [
                $this->aCandidate(missionId: 1, userId: self::CREATOR, arrivesAt: self::OPENING),
                // Un copain hors alliance, admis avant le combat par l'ACS formelle.
                $this->aCandidate(missionId: 2, userId: 99, arrivesAt: self::OPENING, allianceId: null),
            ],
            AdmissionBudget::canonical()
        );

        $this->assertSame([self::CREATOR, 99], $fondateur->distinctPlayers());
        $this->assertSame(2, $fondateur->fleetCount());

        // Et il n'aurait pas ete admis comme candidate : c'est exactement la distinction.
        $this->assertFalse($fondateur->admitsAutomatically($fondateur->members[1]));
    }

    /**
     * Un changement d'alliance apres l'ouverture ne change rien.
     *
     * L'appartenance est celle de l'ouverture. Un joueur qui rejoint l'alliance pendant la fenetre
     * n'y entre pas ; un joueur qui la quitte n'en sort pas.
     */
    public function testAnAllianceChangeAfterTheOpeningChangesNothing(): void
    {
        $verdict = $this->selectAttack(
            [
                // Il a rejoint l'alliance apres l'ouverture : a l'ouverture, il n'y etait pas.
                AttackCandidateGroup::ofASingleFleet(
                    $this->aCandidate(missionId: 500, userId: 31, arrivesAt: self::OPENING + 5, allianceId: null)
                ),
            ],
            $this->aFoundingGroup()
        );

        $this->assertSame(CombatReasonCode::AllianceNotEligible, $verdict->refused()[0]->refusal);
    }

    /**
     * Le transfert du slot 1 ne change pas l'alliance qui gouverne.
     *
     * `FleetUnionService::handleFleetRecall()` transfere aujourd'hui la propriete de l'union au
     * nouveau slot 1. Cette propriete-la est mouvante ; l'alliance du combat ne l'est pas.
     */
    public function testTheSlotOneTransferDoesNotChangeTheGoverningAlliance(): void
    {
        // Le createur a rappele : les membres continuent, personne d'autre n'entre.
        $fondateur = new FoundingGroup(
            self::CREATOR,
            self::ALLIANCE,
            [$this->aCandidate(missionId: 2, userId: 21, arrivesAt: self::OPENING)],
            AdmissionBudget::canonical(),
            stillAcceptsNewMembers: false
        );

        $verdict = $this->selectAttack(
            [
                AttackCandidateGroup::ofASingleFleet(
                    $this->aCandidate(missionId: 600, userId: 22, arrivesAt: self::OPENING + 5)
                ),
            ],
            $fondateur
        );

        $this->assertSame(CombatReasonCode::RallyClosed, $verdict->refused()[0]->refusal);

        // Et l'alliance qui gouverne reste celle du createur, absent du groupe.
        $this->assertSame(self::ALLIANCE, $fondateur->governingAllianceId);
        $this->assertSame(self::CREATOR, $fondateur->creatorUserId);
    }

    /**
     * Une autre union est admise entierement, ou renvoyee entierement.
     *
     * ## Pourquoi on ne decoupe jamais
     *
     * Une attaque ACS deja en vol arrive ensemble. En admettre trois flottes et en renvoyer deux
     * briserait une attaque coordonnee que ses joueurs ont organisee et payee.
     */
    public function testAnotherUnionIsAdmittedOrReturnedAsAWhole(): void
    {
        // Quatorze places restent apres l'ouvreur et un allie ; un groupe de quinze n'y tient pas.
        $fondateur = new FoundingGroup(
            self::CREATOR,
            self::ALLIANCE,
            [
                $this->aCandidate(missionId: 1, userId: self::CREATOR, arrivesAt: self::OPENING),
                $this->aCandidate(missionId: 2, userId: self::CREATOR, arrivesAt: self::OPENING),
            ],
            AdmissionBudget::canonical()
        );

        $trop = [];

        for ($i = 1; $i <= 15; $i++) {
            $trop[] = $this->aCandidate(missionId: 700 + $i, userId: 21, arrivesAt: self::OPENING + 10);
        }

        $verdict = $this->selectAttack([new AttackCandidateGroup('union:9', $trop)], $fondateur);

        $this->assertSame([], $verdict->admitted(), 'A coordinated attack was cut in half.');
        $this->assertSame(CombatReasonCode::FleetLimitReached, $verdict->refused()[0]->refusal);

        // Le meme groupe a quatorze flottes tient entierement.
        array_pop($trop);

        $tient = $this->selectAttack([new AttackCandidateGroup('union:9', $trop)], $fondateur);

        $this->assertCount(1, $tient->admitted());
        $this->assertSame([], $tient->refused());
    }

    /**
     * L'ordre est deterministe, et ne depend pas de l'ordre de lecture.
     */
    public function testTheOrderIsDeterministicAndNotTheReadingOrder(): void
    {
        $candidates = [
            AttackCandidateGroup::ofASingleFleet($this->aCandidate(missionId: 3, userId: 21, arrivesAt: self::OPENING + 30)),
            AttackCandidateGroup::ofASingleFleet($this->aCandidate(missionId: 1, userId: 22, arrivesAt: self::OPENING + 10)),
            AttackCandidateGroup::ofASingleFleet($this->aCandidate(missionId: 2, userId: 23, arrivesAt: self::OPENING + 20)),
        ];

        $premier = $this->selectAttack($candidates, $this->aFoundingGroup())->describe();
        $second = $this->selectAttack(array_reverse($candidates), $this->aFoundingGroup())->describe();

        $this->assertSame($premier, $second, 'The reading order changed the admissions.');
        $this->assertSame(
            ['mission:1 | admis', 'mission:2 | admis', 'mission:3 | admis'],
            $premier
        );
    }

    /**
     * Chaque refus porte sa raison propre, et l'ordre des controles la decide.
     *
     * Un joueur etranger doit lire « pas allie », pas « limite atteinte » : la seconde lui laisserait
     * croire qu'il aurait pu entrer en arrivant plus tot.
     */
    public function testEachRefusalCarriesItsOwnPreciseReason(): void
    {
        $cas = [
            'mauvais corps' => [$this->aCandidate(missionId: 801, userId: 21, arrivesAt: self::OPENING + 5, targetBodyId: self::TARGET_BODY + 1), CombatReasonCode::WrongTargetBody],
            'pirate' => [$this->aCandidate(missionId: 802, userId: 21, arrivesAt: self::OPENING + 5, actor: ActorKind::Npc), CombatReasonCode::NpcSideNotReinforceable],
            'pas en vol' => [$this->aCandidate(missionId: 805, userId: 21, arrivesAt: self::OPENING + 5, inFlightAtOpening: false), CombatReasonCode::NotAlreadyInFlight],
            'rappelee' => [$this->aCandidate(missionId: 806, userId: 21, arrivesAt: self::OPENING + 5, recalled: true), CombatReasonCode::CandidateRecalled],
            'trop tard' => [$this->aCandidate(missionId: 807, userId: 21, arrivesAt: self::OPENING + AttackAdmissionSelector::MAX_WINDOW_SECONDS), CombatReasonCode::RallyWindowLimit],
            'non allie' => [$this->aCandidate(missionId: 808, userId: 99, arrivesAt: self::OPENING + 5, allianceId: 999), CombatReasonCode::AllianceNotEligible],
        ];

        foreach ($cas as $quoi => [$candidate, $attendue]) {
            $verdict = $this->selectAttack(
                [AttackCandidateGroup::ofASingleFleet($candidate)],
                $this->aFoundingGroup()
            );

            $this->assertSame(
                $attendue,
                $verdict->refused()[0]->refusal,
                "The refusal reason for « {$quoi} » changed."
            );
        }
    }

    /**
     * Une arrivee exactement au plafond temporel est exclue.
     */
    public function testAnArrivalExactlyAtTheCeilingIsExcluded(): void
    {
        $juste = $this->aCandidate(
            missionId: 900,
            userId: 21,
            arrivesAt: self::OPENING + AttackAdmissionSelector::MAX_WINDOW_SECONDS - 1
        );

        $trop = $this->aCandidate(
            missionId: 901,
            userId: 21,
            arrivesAt: self::OPENING + AttackAdmissionSelector::MAX_WINDOW_SECONDS
        );

        $verdict = $this->selectAttack(
            [AttackCandidateGroup::ofASingleFleet($juste), AttackCandidateGroup::ofASingleFleet($trop)],
            $this->aFoundingGroup()
        );

        $this->assertCount(1, $verdict->admitted());
        $this->assertSame(CombatReasonCode::RallyWindowLimit, $verdict->refused()[0]->refusal);
    }

    /**
     * Le proprietaire de la cible occupe un des cinq, et aucune flotte.
     *
     * ## Une decision, pas une caracterisation
     *
     * Il n'existe **aucune union defensive** dans le code : `AcsDefendMission` ne touche jamais
     * `union_id`. Ce budget-ci n'avait donc aucun comportement a preserver — il est cree ici, et il
     * laisse au plus quatre joueurs exterieurs.
     */
    public function testTheTargetOwnerTakesOneOfTheFivePlayersAndNoFleet(): void
    {
        $candidates = [];

        foreach ([41, 42, 43, 44, 45] as $rang => $joueur) {
            $candidates[] = $this->aCandidate(
                missionId: 1_000 + $rang,
                userId: $joueur,
                arrivesAt: self::OPENING + 1 + $rang,
                mission: CombatMissionKind::AcsDefend
            );
        }

        $verdict = (new DefensiveAdmissionSelector())->select(7, self::TARGET_BODY, self::OPENING, $candidates);

        $this->assertCount(4, $verdict->admitted(), 'The target owner did not take one of the five slots.');
        $this->assertSame(CombatReasonCode::PlayerLimitReached, $verdict->refused()[0]->refusal);
    }

    /**
     * Un retour ou un deploiement personnel ne consomme aucun emplacement defensif.
     */
    public function testAReturnOrPersonalDeploymentConsumesNoDefensiveSlot(): void
    {
        $candidates = [
            $this->aCandidate(missionId: 1_100, userId: 7, arrivesAt: self::OPENING + 5, mission: CombatMissionKind::Transport, leg: FlightLeg::Return),
            $this->aCandidate(missionId: 1_101, userId: 7, arrivesAt: self::OPENING + 6, mission: CombatMissionKind::Deployment),
            $this->aCandidate(missionId: 1_102, userId: 41, arrivesAt: self::OPENING + 7, mission: CombatMissionKind::AcsDefend),
        ];

        $verdict = (new DefensiveAdmissionSelector())->select(7, self::TARGET_BODY, self::OPENING, $candidates);

        $this->assertCount(1, $verdict->admitted(), 'Only a real ACS Defence consumes a slot.');
        $this->assertCount(2, $verdict->refused());

        foreach ($verdict->refused() as $refus) {
            $this->assertSame(CombatReasonCode::NoCombatEffect, $refus->refusal);
        }
    }

    /**
     * Plusieurs defenses d'un meme allie consomment plusieurs flottes et un seul joueur.
     */
    public function testSeveralDefencesOfOneAllyConsumeSeveralFleetsButOnePlayer(): void
    {
        $candidates = [];

        for ($i = 1; $i <= 16; $i++) {
            $candidates[] = $this->aCandidate(
                missionId: 1_200 + $i,
                userId: 41,
                arrivesAt: self::OPENING + $i,
                mission: CombatMissionKind::AcsDefend
            );
        }

        // Une dix-septieme dépasse le plafond de flottes, pas celui de joueurs.
        $candidates[] = $this->aCandidate(
            missionId: 1_300,
            userId: 41,
            arrivesAt: self::OPENING + 20,
            mission: CombatMissionKind::AcsDefend
        );

        $verdict = (new DefensiveAdmissionSelector())->select(7, self::TARGET_BODY, self::OPENING, $candidates);

        $this->assertCount(16, $verdict->admitted());
        $this->assertSame(CombatReasonCode::FleetLimitReached, $verdict->refused()[0]->refusal);
    }

    /**
     * La fermeture vient des deux camps a la fois.
     */
    public function testTheClosingComesFromBothSidesAtOnce(): void
    {
        $attaque = $this->selectAttack(
            [AttackCandidateGroup::ofASingleFleet($this->aCandidate(missionId: 1, userId: 21, arrivesAt: self::OPENING + 10))],
            $this->aFoundingGroup()
        );

        $defense = (new DefensiveAdmissionSelector())->select(7, self::TARGET_BODY, self::OPENING, [
            $this->aCandidate(missionId: 2, userId: 41, arrivesAt: self::OPENING + 30, mission: CombatMissionKind::AcsDefend),
        ]);

        // La derniere admise des deux camps, plus un pas de temps. **C'est la regle qui existait
        // deja** : j'avais ecrit un second coordinateur avant de m'apercevoir que
        // `CombatRallyWindow::closesAt()` faisait exactement cela, en mieux — il ecarte les arrivees
        // qui ne tiendraient pas sous le plafond au lieu de les rogner.
        $this->assertSame(
            self::OPENING + 31,
            CombatRallyWindow::closesAt(self::OPENING, $this->admittedArrivalsOf($attaque, $defense))
        );

        // Aucune candidate admise nulle part : fermeture et ouverture coincident.
        $vide = $this->selectAttack([], $this->aFoundingGroup());
        $videDefense = (new DefensiveAdmissionSelector())->select(7, self::TARGET_BODY, self::OPENING, []);

        $this->assertSame(
            self::OPENING,
            CombatRallyWindow::closesAt(self::OPENING, $this->admittedArrivalsOf($vide, $videDefense))
        );
    }

    /**
     * La fenetre ne depasse jamais soixante secondes.
     */
    public function testTheWindowNeverExceedsSixtySeconds(): void
    {
        $tardive = $this->selectAttack(
            [
                AttackCandidateGroup::ofASingleFleet(
                    $this->aCandidate(missionId: 1, userId: 21, arrivesAt: self::OPENING + 59)
                ),
            ],
            $this->aFoundingGroup()
        );

        $videDefense = (new DefensiveAdmissionSelector())->select(7, self::TARGET_BODY, self::OPENING, []);

        // A 59 secondes, l'arrivee tient **exactement** sous le plafond : 59 + un pas de temps = 60.
        // La fenetre se ferme donc au plafond, et pas une seconde plus tard.
        $this->assertSame(
            self::OPENING + AttackAdmissionSelector::MAX_WINDOW_SECONDS,
            CombatRallyWindow::closesAt(self::OPENING, $this->admittedArrivalsOf($tardive, $videDefense))
        );

        // Une seconde de plus, et elle ne tient plus : `closesAt()` l'ecarte au lieu de la rogner,
        // et la fenetre se ferme a l'instant meme de son ouverture.
        $this->assertSame(
            self::OPENING,
            CombatRallyWindow::closesAt(self::OPENING, [self::OPENING + AttackAdmissionSelector::MAX_WINDOW_SECONDS])
        );
    }

    /**
     * Une candidate refusee ne prolonge jamais la fenetre.
     */
    public function testARefusedCandidateNeverExtendsTheWindow(): void
    {
        $verdict = $this->selectAttack(
            [
                AttackCandidateGroup::ofASingleFleet(
                    $this->aCandidate(missionId: 1, userId: 21, arrivesAt: self::OPENING + 10)
                ),
                // Etrangere a l'alliance, et bien plus tardive.
                AttackCandidateGroup::ofASingleFleet(
                    $this->aCandidate(missionId: 2, userId: 99, arrivesAt: self::OPENING + 50, allianceId: 999)
                ),
            ],
            $this->aFoundingGroup()
        );

        $this->assertSame(
            self::OPENING + 10,
            $verdict->latestAdmittedArrival(),
            'A refused candidate was allowed to delay a combat it does not take part in.'
        );
    }

    /**
     * Une admission porte une raison de refus, ou elle est admise. Jamais les deux.
     */
    public function testAnAdmissionCarriesAReasonOrIsAdmittedButNeverBoth(): void
    {
        $groupe = AttackCandidateGroup::ofASingleFleet($this->aCandidate(missionId: 1, userId: 21, arrivesAt: self::OPENING + 5));

        $this->assertTrue(GroupAdmission::admit($groupe)->admitted);
        $this->assertNull(GroupAdmission::admit($groupe)->refusal);
        $this->assertFalse(GroupAdmission::refuse($groupe, CombatReasonCode::RallyClosed)->admitted);
        $this->assertSame(
            CombatReasonCode::RallyClosed,
            GroupAdmission::refuse($groupe, CombatReasonCode::RallyClosed)->refusal
        );
    }

    /**
     * Le budget canonique est celui de la base : seize flottes, cinq joueurs.
     */
    public function testTheCanonicalBudgetIsTheOneInTheDatabase(): void
    {
        $this->assertSame(16, AdmissionBudget::canonical()->maxFleets);
        $this->assertSame(5, AdmissionBudget::canonical()->maxPlayers);
    }

    /**
     * Un retour ou un genre non combattant n'est pas un refus : c'est une contradiction.
     *
     * La matrice ne delegue au selecteur attaquant que les allers `Attack`, `AcsAttack` et
     * `MoonDestruction`. Une autre forme ne peut donc pas arriver ici sur un chemin sain.
     *
     * **La rendre sous `NoCombatEffect` etait tentant** — la phrase est vraie, le joueur lirait
     * quelque chose de sense, sa flotte repartirait. C'est precisement ce qui la rendait dangereuse :
     * un defaut d'integration aurait disparu derriere un message anodin.
     */
    public function testAContradictoryShapeIsStoppedRatherThanRefused(): void
    {
        $formes = [
            'retour' => $this->aCandidate(missionId: 803, userId: 21, arrivesAt: self::OPENING + 5, leg: FlightLeg::Return),
            'transport' => $this->aCandidate(missionId: 804, userId: 21, arrivesAt: self::OPENING + 5, mission: CombatMissionKind::Transport),
        ];

        foreach ($formes as $quoi => $candidate) {
            try {
                $this->selectAttack(
                    [AttackCandidateGroup::ofASingleFleet($candidate)],
                    $this->aFoundingGroup()
                );

                $this->fail("A « {$quoi} » was refused with a player-facing reason instead of stopping the run.");
            } catch (ContradictoryAdmissionInput $arret) {
                $this->assertStringContainsString(
                    'selecteur d admission attaquante',
                    $arret->getMessage(),
                    'The contradiction does not name the mechanism that received it.'
                );
            }
        }
    }

    /**
     * Le meme groupe rend toujours la meme raison, quel que soit l'ordre de ses missions.
     *
     * ## Le defaut que cet essai ferme
     *
     * `whyItCannotJoin()` rendait le **premier defaut rencontre** en parcourant les missions du
     * groupe. `AttackCandidateGroup` ne canonise pas cet ordre : deux permutations du meme groupe
     * ACS donnaient donc deux messages differents au joueur, selon l'ordre dans lequel la base avait
     * rendu les lignes.
     *
     * Le groupe porte ici **deux defauts simultanes** — une candidate rappelee, une autre hors
     * alliance — precisement pour que l'ordre puisse departager. La priorite documentee tranche :
     * l'etat de la candidate passe avant l'alliance.
     */
    public function testTheSameGroupAlwaysReadsTheSameReason(): void
    {
        $rappelee = $this->aCandidate(missionId: 901, userId: 21, arrivesAt: self::OPENING + 5, recalled: true);
        $etrangere = $this->aCandidate(missionId: 902, userId: 99, arrivesAt: self::OPENING + 5, allianceId: 999);

        $raisons = [];

        foreach ([[$rappelee, $etrangere], [$etrangere, $rappelee]] as $ordre) {
            $verdict = $this->selectAttack(
                [new AttackCandidateGroup('union:7', $ordre)],
                $this->aFoundingGroup()
            );

            $raisons[] = $verdict->refused()[0]->refusal;
        }

        $this->assertSame(
            $raisons[0],
            $raisons[1],
            'Two permutations of one ACS group produced two different reasons for the same player.'
        );

        $this->assertSame(
            CombatReasonCode::CandidateRecalled,
            $raisons[0],
            'The priority order changed: the state of a candidate must come before her alliance.'
        );
    }

    /**
     * Une impossibilite permanente passe avant une limite circonstancielle.
     *
     * Un allie qui n'aurait **jamais** pu entrer ne doit pas lire « trop tard » : il aurait cru
     * qu'un depart plus tot aurait suffi. L'ordre etait fautif — le plafond temporel etait teste
     * avant la cible non renforcable et avant l'alliance.
     */
    public function testAPermanentImpossibilityIsReadBeforeATimingOne(): void
    {
        // Hors alliance **et** arrivee au plafond : deux defauts, et c'est l'alliance qui compte.
        $candidate = $this->aCandidate(
            missionId: 903,
            userId: 99,
            arrivesAt: self::OPENING + AttackAdmissionSelector::MAX_WINDOW_SECONDS,
            allianceId: 999
        );

        $verdict = $this->selectAttack(
            [AttackCandidateGroup::ofASingleFleet($candidate)],
            $this->aFoundingGroup()
        );

        $this->assertSame(
            CombatReasonCode::AllianceNotEligible,
            $verdict->refused()[0]->refusal,
            'A player who could never have joined was told he was too late.'
        );
    }

    /**
     * Les arrivees des candidates admises des deux camps.
     *
     * @return array<int, int>
     */
    private function admittedArrivalsOf(AdmissionVerdict $attack, AdmissionVerdict $defence): array
    {
        $arrivees = [];

        foreach ([$attack, $defence] as $verdict) {
            foreach ($verdict->admitted() as $groupe) {
                $arrivees[] = $groupe->scheduledArrivalAt();
            }
        }

        return $arrivees;
    }

    /**
     * Le verdict d'une selection attaquante.
     *
     * @param array<int, AttackCandidateGroup> $candidates
     */
    private function selectAttack(array $candidates, FoundingGroup $founding): AdmissionVerdict
    {
        return (new AttackAdmissionSelector())->select($founding, self::TARGET_BODY, ActorKind::Player, self::OPENING, $candidates);
    }

    /**
     * Le groupe fondateur de reference : le createur seul, dans son alliance.
     */
    private function aFoundingGroup(): FoundingGroup
    {
        return new FoundingGroup(
            self::CREATOR,
            self::ALLIANCE,
            [$this->aCandidate(missionId: 1, userId: self::CREATOR, arrivesAt: self::OPENING)],
            AdmissionBudget::canonical()
        );
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
}
