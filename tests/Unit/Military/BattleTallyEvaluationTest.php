<?php

namespace Tests\Unit\Military;

use OGame\Combat\Support\CombatParticipantKey;
use OGame\GameMissions\BattleEngine\Models\AttackerFleetResult;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameMissions\BattleEngine\Models\BattleResultRound;
use OGame\GameMissions\BattleEngine\Models\DefenderFleetResult;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Military\BattleTallyEvaluation;
use OGame\Military\BattleTallyFacts;
use OGame\Military\MilitaryValue;
use OGame\Services\ObjectService;
use Tests\TestCase;

/**
 * L evaluation d une bataille reglee d un bloc : detruits et perdus round par round, sur des faits fabriques.
 *
 * Prix des faits : chasseur leger 10 (poids 2, donc 20 par unite), lance-missiles 7 (poids 2, 14), petit transporteur 3
 * (poids 1, 3). Les nombres sont choisis pour que le partage exact, le plus grand reste et l egalite se voient.
 */
final class BattleTallyEvaluationTest extends TestCase
{
    private const array PRIX = ['light_fighter' => 10, 'rocket_launcher' => 7, 'small_cargo' => 3];

    /**
     * Les clefs viennent de la fabrique, jamais ecrites a la main (`CombatSchemaShapeTest` le garde).
     */
    private static function fleet(int $mission): string
    {
        return CombatParticipantKey::forFleet($mission);
    }

    private static function garnison(): string
    {
        return CombatParticipantKey::forPlanet(7);
    }

    /**
     * **Le partage suit les forces de chaque round, et les perdus sont les pertes definitives apres reparation.**
     *
     * Deux attaquantes de 10 et 30 chasseurs contre 20 lance-missiles. Round 1 : la garnison perd 6, les attaquantes
     * 4 et 6 ; round 2 : la garnison perd 9, les attaquantes 6 et 4. Cinq lance-missiles sont repares : deux au
     * round 1, trois au round 2 (prorata 6:9). Pertes definitives de la garnison : 4 puis 6, soit 56 puis 84.
     * Round 1, forces 200 contre 600 : 56 donne 14 et 42. Round 2, forces 120 contre 480 : 84 donne 16,8 et 67,2, soit
     * 17 et 67 par plus grand reste.
     */
    public function testDestroyedFollowsTheForcesOfEachRoundAndLostIsDefinitive(): void
    {
        $issue = (new BattleTallyEvaluation())->evaluate($this->deuxAttaquantesEtUneGarnison(), MilitaryValue::WEIGHTING_VERSION);

        $this->assertFalse($issue->isPending(), (string)$issue->detail);
        $this->assertSame([
            self::fleet(1) => ['owner' => 11, 'npc' => false, 'destroyed' => 14 + 17, 'lost' => 10 * 20],
            self::fleet(2) => ['owner' => 12, 'npc' => false, 'destroyed' => 42 + 67, 'lost' => 10 * 20],
            self::garnison() => ['owner' => 21, 'npc' => false, 'destroyed' => 10 * 20 + 10 * 20, 'lost' => 10 * 14],
        ], $issue->computed);

        $this->assertConservation($issue->computed, $this->deuxAttaquantesEtUneGarnison());
    }

    /**
     * **Un attaquant absent au debut d un round ne recoit rien de ce round.** La flotte 1 tombe entiere au round 1 :
     * les pertes de la garnison au round 2 vont toutes a la flotte 2, meme si la flotte 1 a tire au round 1.
     */
    public function testAParticipantGoneBeforeARoundGetsNothingFromIt(): void
    {
        $faits = $this->faits(
            participants: [
                $this->participant(self::fleet(1), BattleTallyFacts::SIDE_ATTACKER, 11, ['light_fighter' => 10], ['light_fighter' => 10]),
                $this->participant(self::fleet(2), BattleTallyFacts::SIDE_ATTACKER, 12, ['light_fighter' => 30], ['light_fighter' => 5]),
                $this->participant(self::garnison(), BattleTallyFacts::SIDE_DEFENDER, 21, ['rocket_launcher' => 20], ['rocket_launcher' => 12]),
            ],
            rounds: [
                [self::fleet(1) => ['light_fighter' => 10], self::fleet(2) => ['light_fighter' => 2], self::garnison() => ['rocket_launcher' => 4]],
                [self::fleet(2) => ['light_fighter' => 3], self::garnison() => ['rocket_launcher' => 8]],
            ],
            restants: [
                [self::fleet(1) => [], self::fleet(2) => ['light_fighter' => 28]],
                [self::fleet(1) => [], self::fleet(2) => ['light_fighter' => 25]],
            ],
        );

        $issue = (new BattleTallyEvaluation())->evaluate($faits, MilitaryValue::WEIGHTING_VERSION);

        $this->assertFalse($issue->isPending(), (string)$issue->detail);
        // Round 1 : 56 entre 200 et 600, soit 14 et 42. Round 2 : 112 a la flotte 2 seule.
        $this->assertSame(14, $issue->computed[self::fleet(1)]['destroyed']);
        $this->assertSame(42 + 112, $issue->computed[self::fleet(2)]['destroyed']);
        $this->assertConservation($issue->computed, $faits);
    }

