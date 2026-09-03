<?php

namespace Tests\Unit\Combat;

use InvalidArgumentException;
use LogicException;
use OGame\Combat\Exceptions\CorruptedFrozenMoonPlan;
use OGame\Combat\Exceptions\UnknownMoonDestructionRuleVersion;
use OGame\Combat\MoonDestruction\FrozenMoonDestructionAttempt;
use OGame\Combat\MoonDestruction\FrozenMoonDestructionPlan;
use OGame\Combat\MoonDestruction\FrozenMoonIdentity;
use OGame\Combat\MoonDestruction\MoonDestructionCandidate;
use OGame\Combat\MoonDestruction\MoonDestructionOdds;
use OGame\Combat\MoonDestruction\MoonDestructionOutcome;
use OGame\Combat\MoonDestruction\MoonDestructionRule;
use OGame\Combat\MoonDestruction\MoonDestructionRuleRegistry;
use OGame\Combat\MoonDestruction\MoonDestructionRuleV1;
use Tests\UnitTestCase;

/**
 * Le gel des tentatives de destruction : ordre, isolement, et hasard qui ne bouge plus.
 *
 * ## Ce que le tirage injecte permet de prouver
 *
 * Un tirage reel rendrait ces essais aleatoires, donc inutiles. Ici il est fourni, et **compte ses
 * appels** : c'est ce qui rend verifiable la regle « une mission sautee ne consomme aucun tirage ».
 * Sans ce compteur, une mission sautee qui tirerait quand meme passerait inapercue — et decalerait
 * le hasard de toutes les suivantes.
 *
 * ## Une lune de 8100
 *
 * Elle revient partout parce qu'elle donne des nombres ronds : 90 de racine, donc 10 % de chance de
 * destruction par etoile de la mort et 45 % de chance de tout perdre. Les tirages choisis tombent
 * loin des bornes, sauf la ou l'essai porte precisement sur une borne.
 */
class FrozenMoonDestructionPlanTest extends UnitTestCase
{
    /**
     * Les tirages rendus par le tirage injecte, dans l'ordre.
     *
     * @var array<int, int>
     */
    private array $tiragesAServir = [];

