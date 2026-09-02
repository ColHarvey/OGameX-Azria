<?php

namespace Tests\Unit\Combat;

use InvalidArgumentException;
use LogicException;
use OGame\Combat\MoonDestruction\FrozenMoonDestructionAttempt;
use OGame\Combat\MoonDestruction\FrozenMoonDestructionPlan;
use OGame\Combat\MoonDestruction\MoonDestructionCandidate;
use OGame\Combat\MoonDestruction\MoonDestructionOdds;
use OGame\Combat\MoonDestruction\MoonDestructionOutcome;
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
     *
     * Heure d'arrivee planifiee, puis identifiant. Deux lectures dans un ordre different doivent
     * produire exactement le meme plan — sans quoi le hasard lui-meme dependrait de la base.
     */
    public function testTheOrderDoesNotDependOnHowTheDatabaseReturnedTheRows(): void
    {
        $candidates = [
            new MoonDestructionCandidate(30, 1_000, 5),
            new MoonDestructionCandidate(10, 900, 5),
            new MoonDestructionCandidate(20, 900, 5),
        ];

        $premier = $this->freeze($candidates, tirages: [50, 50, 50, 50, 50, 50]);
        $second = $this->freeze(array_reverse($candidates), tirages: [50, 50, 50, 50, 50, 50]);

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
        // Une lune de 8100 : 90 de racine, donc 10 % de chance par etoile de la mort. Avec une seule
        // etoile, un tirage de 99 echoue et un tirage de 1 reussit.
        $plan = $this->freeze(
            [
                new MoonDestructionCandidate(1, 100, 1),
                new MoonDestructionCandidate(2, 200, 1),
            ],
            tirages: [99, 99, 1, 99],
            moonDiameter: 8_100
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
            tirages: [1, 99],
            moonDiameter: 8_100
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
            tirages: [99, 99, 99, 99],
            moonDiameter: 8_100
        );

        $this->assertSame(1, $plan->attempts[0]->survivingDeathstars);
        $this->assertSame(4, $plan->attempts[1]->survivingDeathstars);

        // Et la probabilite de chacune suit sa propre quantite : 10 % contre 20 %.
        $this->assertEqualsWithDelta(10.0, MoonDestructionOdds::destructionChance(8_100, 1), 0.001);
        $this->assertEqualsWithDelta(20.0, MoonDestructionOdds::destructionChance(8_100, 4), 0.001);
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
            tirages: [99, 1, 42, 99],
            moonDiameter: 8_100
        );

        $relu = FrozenMoonDestructionPlan::fromFrozenFacts($plan->combatInstanceId, $plan->toFrozenFacts());

        $this->assertSame($plan->toFrozenFacts(), $relu->toFrozenFacts());
        $this->assertSame($plan->destroysTheMoon(), $relu->destroysTheMoon());

        // La relecture ne retire aucun tirage.
        $consommesAuGel = $this->tiragesConsommes;
        FrozenMoonDestructionPlan::fromFrozenFacts($plan->combatInstanceId, $plan->toFrozenFacts());
        $this->assertSame($consommesAuGel, $this->tiragesConsommes);
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
        $detruit = static fn (int $mission, int $rang): FrozenMoonDestructionAttempt => new FrozenMoonDestructionAttempt(
            $mission,
            $rang,
            1,
            8_100,
            FrozenMoonDestructionPlan::VERSION,
            1,
            99,
            MoonDestructionOutcome::MoonDestroyed,
            0
        );

        $this->expectException(LogicException::class);

        FrozenMoonDestructionPlan::fromFrozenFacts(1, [
            $detruit(1, 1)->toFrozenFacts(),
            $detruit(2, 2)->toFrozenFacts(),
        ]);
    }

    /**
     * Une tentative qui n'a pas eu lieu ne peut pas porter de tirage.
     */
    public function testASkippedAttemptMayNotCarryADraw(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FrozenMoonDestructionAttempt(
            1,
            1,
            1,
            8_100,
            FrozenMoonDestructionPlan::VERSION,
            50,
            50,
            MoonDestructionOutcome::TargetAlreadyDestroyed,
            0
        );
    }

    /**
     * Les formules restent celles du jeu.
     *
     * Ce chantier deplace le moment du tirage, pas la probabilite. Ces valeurs sont calculees a la
     * main depuis les formules d'OGameX : une lune de 8100 a 90 pour racine, donc 10 % de chance de
     * destruction par etoile de la mort et 45 % de chance de tout perdre.
     */
    public function testTheGameFormulasAreUnchanged(): void
    {
        $this->assertEqualsWithDelta(10.0, MoonDestructionOdds::destructionChance(8_100, 1), 0.001);
        $this->assertEqualsWithDelta(45.0, MoonDestructionOdds::deathstarLossChance(8_100), 0.001);

        // Les deux bornes du jeu, et la comparaison inclusive.
        $this->assertTrue(MoonDestructionOdds::succeeds(10, 10.0), 'The comparison is no longer inclusive.');
        $this->assertFalse(MoonDestructionOdds::succeeds(11, 10.0));

        // Une lune enorme ne descend jamais sous zero, une lune minuscule ne depasse jamais cent.
        $this->assertSame(0.0, MoonDestructionOdds::destructionChance(1_000_000, 100));
        $this->assertSame(100.0, MoonDestructionOdds::destructionChance(1, 100));
    }

    /**
     * Gele un plan avec un tirage observable.
     *
     * @param array<int, MoonDestructionCandidate> $candidates
     * @param array<int, int> $tirages
     * @param bool $attackSideWon
     * @param int $moonDiameter
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
            $candidates,
            $attackSideWon,
            $moonDiameter,
            function (): int {
                if ($this->tiragesAServir === []) {
                    $this->fail('The plan asked for more draws than the test provided.');
                }

                $this->tiragesConsommes++;

                return (int)array_shift($this->tiragesAServir);
            }
        );
    }
}