    /**
     * **Le plus grand reste, et l egalite par la clef.** Un total impair entre deux forces egales : la clef la plus
     * petite recoit l unite de plus.
     */
    public function testATieBetweenEqualForcesGoesToTheSmallestParticipantKey(): void
    {
        $faits = $this->faits(
            participants: [
                $this->participant(self::fleet(9), BattleTallyFacts::SIDE_ATTACKER, 11, ['light_fighter' => 10], []),
                $this->participant(self::fleet(2), BattleTallyFacts::SIDE_ATTACKER, 12, ['light_fighter' => 10], []),
                $this->participant(self::garnison(), BattleTallyFacts::SIDE_DEFENDER, 21, ['small_cargo' => 5], ['small_cargo' => 1]),
            ],
            rounds: [[self::garnison() => ['small_cargo' => 1]]],
            restants: [[self::fleet(2) => ['light_fighter' => 10], self::fleet(9) => ['light_fighter' => 10]]],
        );

        $issue = (new BattleTallyEvaluation())->evaluate($faits, MilitaryValue::WEIGHTING_VERSION);

        $this->assertFalse($issue->isPending(), (string)$issue->detail);
        $this->assertSame(2, $issue->computed[self::fleet(2)]['destroyed'], 'La clef la plus petite ne recoit pas le reste.');
        $this->assertSame(1, $issue->computed[self::fleet(9)]['destroyed']);
    }

    /**
     * **Les pertes anterieures au premier round sortent des forces du round 1 et entrent dans les perdus.**
     *
     * L emplacement existe pour la manoeuvre de Hamill, le jour ou le moteur nommera sa victime : la flotte 1 a deja
     * perdu 2 chasseurs avant le round 1, ses forces valent 160 et non 200, et ses pertes definitives 10 et non 8.
     */
    public function testLossesBeforeTheFirstRoundLeaveTheForcesOfRoundOneAndCountAsLost(): void
    {
        $faits = $this->faits(
            participants: [
                $this->participant(self::fleet(1), BattleTallyFacts::SIDE_ATTACKER, 11, ['light_fighter' => 10], ['light_fighter' => 10]),
                $this->participant(self::fleet(2), BattleTallyFacts::SIDE_ATTACKER, 12, ['light_fighter' => 32], ['light_fighter' => 8]),
                $this->participant(self::garnison(), BattleTallyFacts::SIDE_DEFENDER, 21, ['rocket_launcher' => 20], ['rocket_launcher' => 4]),
            ],
            rounds: [
                [self::fleet(1) => ['light_fighter' => 4], self::fleet(2) => ['light_fighter' => 4], self::garnison() => ['rocket_launcher' => 4]],
                [self::fleet(1) => ['light_fighter' => 4], self::fleet(2) => ['light_fighter' => 4]],
            ],
            restants: [
                [self::fleet(1) => ['light_fighter' => 4], self::fleet(2) => ['light_fighter' => 28]],
                [self::fleet(1) => [], self::fleet(2) => ['light_fighter' => 24]],
            ],
            anterieures: [self::fleet(1) => ['light_fighter' => 2]],
        );

        $issue = (new BattleTallyEvaluation())->evaluate($faits, MilitaryValue::WEIGHTING_VERSION);

        $this->assertFalse($issue->isPending(), (string)$issue->detail);
        // Round 1 : 56 entre 160 et 640, soit 11,2 et 44,8 : 11 et 45. Sans les pertes anterieures : 14 et 42.
        $this->assertSame(11, $issue->computed[self::fleet(1)]['destroyed']);
        $this->assertSame(45, $issue->computed[self::fleet(2)]['destroyed']);
        $this->assertSame(10 * 20, $issue->computed[self::fleet(1)]['lost'], 'Les pertes anterieures au premier round ne comptent pas en perdus.');
        $this->assertConservation($issue->computed, $faits);
    }