    /**
     * Combien de fois le tirage a ete appele.
     */
    private int $tiragesConsommes = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tiragesAServir = [];
        $this->tiragesConsommes = 0;
    }

    /**
     * L'ordre ne depend pas de l'ordre de lecture en base.
     */
    public function testTheOrderDoesNotDependOnHowTheDatabaseReturnedTheRows(): void
    {
        $candidates = [
            new MoonDestructionCandidate(30, 1_000, 5),
            new MoonDestructionCandidate(10, 900, 5),
            new MoonDestructionCandidate(20, 900, 5),
        ];

        $premier = $this->freeze($candidates, tirages: [99, 99, 99, 99, 99, 99]);
        $second = $this->freeze(array_reverse($candidates), tirages: [99, 99, 99, 99, 99, 99]);

        $this->assertSame(
            [10, 20, 30],
            array_map(
                static fn (FrozenMoonDestructionAttempt $t): int => $t->fleetMissionId,
                $premier->attempts
            ),
            'The order is not (scheduled arrival, then mission id).'
        );

        $this->assertSame(
            $premier->toFrozenFacts(),
            $second->toFrozenFacts(),
            'Reading the rows in another order produced another plan.'
        );
    }

    /**
     * La premiere echoue, la suivante peut tenter.
     */
    public function testWhenTheFirstAttemptFailsTheNextOneMayStillTry(): void
    {
        $plan = $this->freeze(
            [
                new MoonDestructionCandidate(1, 100, 1),
                new MoonDestructionCandidate(2, 200, 1),
            ],
            tirages: [99, 99, 1, 99]
        );

        $this->assertSame(MoonDestructionOutcome::AttemptFailed, $plan->attempts[0]->outcome);
        $this->assertSame(MoonDestructionOutcome::MoonDestroyed, $plan->attempts[1]->outcome);
        $this->assertSame(4, $this->tiragesConsommes, 'Two real attempts consume exactly four draws.');
        $this->assertTrue($plan->destroysTheMoon());
    }

    /**
     * Une fois la lune detruite, les suivantes ne tirent plus rien.
     *
     * **La garantie la plus facile a perdre.** Une mission sautee qui consommerait quand meme un
     * tirage decalerait le hasard des suivantes, et deux rejeux du meme plan divergeraient.
     */
    public function testOnceTheMoonIsGoneTheFollowingAttemptsDrawNothing(): void
    {
        $plan = $this->freeze(
            [
                new MoonDestructionCandidate(1, 100, 1),
                new MoonDestructionCandidate(2, 200, 1),
                new MoonDestructionCandidate(3, 300, 1),
            ],
            tirages: [1, 99]
        );

        $this->assertSame(MoonDestructionOutcome::MoonDestroyed, $plan->attempts[0]->outcome);
        $this->assertSame(MoonDestructionOutcome::TargetAlreadyDestroyed, $plan->attempts[1]->outcome);
        $this->assertSame(MoonDestructionOutcome::TargetAlreadyDestroyed, $plan->attempts[2]->outcome);

        $this->assertSame(2, $this->tiragesConsommes, 'A skipped attempt consumed a draw.');

        foreach ([1, 2] as $rang) {
            $this->assertNull($plan->attempts[$rang]->destructionRoll);
            $this->assertNull($plan->attempts[$rang]->deathstarLossRoll);
            $this->assertSame(0, $plan->attempts[$rang]->extraDeathstarLosses);
        }
    }

    /**
     * Sans etoile de la mort survivante, aucune tentative.
     */
    public function testAMissionWithNoSurvivingDeathstarNeverTries(): void
    {
        $plan = $this->freeze([new MoonDestructionCandidate(1, 100, 0)], tirages: []);

        $this->assertSame(MoonDestructionOutcome::NoSurvivingDeathstar, $plan->attempts[0]->outcome);
        $this->assertSame(0, $this->tiragesConsommes);
        $this->assertFalse($plan->destroysTheMoon());
    }

    /**
     * Un camp attaquant battu ne tente rien, quelles que soient ses survivantes.
     */
    public function testALosingAttackSideTriesNothing(): void
    {
        $plan = $this->freeze(
            [new MoonDestructionCandidate(1, 100, 50)],
            tirages: [],
            attackSideWon: false
        );

        $this->assertSame(MoonDestructionOutcome::AttackSideDidNotWin, $plan->attempts[0]->outcome);
        $this->assertSame(0, $this->tiragesConsommes);
    }

    /**
     * Les etoiles de la mort d'une mission ne gonflent jamais la probabilite d'une autre.
     *
     * Trois missions d'une etoile ne doivent pas tirer comme une mission de trois : la mise en
     * commun des flottes vaut pour le combat, jamais pour l'effet special.
     */
    public function testDeathstarCountsAreIsolatedPerMission(): void
    {
        $plan = $this->freeze(
            [
                new MoonDestructionCandidate(1, 100, 1),
                new MoonDestructionCandidate(2, 200, 4),
            ],
            tirages: [99, 99, 99, 99]
        );

        $this->assertSame(1, $plan->attempts[0]->survivingDeathstars);
        $this->assertSame(4, $plan->attempts[1]->survivingDeathstars);

        // Et la chance gelee de chacune suit sa propre quantite : 10 % contre 20 %.
        $this->assertEqualsWithDelta(10.0, $plan->attempts[0]->destructionChance, 0.001);
        $this->assertEqualsWithDelta(20.0, $plan->attempts[1]->destructionChance, 0.001);
    }

    /**
     * La perte d'une mission n'affecte aucune autre.
     */
    public function testOneMissionsLossDoesNotTouchAnother(): void
    {
        // Le premier tirage de perte gagne (1 <= 45), le second perd (99 > 45).
        $plan = $this->freeze(
            [
                new MoonDestructionCandidate(1, 100, 3),
                new MoonDestructionCandidate(2, 200, 7),
            ],
            tirages: [99, 1, 99, 99]
        );

        $this->assertSame(3, $plan->attempts[0]->extraDeathstarLosses);
        $this->assertSame(0, $plan->attempts[1]->extraDeathstarLosses);
        $this->assertSame(7, $plan->attempts[1]->survivingDeathstars);
    }

    /**
     * Le plan relu est exactement celui qui a ete ecrit.
     *
     * C'est la raison d'etre du gel : deux heures plus tard, rien ne se recalcule. Conserver une
     * graine ne suffirait pas — PHP et Rust ne consomment pas forcement les tirages de la meme
     * facon, et le resultat relu pourrait differer de celui qui a ete calcule.
     */
    public function testThePlanReadBackIsTheOneThatWasWritten(): void
    {
        $plan = $this->freeze(
            [
                new MoonDestructionCandidate(1, 100, 2),
                new MoonDestructionCandidate(2, 200, 0),
                new MoonDestructionCandidate(3, 300, 3),
            ],
            tirages: [99, 1, 42, 99]
        );

        $consommesAuGel = $this->tiragesConsommes;

        $relu = FrozenMoonDestructionPlan::fromFrozenFacts($plan->toFrozenFacts());

        $this->assertSame($plan->toFrozenFacts(), $relu->toFrozenFacts());
        $this->assertSame($plan->destroysTheMoon(), $relu->destroysTheMoon());

        // **La relecture ne retire aucun tirage.** L'application a l'echeance relit ; elle ne rejoue
        // pas le hasard.
        $this->assertSame($consommesAuGel, $this->tiragesConsommes);
    }

    /**
     * La lune reste lisible apres sa destruction.
     *
     * Si l'identite n'etait qu'une cle etrangere, la destruction rendrait le rapport muet : le
     * joueur lirait qu'une lune a ete detruite sans savoir laquelle.
     */
    public function testTheMoonStaysReadableAfterItIsGone(): void
    {
        $plan = $this->freeze([new MoonDestructionCandidate(1, 100, 1)], tirages: [1, 99]);

        $relu = FrozenMoonDestructionPlan::fromFrozenFacts($plan->toFrozenFacts());

        $this->assertTrue($relu->destroysTheMoon());
        $this->assertSame(4_242, $relu->moon->moonId);
        $this->assertSame('1:2:3', $relu->moon->coordinates);
        $this->assertSame('Lune de Keven', $relu->moon->name);
        $this->assertSame(8_100, $relu->moon->diameter);
    }

    /**
     * Le diametre vivant peut changer : le plan gele ne bouge pas.
     */
    public function testTheLivingDiameterCannotChangeAFrozenPlan(): void
    {
        $plan = $this->freeze([new MoonDestructionCandidate(1, 100, 1)], tirages: [99, 99]);

        $faits = $plan->toFrozenFacts();

        // Une lune plus petite donnerait une tout autre chance : 2500 a 50 pour racine, donc 50 %.
        $this->assertEqualsWithDelta(10.0, MoonDestructionOdds::destructionChance(8_100, 1), 0.001);
        $this->assertEqualsWithDelta(50.0, MoonDestructionOdds::destructionChance(2_500, 1), 0.001);

        $relu = FrozenMoonDestructionPlan::fromFrozenFacts($faits);

        $this->assertEqualsWithDelta(10.0, $relu->attempts[0]->destructionChance, 0.001);
        $this->assertSame(8_100, $relu->moon->diameter);
    }

    /**
     * Un plan reste lisible apres qu'une v2 est devenue la version courante.
     *
     * C'est ce que le registre garantit, et ce qu'une constante ne garantirait pas : comparer la
     * version persistee a la version courante rendrait illisibles, d'un coup, tous les plans en
     * cours le jour ou la constante change.
     */
    public function testAnOldPlanStaysReadableAfterANewerRuleBecomesCurrent(): void
    {
        $v2 = $this->aSecondRule();
        $registre = MoonDestructionRuleRegistry::of([new MoonDestructionRuleV1(), $v2], $v2->version());

        $this->assertSame($v2->version(), $registre->currentVersion());

        // La version persistee **selectionne** l'implementation ; elle n'est jamais comparee a la
        // version courante.
        $this->assertInstanceOf(
            MoonDestructionRuleV1::class,
            $registre->forVersion(MoonDestructionRuleV1::VERSION)
        );

        $plan = $this->freeze([new MoonDestructionCandidate(1, 100, 1)], tirages: [99, 99]);

        $this->assertSame(MoonDestructionRuleV1::VERSION, $plan->ruleVersion);
        $this->assertSame(
            $plan->toFrozenFacts(),
            FrozenMoonDestructionPlan::fromFrozenFacts($plan->toFrozenFacts())->toFrozenFacts()
        );
    }

    /**
     * Une version inconnue est refusee, jamais remplacee par la version courante.
     */
    public function testAnUnknownRuleVersionIsRefusedRatherThanReplaced(): void
    {
        $this->expectException(UnknownMoonDestructionRuleVersion::class);

        MoonDestructionRuleRegistry::default()->forVersion('moon_destruction_odds_v99');
    }

    /**
     * Deux implementations ne peuvent pas se reclamer de la meme version.
     */
    public function testTwoImplementationsMayNotClaimTheSameVersion(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MoonDestructionRuleRegistry::of(
            [new MoonDestructionRuleV1(), new MoonDestructionRuleV1()],
            MoonDestructionRuleV1::VERSION
        );
    }

    /**
     * Un schema inconnu est refuse.
     *
     * Le relire au petit bonheur donnerait des champs manquants pour des valeurs nulles, et un
     * resultat different de celui qui a ete calcule.
     */
    public function testAnUnknownSchemaIsRefused(): void
    {
        $plan = $this->freeze([new MoonDestructionCandidate(1, 100, 0)], tirages: []);

        $faits = $plan->toFrozenFacts();
        $faits['schema'] = FrozenMoonDestructionPlan::SCHEMA + 1;

        $this->expectException(CorruptedFrozenMoonPlan::class);

        FrozenMoonDestructionPlan::fromFrozenFacts($faits);
    }

    /**
     * La cle d'idempotence distingue le combat, la mission et la nature de l'effet.
     */
    public function testTheIdempotencyKeyDistinguishesCombatMissionAndEffect(): void
    {
        $plan = $this->freeze([new MoonDestructionCandidate(7, 100, 0)], tirages: []);

        $this->assertSame(
            '99:7:' . FrozenMoonDestructionPlan::ATTEMPT_KEY,
            $plan->idempotencyKeyOf($plan->attempts[0])
        );
    }

    /**
     * Un plan qui detruirait la lune deux fois est refuse.
     */
    public function testAPlanThatDestroysTheMoonTwiceIsRefused(): void
    {
        $detruit = static fn (int $mission, int $rang): array => (new FrozenMoonDestructionAttempt(
            $mission,
            $rang,
            1,
            10.0,
            45.0,
            10,
            45,
            1,
            99,
            MoonDestructionOutcome::MoonDestroyed,
            0
        ))->toFrozenFacts();

        $this->expectException(LogicException::class);

        FrozenMoonDestructionPlan::fromFrozenFacts([
            'schema' => FrozenMoonDestructionPlan::SCHEMA,
            'combat_instance_id' => 1,
            'rule_version' => MoonDestructionRuleV1::VERSION,
            'moon' => $this->aMoon()->toFrozenFacts(),
            'attempts' => [$detruit(1, 1), $detruit(2, 2)],
        ]);
    }

    /**
     * Une tentative qui n'a pas eu lieu ne peut pas porter de tirage.
     */
    public function testASkippedAttemptMayNotCarryADraw(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FrozenMoonDestructionAttempt(1, 1, 1, 10.0, 45.0, 10, 45, 50, 50, MoonDestructionOutcome::TargetAlreadyDestroyed, 0);
    }

    /**
     * Un resultat gele qui ne concorde pas avec son tirage est refuse.
     *
     * Sans ce controle, relire le plan et le recalculer donneraient deux reponses, et personne ne
     * saurait laquelle le joueur a vue.
     */
    public function testAFrozenOutcomeThatContradictsItsRollIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // 99 contre un seuil de 10 : la lune tient. Pretendre qu'elle a ete detruite est une
        // contradiction.
        new FrozenMoonDestructionAttempt(1, 1, 1, 10.0, 45.0, 10, 45, 99, 99, MoonDestructionOutcome::MoonDestroyed, 0);
    }

    /**
     * Les formules restent celles du jeu.
     *
     * Ce chantier deplace le moment du tirage, pas la probabilite.
     */
    public function testTheGameFormulasAreUnchanged(): void
    {
        $this->assertEqualsWithDelta(10.0, MoonDestructionOdds::destructionChance(8_100, 1), 0.001);
        $this->assertEqualsWithDelta(45.0, MoonDestructionOdds::deathstarLossChance(8_100), 0.001);

        // La comparaison est inclusive, comme dans le chemin immediat.
        $this->assertTrue(MoonDestructionOdds::succeeds(10, 10.0), 'The comparison is no longer inclusive.');
        $this->assertFalse(MoonDestructionOdds::succeeds(11, 10.0));

        // Une lune enorme ne descend jamais sous zero, une lune minuscule ne depasse jamais cent.
        $this->assertSame(0.0, MoonDestructionOdds::destructionChance(1_000_000, 100));
        $this->assertSame(100.0, MoonDestructionOdds::destructionChance(1, 100));
    }

    /**
     * Le seuil entier selectionne exactement les memes tirages que la chance flottante.
     *
     * ## Pourquoi persister un entier
     *
     * Le tirage est un entier de 1 a 100, et la reussite est `tirage <= chance`. Pour un tirage
     * entier, c'est exactement `tirage <= plancher(chance)`. Le seuil est donc l'information
     * **observable** — et il se relit sans perte, la ou une chance flottante peut differer du
     * dernier bit apres un aller-retour JSON.
     *
     * Le balayage porte sur toute la plage des tirages et sur des chances aux decimales variees :
     * un seul ecart ferait tomber l'essai.
     */
    public function testTheIntegerThresholdSelectsExactlyTheSameRolls(): void
    {
        $ecarts = [];

        foreach ([0.0, 0.4, 1.0, 9.99, 10.0, 14.142, 45.0, 99.5, 100.0] as $chance) {
            $seuil = MoonDestructionOdds::thresholdFor($chance);

            for ($tirage = MoonDestructionOdds::ROLL_MINIMUM; $tirage <= MoonDestructionOdds::ROLL_MAXIMUM; $tirage++) {
                if (MoonDestructionOdds::succeeds($tirage, $chance) !== MoonDestructionOdds::succeedsAgainst($tirage, $seuil)) {
                    $ecarts[] = $tirage . ' contre ' . $chance;
                }
            }
        }

        $this->assertSame([], $ecarts, 'The integer threshold no longer selects the same rolls.');

        // Et les bornes du seuil restent dans la plage des tirages.
        $this->assertSame(0, MoonDestructionOdds::thresholdFor(-50.0));
        $this->assertSame(100, MoonDestructionOdds::thresholdFor(1_000.0));
    }

    /**
     * Les frontieres du diametre, la ou la formule change de regime.
     *
     * `(100 - racine(diametre))` devient nul a **10 000** et negatif au-dela : la borne ramene alors
     * a zero, et aucune etoile de la mort ne peut plus rien. Ce n'est pas une decroissance douce,
     * c'est un mur, et il vaut d'etre fige.
     */
    public function testTheDiameterBoundariesWhereTheFormulaChangesRegime(): void
    {
        // Juste avant : une chance minuscule mais reelle, dont le seuil entier vaut deja zero.
        $this->assertGreaterThan(0.0, MoonDestructionOdds::destructionChance(9_999, 1));
        $this->assertSame(0, MoonDestructionOdds::thresholdFor(MoonDestructionOdds::destructionChance(9_999, 1)));

        // Exactement 10 000 : racine 100, donc chance nulle.
        $this->assertSame(0.0, MoonDestructionOdds::destructionChance(10_000, 100));

        // Au-dela : la valeur brute est negative, et la borne la ramene a zero.
        $this->assertSame(0.0, MoonDestructionOdds::destructionChance(10_001, 100));
        $this->assertSame(0.0, MoonDestructionOdds::destructionChance(1_000_000, 100));

        // **Une chance nulle ne detruit jamais**, meme au tirage le plus favorable. Le comportement
        // est celui du chemin immediat : `random_int` ne rend jamais zero.
        $plan = $this->freeze(
            [new MoonDestructionCandidate(1, 100, 5)],
            tirages: [MoonDestructionOdds::ROLL_MINIMUM, 99],
            moonDiameter: 10_000
        );

        $this->assertSame(MoonDestructionOutcome::AttemptFailed, $plan->attempts[0]->outcome);
        $this->assertSame(0, $plan->attempts[0]->destructionThreshold);
        $this->assertFalse($plan->destroysTheMoon());
    }

    /**
     * Une lune dont l'identifiant est une chaine numerique n'est pas relue.
     *
     * ## Le defaut que cet essai ferme
     *
     * `FrozenMoonIdentity::fromFrozenFacts()` faisait `(int)$facts['moon_id']` : la chaine « 12 »
     * devenait 12, le flottant 12.7 devenait 12, `true` devenait 1. Le plan entre dans l'empreinte
     * et les cles d'idempotence ; un fait relu autrement qu'ecrit rend un rejeu different de
     * l'original, sans que rien ne le dise.
     */
    public function testAMoonWithANumericStringIdentifierIsRefused(): void
    {
        $faits = $this->freeze([new MoonDestructionCandidate(1, 100, 2)], tirages: [99, 99])->toFrozenFacts();
        $faits['moon']['moon_id'] = (string)$faits['moon']['moon_id'];

        $this->expectException(CorruptedFrozenMoonPlan::class);

        FrozenMoonDestructionPlan::fromFrozenFacts($faits);
    }

    /**
     * Un plan dont l'identifiant de combat est un flottant n'est pas relu.
     */
    public function testAPlanWithAFloatCombatIdentifierIsRefused(): void
    {
        $faits = $this->freeze([new MoonDestructionCandidate(1, 100, 2)], tirages: [99, 99])->toFrozenFacts();
        $faits['combat_instance_id'] = (float)$faits['combat_instance_id'];

        $this->expectException(CorruptedFrozenMoonPlan::class);

        FrozenMoonDestructionPlan::fromFrozenFacts($faits);
    }

    /**
     * Une tentative dont la chance est une chaine numerique n'est pas relue.
     *
     * Une chance ecrite `100` revient `100` du decodeur JSON, pas `100.0` : un entier est accepte.
     * Une chaine « 0.5 », elle, ne vient pas du meme ecrivain.
     */
    public function testAnAttemptWithANumericStringChanceIsRefused(): void
    {
        $faits = $this->freeze([new MoonDestructionCandidate(1, 100, 2)], tirages: [99, 99])->toFrozenFacts();
        $faits['attempts'][0]['destruction_chance'] = (string)$faits['attempts'][0]['destruction_chance'];

        $this->expectException(CorruptedFrozenMoonPlan::class);

        FrozenMoonDestructionPlan::fromFrozenFacts($faits);
    }

    /**
     * Un tirage relu comme flottant n'est pas un tirage.
     */
    public function testAnAttemptWithAFloatRollIsRefused(): void
    {
        $faits = $this->freeze([new MoonDestructionCandidate(1, 100, 2)], tirages: [99, 99])->toFrozenFacts();
        $faits['attempts'][0]['destruction_threshold'] = 42.0;

        $this->expectException(CorruptedFrozenMoonPlan::class);

        FrozenMoonDestructionPlan::fromFrozenFacts($faits);
    }

    /**
     * Un schema ecrit en chaine n'est plus accepte par coercition.
     */
    public function testANumericStringSchemaIsRefused(): void
    {
        $faits = $this->freeze([new MoonDestructionCandidate(1, 100, 2)], tirages: [99, 99])->toFrozenFacts();
        $faits['schema'] = (string)$faits['schema'];

        $this->expectException(CorruptedFrozenMoonPlan::class);

        FrozenMoonDestructionPlan::fromFrozenFacts($faits);
    }

    /**
     * Gele un plan avec un tirage observable.
     *
     * @param array<int, MoonDestructionCandidate> $candidates
     * @param array<int, int> $tirages
     * @param bool $attackSideWon
     * @return FrozenMoonDestructionPlan
     */
    private function freeze(
        array $candidates,
        array $tirages,
        bool $attackSideWon = true,
        int $moonDiameter = 8_100,
    ): FrozenMoonDestructionPlan {
        $this->tiragesAServir = $tirages;
        $this->tiragesConsommes = 0;

        return FrozenMoonDestructionPlan::freeze(
            99,
            $this->aMoon($moonDiameter),
            $candidates,
            $attackSideWon,
            MoonDestructionRuleRegistry::default()->current(),
            function (): int {
                if ($this->tiragesAServir === []) {
                    $this->fail('The plan asked for more draws than the test provided.');
                }

                $this->tiragesConsommes++;

                return (int)array_shift($this->tiragesAServir);
            }
        );
    }

    /**
     * La lune des essais : 8100 de diametre, donc des nombres ronds.
     */
    private function aMoon(int $diameter = 8_100): FrozenMoonIdentity
    {
        return new FrozenMoonIdentity(4_242, '1:2:3', 'Lune de Keven', $diameter);
    }

    /**
     * Une seconde version de regle, qui n'existe que dans cet essai.
     *
     * Elle ne modifie aucune formule : elle sert seulement a montrer qu'une version courante
     * differente ne rend pas illisible un plan calcule sous l'ancienne.
     */
    private function aSecondRule(): MoonDestructionRule
    {
        return new class () implements MoonDestructionRule {
            public function version(): string
            {
                return 'moon_destruction_odds_v2';
            }

            public function destructionChance(int $moonDiameter, int $deathstarCount): float
            {
                return MoonDestructionOdds::destructionChance($moonDiameter, $deathstarCount);
            }

            public function deathstarLossChance(int $moonDiameter): float
            {
                return MoonDestructionOdds::deathstarLossChance($moonDiameter);
            }

            public function succeeds(int $roll, float $chance): bool
            {
                return MoonDestructionOdds::succeeds($roll, $chance);
            }

            public function thresholdFor(float $chance): int
            {
                return MoonDestructionOdds::thresholdFor($chance);
            }
        };
    }
}