    /**
     * **Un compte PNJ est calcule, jamais credite** : ses pertes font les detruits de ses adversaires.
     */
    public function testAnNpcParticipantIsComputedButNotCredited(): void
    {
        $faits = $this->deuxAttaquantesEtUneGarnison(garnisonNpc: true);

        $issue = (new BattleTallyEvaluation())->evaluate($faits, MilitaryValue::WEIGHTING_VERSION);

        $this->assertFalse($issue->isPending(), (string)$issue->detail);
        $this->assertArrayHasKey(self::garnison(), $issue->computed);
        $this->assertTrue($issue->computed[self::garnison()]['npc']);
        $this->assertSame([self::fleet(1), self::fleet(2)], array_keys($issue->credits()), 'La garnison PNJ a recu un credit, ou un attaquant en a perdu un.');
        $this->assertSame(31 + 109, $issue->credits()[self::fleet(1)]['destroyed'] + $issue->credits()[self::fleet(2)]['destroyed'], 'Les pertes du PNJ ne sont plus creditees a ses adversaires.');
        $this->assertSame($issue->computed[self::garnison()]['lost'], 31 + 109, 'Conservation : les detruits des attaquantes ne font pas les perdus du PNJ.');
    }

    public function testEveryAnomalyLeavesTheWholeBattlePendingWithItsReason(): void
    {
        $evaluation = new BattleTallyEvaluation();
        $version = MilitaryValue::WEIGHTING_VERSION;

        $cas = [
            'Hamill sans victime' => [$this->deuxAttaquantesEtUneGarnison(hamill: true), BattleTallyEvaluation::HAMILL_VICTIM_UNNAMED, 'Hamill'],
            'proprietaire absent' => [$this->deuxAttaquantesEtUneGarnison(proprietaireDeLaFlotte1: null), BattleTallyEvaluation::PARTICIPANT_WITHOUT_OWNER, self::fleet(1)],
            'unite hors catalogue' => [$this->deuxAttaquantesEtUneGarnison(departDeLaFlotte1: ['light_fighter' => 10, 'canon_fictif' => 1], prix: self::PRIX + ['canon_fictif' => 9]), BattleTallyEvaluation::UNKNOWN_UNIT_FAMILY, 'canon_fictif'],
            'prix absent' => [$this->deuxAttaquantesEtUneGarnison(prix: ['light_fighter' => 10]), BattleTallyEvaluation::INCOHERENT_BATTLE, 'prix brut absent pour : rocket_launcher'],
            'rounds qui ne recouvrent pas les pertes' => [$this->deuxAttaquantesEtUneGarnison(pertesDeLaFlotte1: ['light_fighter' => 9]), BattleTallyEvaluation::INCOHERENT_BATTLE, 'ne recouvrent pas'],
            'restants figes differents' => [$this->deuxAttaquantesEtUneGarnison(restantsApresRound1: [self::fleet(1) => ['light_fighter' => 5], self::fleet(2) => ['light_fighter' => 24]]), BattleTallyEvaluation::INCOHERENT_BATTLE, 'restants que le moteur a figes'],
            'reparation au-dela des pertes' => [$this->deuxAttaquantesEtUneGarnison(reparees: ['rocket_launcher' => 16]), BattleTallyEvaluation::INCOHERENT_BATTLE, 'reparation de rocket_launcher'],
            'deux participants sous la meme clef' => [$this->deuxAttaquantesEtUneGarnison(clefDeLaFlotte2: self::fleet(1)), BattleTallyEvaluation::AMBIGUOUS_PARTICIPANT, self::fleet(1)],
        ];

        foreach ($cas as $nom => [$faits, $raison, $extrait]) {
            $issue = $evaluation->evaluate($faits, $version);

            $this->assertTrue($issue->isPending(), "Le cas « $nom » a ete evalue au lieu d attendre.");
            $this->assertSame($raison, $issue->reason, "Le cas « $nom » n attend pas pour la bonne raison.");
            $this->assertStringContainsString($extrait, $issue->detail, "Le detail du cas « $nom » ne dit pas ce qui manque.");
            $this->assertSame([], $issue->computed, "Le cas « $nom » a rendu des valeurs partielles.");
            $this->assertSame([], $issue->credits());
        }
    }

    /**
     * **La manoeuvre nommee** : l Etoile est une perte anterieure au premier round de la victime — hors des forces du
     * round 1, hors du partage des rounds —, comptee **une fois** en perdus, et sa valeur va a l auteur **en un
     * evenement nomme**, jamais dans les detruits de la bataille.
     */
    public function testANamedManoeuvreCreditsTheStarOnceToTheAuthorAndNeverThroughTheRounds(): void
    {
        $faits = $this->faitsSousHamill();

        $issue = (new BattleTallyEvaluation())->evaluate($faits, MilitaryValue::WEIGHTING_VERSION);

        $this->assertFalse($issue->isPending(), (string)$issue->detail);
        // Prix des faits : Etoile 1000, poids 2 → 2000. Perdus de la garnison : 15 lance-missiles − 5 repares = 140, plus l Etoile.
        $this->assertSame(10 * 14 + 2000, $issue->computed[self::garnison()]['lost'], 'L Etoile n est pas comptee exactement une fois en perdus.');
        $this->assertSame(14 + 17, $issue->computed[self::fleet(1)]['destroyed'], 'Les detruits de la bataille portent une part de l Etoile.');
        $this->assertSame(42 + 67, $issue->computed[self::fleet(2)]['destroyed']);
        $this->assertSame(['author' => self::fleet(1), 'owner' => 11, 'npc' => false, 'destroyed' => 2000], $issue->hamill);
        $this->assertSame(['author' => self::fleet(1), 'owner' => 11, 'destroyed' => 2000], $issue->hamillCredit());
        // Conservation avec l evenement nomme : detruits des rounds + Etoile nommee = perdus de la garnison.
        $this->assertSame($issue->computed[self::garnison()]['lost'], 31 + 109 + 2000);
        $this->assertConservation($issue->computed, $faits);
    }

    public function testANamedManoeuvreOfAnNpcAuthorIsComputedButNotCredited(): void
    {
        $faits = $this->faitsSousHamill(auteurNpc: true);

        $issue = (new BattleTallyEvaluation())->evaluate($faits, MilitaryValue::WEIGHTING_VERSION);

        $this->assertFalse($issue->isPending(), (string)$issue->detail);
        $this->assertNotNull($issue->hamill);
        $this->assertTrue($issue->hamill['npc']);
        $this->assertNull($issue->hamillCredit(), 'Un auteur PNJ a ete credite de la manoeuvre.');
    }

    public function testANamedManoeuvreThatDoesNotFitTheFactsLeavesTheBattlePending(): void
    {
        $evaluation = new BattleTallyEvaluation();

        foreach ([
            'victime qui n est pas un defenseur' => [$this->faitsSousHamill(victime: self::fleet(2)), 'n est pas une flotte defensive'],
            'auteur qui n est pas un attaquant' => [$this->faitsSousHamill(auteur: self::garnison()), 'n est pas une flotte attaquante'],
            'pertes anterieures qui ne sont pas une Etoile' => [$this->faitsSousHamill(anterieures: [self::garnison() => ['rocket_launcher' => 1]]), 'exactement une Etoile'],
            'nommee sans etre declenchee' => [$this->faitsSousHamill(declenchee: false), 'sans manoeuvre declenchee'],
        ] as $nom => [$faits, $extrait]) {
            $issue = $evaluation->evaluate($faits, MilitaryValue::WEIGHTING_VERSION);

            $this->assertTrue($issue->isPending(), "Le cas « $nom » a ete evalue.");
            $this->assertSame(BattleTallyEvaluation::INCOHERENT_BATTLE, $issue->reason, "Le cas « $nom » n attend pas pour la bonne raison.");
            $this->assertStringContainsString($extrait, $issue->detail);
        }
    }

    public function testTheFactsOfTheSecondSchemaSurviveTheirStorageAndTheFirstSchemaStillReads(): void
    {
        $faits = $this->faitsSousHamill();
        $document = $faits->toStorage();
        $this->assertSame(2, $document['schema']);

        $relus = BattleTallyFacts::fromStorage(json_decode((string)json_encode($document), true));
        $this->assertNotNull($relus);
        $this->assertEquals($faits, $relus);

        $ancien = $this->deuxAttaquantesEtUneGarnison(hamill: true)->toStorage();
        $ancien['schema'] = 1;
        unset($ancien['hamill']);
        $relu = BattleTallyFacts::fromStorage($ancien);
        $this->assertNotNull($relu, 'Un document du schema 1 ne se relit plus.');
        $this->assertNull($relu->hamill);
        $this->assertTrue($relu->hamillTriggered);
        $this->assertSame(BattleTallyEvaluation::HAMILL_VICTIM_UNNAMED, (new BattleTallyEvaluation())->evaluate($relu, MilitaryValue::WEIGHTING_VERSION)->reason);

        foreach ([
            'schema 2 sans le champ hamill' => array_diff_key($document, ['hamill' => true]),
            'manoeuvre sans regle' => ['hamill' => ['victim' => self::garnison(), 'author' => self::fleet(1)]] + $document,
            'regle inconnue' => ['hamill' => ['victim' => self::garnison(), 'author' => self::fleet(1), 'rule' => 'v9']] + $document,
        ] as $nom => $deforme) {
            $this->assertNull(BattleTallyFacts::fromStorage($deforme), "Un document deforme (« $nom ») a ete relu.");
        }
    }

    public function testLossesWithoutAnyOpposingForceAreAnIncoherence(): void
    {
        // Les deux attaquantes tombent au round 1 ; au round 2 la garnison perd encore : personne n a pu tirer.
        $faits = $this->faits(
            participants: [
                $this->participant(self::fleet(1), BattleTallyFacts::SIDE_ATTACKER, 11, ['light_fighter' => 10], ['light_fighter' => 10]),
                $this->participant(self::garnison(), BattleTallyFacts::SIDE_DEFENDER, 21, ['rocket_launcher' => 20], ['rocket_launcher' => 3]),
            ],
            rounds: [
                [self::fleet(1) => ['light_fighter' => 10], self::garnison() => ['rocket_launcher' => 1]],
                [self::garnison() => ['rocket_launcher' => 2]],
            ],
            restants: [[self::fleet(1) => []], [self::fleet(1) => []]],
        );

        $issue = (new BattleTallyEvaluation())->evaluate($faits, MilitaryValue::WEIGHTING_VERSION);

        $this->assertTrue($issue->isPending());
        $this->assertSame(BattleTallyEvaluation::INCOHERENT_BATTLE, $issue->reason);
        $this->assertStringContainsString('sans qu aucune force', $issue->detail);
    }

    public function testTheFactsSurviveTheirStorageAndAMalformedDocumentGivesNothing(): void
    {
        $faits = $this->deuxAttaquantesEtUneGarnison();
        $document = $faits->toStorage();

        $relus = BattleTallyFacts::fromStorage(json_decode((string)json_encode($document), true));
        $this->assertNotNull($relus);
        $this->assertEquals($faits, $relus, 'Les faits relus ne sont pas ceux qui ont ete ecrits.');
        $this->assertSame('battle:combat:12:' . self::fleet(1), $faits->eventKeyFor(self::fleet(1)));

        foreach ([
            'schema inconnu' => ['schema' => 3] + $document,
            'espace inconnu' => ['space' => 'ailleurs'] + $document,
            'proprietaire zero' => ['participants' => [['owner' => 0] + $document['participants'][0]]] + $document,
            'quantite negative' => ['repaired' => ['rocket_launcher' => -1]] + $document,
            'rounds qui ne sont pas une liste' => ['rounds' => ['a' => []]] + $document,
            'clef de participant mal formee' => ['pre_round_losses' => ['nimporte' => []]] + $document,
            'rien' => null,
        ] as $nom => $deforme) {
            $this->assertNull(BattleTallyFacts::fromStorage($deforme), "Un document deforme (« $nom ») a ete relu.");
        }
    }

    public function testTheFactsAreReadFromTheFrozenResultWithTheirOwnersKeysAndPrices(): void
    {
        $chasseur = ObjectService::getUnitObjectByMachineName('light_fighter');
        $lanceur = ObjectService::getUnitObjectByMachineName('rocket_launcher');

        $resultat = new BattleResult();
        $resultat->hamillManoeuvreTriggered = false;
        $resultat->repairedDefenses = $this->collection([$lanceur, 2]);

        $premiere = new AttackerFleetResult(41, 11, $this->collection([$chasseur, 10]));
        $premiere->unitsLost = $this->collection([$chasseur, 3]);
        $ephemere = new AttackerFleetResult(0, 12, $this->collection([$chasseur, 5]));
        $ephemere->unitsLost = $this->collection([$chasseur, 5]);
        $resultat->attackerFleetResults = [$premiere, $ephemere];

        $garnison = new DefenderFleetResult(0, 21, $this->collection([$lanceur, 20]));
        $garnison->unitsLost = $this->collection([$lanceur, 6]);
        $renfort = new DefenderFleetResult(77, 22, $this->collection([$chasseur, 4]));
        $renfort->unitsLost = new UnitCollection();
        $resultat->defenderFleetResults = [$garnison, $renfort];

        $round = new BattleResultRound();
        $round->lossesInRoundByParticipant = [
            self::fleet(41) => $this->collection([$chasseur, 3]),
            CombatParticipantKey::EPHEMERAL_ATTACKER => $this->collection([$chasseur, 5]),
            self::garnison() => $this->collection([$lanceur, 6]),
        ];
        $round->attackerShipsPerFleet = [41 => $this->collection([$chasseur, 7]), 0 => new UnitCollection()];
        $resultat->rounds = [$round];

        $faits = BattleTallyFacts::fromBattleResult($resultat, self::garnison(), BattleTallyFacts::SPACE_COMBAT, 12, 1_704_000_000, [21]);

        $this->assertSame([
            ['key' => self::fleet(41), 'side' => 'attaquant', 'owner' => 11, 'npc' => false, 'start' => ['light_fighter' => 10], 'lost' => ['light_fighter' => 3]],
            ['key' => CombatParticipantKey::EPHEMERAL_ATTACKER, 'side' => 'attaquant', 'owner' => 12, 'npc' => false, 'start' => ['light_fighter' => 5], 'lost' => ['light_fighter' => 5]],
            ['key' => self::garnison(), 'side' => 'defenseur', 'owner' => 21, 'npc' => true, 'start' => ['rocket_launcher' => 20], 'lost' => ['rocket_launcher' => 6]],
            ['key' => self::fleet(77), 'side' => 'defenseur', 'owner' => 22, 'npc' => false, 'start' => ['light_fighter' => 4], 'lost' => []],
        ], $faits->participants);
        $this->assertSame(['rocket_launcher' => 2], $faits->repaired);
        $this->assertSame([[CombatParticipantKey::EPHEMERAL_ATTACKER => ['light_fighter' => 5], self::fleet(41) => ['light_fighter' => 3], self::garnison() => ['rocket_launcher' => 6]]], $faits->rounds);
        $this->assertSame([[CombatParticipantKey::EPHEMERAL_ATTACKER => [], self::fleet(41) => ['light_fighter' => 7]]], $faits->attackerShipsPerRound);
        $this->assertSame([], $faits->preRoundLosses);
        $this->assertSame([
            'light_fighter' => (int)ObjectService::getObjectRawPrice('light_fighter')->sum(),
            'rocket_launcher' => (int)ObjectService::getObjectRawPrice('rocket_launcher')->sum(),
        ], $faits->prices, 'Les prix bruts ne sont pas ceux du catalogue au moment du fait.');
        $this->assertSame(1_704_000_000, $faits->echeance);

        // Et ces faits s evaluent : l attaquante ephemere a un proprietaire, il est credite.
        $issue = (new BattleTallyEvaluation())->evaluate($faits, MilitaryValue::WEIGHTING_VERSION);
        $this->assertFalse($issue->isPending(), (string)$issue->detail);
        $this->assertArrayHasKey(CombatParticipantKey::EPHEMERAL_ATTACKER, $issue->credits(), 'L attaquante sans mission a perdu son proprietaire.');
        $this->assertArrayNotHasKey(self::garnison(), $issue->credits(), 'La garnison PNJ a ete creditee.');
    }

    /**
     * **Conservation** : les detruits d un camp font exactement les perdus de l autre — aux pertes anterieures au
     * premier round pres, qui sont des perdus sans detruits dans cette evaluation : leur credit appartient a
     * l evenement nomme qui les a causees (la manoeuvre de Hamill, quand le moteur nommera sa victime).
     *
     * @param array<string, array{owner: int|null, npc: bool, destroyed: int, lost: int}> $valeurs
     */
    private function assertConservation(array $valeurs, BattleTallyFacts $faits): void
    {
        $camps = [];
        $anterieurs = [BattleTallyFacts::SIDE_ATTACKER => 0, BattleTallyFacts::SIDE_DEFENDER => 0];

        foreach ($faits->participants as $participant) {
            $camps[$participant['key']] = $participant['side'];

            foreach ($faits->preRoundLosses[$participant['key']] ?? [] as $nom => $nombre) {
                $anterieurs[$participant['side']] += $faits->prices[$nom] * $nombre * (int)MilitaryValue::weightOf($nom, MilitaryValue::WEIGHTING_VERSION);
            }
        }

        $detruits = [BattleTallyFacts::SIDE_ATTACKER => 0, BattleTallyFacts::SIDE_DEFENDER => 0];
        $perdus = [BattleTallyFacts::SIDE_ATTACKER => 0, BattleTallyFacts::SIDE_DEFENDER => 0];

        foreach ($valeurs as $clef => $v) {
            $detruits[$camps[$clef]] += $v['destroyed'];
            $perdus[$camps[$clef]] += $v['lost'];
        }

        $this->assertSame($perdus[BattleTallyFacts::SIDE_DEFENDER], $detruits[BattleTallyFacts::SIDE_ATTACKER] + $anterieurs[BattleTallyFacts::SIDE_DEFENDER], 'Les detruits des attaquants ne font pas les perdus des defenseurs.');
        $this->assertSame($perdus[BattleTallyFacts::SIDE_ATTACKER], $detruits[BattleTallyFacts::SIDE_DEFENDER] + $anterieurs[BattleTallyFacts::SIDE_ATTACKER], 'Les detruits des defenseurs ne font pas les perdus des attaquants.');
    }

    /**
     * @param array<string, int> $departDeLaFlotte1
     * @param array<string, int> $pertesDeLaFlotte1
     * @param array<string, int> $reparees
     * @param array<string, array<string, int>>|null $restantsApresRound1
     * @param array<string, int>|null $prix
     */
    private function deuxAttaquantesEtUneGarnison(
        bool $hamill = false,
        int|null $proprietaireDeLaFlotte1 = 11,
        array $departDeLaFlotte1 = ['light_fighter' => 10],
        array $pertesDeLaFlotte1 = ['light_fighter' => 10],
        array $reparees = ['rocket_launcher' => 5],
        array|null $restantsApresRound1 = null,
        array|null $prix = null,
        string|null $clefDeLaFlotte2 = null,
        bool $garnisonNpc = false,
    ): BattleTallyFacts {
        $clefDeLaFlotte2 ??= self::fleet(2);

        return $this->faits(
            participants: [
                $this->participant(self::fleet(1), BattleTallyFacts::SIDE_ATTACKER, $proprietaireDeLaFlotte1, $departDeLaFlotte1, $pertesDeLaFlotte1),
                $this->participant($clefDeLaFlotte2, BattleTallyFacts::SIDE_ATTACKER, 12, ['light_fighter' => 30], ['light_fighter' => 10]),
                $this->participant(self::garnison(), BattleTallyFacts::SIDE_DEFENDER, 21, ['rocket_launcher' => 20], ['rocket_launcher' => 15], $garnisonNpc),
            ],
            rounds: [
                [self::fleet(1) => ['light_fighter' => 4], $clefDeLaFlotte2 => ['light_fighter' => 6], self::garnison() => ['rocket_launcher' => 6]],
                [self::fleet(1) => ['light_fighter' => 6], $clefDeLaFlotte2 => ['light_fighter' => 4], self::garnison() => ['rocket_launcher' => 9]],
            ],
            restants: [
                $restantsApresRound1 ?? [self::fleet(1) => ['light_fighter' => 6], $clefDeLaFlotte2 => ['light_fighter' => 24]],
                [self::fleet(1) => [], $clefDeLaFlotte2 => ['light_fighter' => 20]],
            ],
            reparees: $reparees,
            hamill: $hamill,
            prix: $prix,
        );
    }

    /**
     * La bataille de base, la manoeuvre nommee : la garnison portait une Etoile, prise avant le round 1 par la
     * flotte 1. Ses pertes definitives la comptent ; aucun round ne la porte.
     *
     * @param array<string, array<string, int>>|null $anterieures
     */
    private function faitsSousHamill(string|null $victime = null, string|null $auteur = null, array|null $anterieures = null, bool $declenchee = true, bool $auteurNpc = false): BattleTallyFacts
    {
        $victime ??= self::garnison();
        $auteur ??= self::fleet(1);

        return new BattleTallyFacts(
            BattleTallyFacts::SPACE_COMBAT,
            12,
            self::garnison(),
            1_704_000_000,
            $declenchee,
            [
                $this->participant(self::fleet(1), BattleTallyFacts::SIDE_ATTACKER, 11, ['light_fighter' => 10], ['light_fighter' => 10], $auteurNpc),
                $this->participant(self::fleet(2), BattleTallyFacts::SIDE_ATTACKER, 12, ['light_fighter' => 30], ['light_fighter' => 10]),
                $this->participant(self::garnison(), BattleTallyFacts::SIDE_DEFENDER, 21, ['rocket_launcher' => 20, 'deathstar' => 1], ['rocket_launcher' => 15, 'deathstar' => 1]),
            ],
            ['rocket_launcher' => 5],
            [
                [self::fleet(1) => ['light_fighter' => 4], self::fleet(2) => ['light_fighter' => 6], self::garnison() => ['rocket_launcher' => 6]],
                [self::fleet(1) => ['light_fighter' => 6], self::fleet(2) => ['light_fighter' => 4], self::garnison() => ['rocket_launcher' => 9]],
            ],
            [
                [self::fleet(1) => ['light_fighter' => 6], self::fleet(2) => ['light_fighter' => 24]],
                [self::fleet(1) => [], self::fleet(2) => ['light_fighter' => 20]],
            ],
            $anterieures ?? [self::garnison() => ['deathstar' => 1]],
            self::PRIX + ['deathstar' => 1000],
            ['victim' => $victime, 'author' => $auteur, 'rule' => 'v3'],
        );
    }

    /**
     * @param list<array{key: string, side: string, owner: int|null, npc: bool, start: array<string, int>, lost: array<string, int>}> $participants
     * @param list<array<string, array<string, int>>> $rounds
     * @param list<array<string, array<string, int>>> $restants
     * @param array<string, int> $reparees
     * @param array<string, array<string, int>> $anterieures
     * @param array<string, int>|null $prix
     */
    private function faits(array $participants, array $rounds, array $restants, array $reparees = [], bool $hamill = false, array $anterieures = [], array|null $prix = null): BattleTallyFacts
    {
        return new BattleTallyFacts(BattleTallyFacts::SPACE_COMBAT, 12, self::garnison(), 1_704_000_000, $hamill, $participants, $reparees, $rounds, $restants, $anterieures, $prix ?? self::PRIX);
    }

    /**
     * @param array<string, int> $depart
     * @param array<string, int> $pertes
     * @return array{key: string, side: string, owner: int|null, npc: bool, start: array<string, int>, lost: array<string, int>}
     */
    private function participant(string $clef, string $camp, int|null $proprietaire, array $depart, array $pertes, bool $npc = false): array
    {
        return ['key' => $clef, 'side' => $camp, 'owner' => $proprietaire, 'npc' => $npc, 'start' => $depart, 'lost' => $pertes];
    }

    /**
     * @param array{0: \OGame\GameObjects\Models\UnitObject, 1: int} ...$entrees
     */
    private function collection(array ...$entrees): UnitCollection
    {
        $collection = new UnitCollection();

        foreach ($entrees as [$objet, $nombre]) {
            $collection->addUnit($objet, $nombre);
        }

        return $collection;
    }
}
