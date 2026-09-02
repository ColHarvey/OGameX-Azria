<?php

namespace Tests\Unit\Combat;

use InvalidArgumentException;
use OGame\Combat\Causality\CausalEventOrder;
use OGame\Combat\Causality\CausalEventOrderV1;
use OGame\Combat\Enums\CombatEventType;
use OGame\Combat\Support\EffectOrderKey;
use Tests\UnitTestCase;

/**
 * L'ordre des effets doit etre total, stable, et le rester quand on ajoutera des evenements.
 *
 * Sans ordre total, deux evenements de la meme seconde seraient departages par le worker qui
 * prend le verrou en premier — c'est-a-dire par le hasard de la charge serveur. Rejouer les memes
 * evenements donnerait alors un autre resultat.
 */
class EffectOrderKeyTest extends UnitTestCase
{
    /**
     * Le rang d'un genre n'appartient plus a l'enumeration.
     *
     * Il vivait sur `CombatEventType::rank()`. Deux essais y verifiaient qu'il etait strictement
     * positif et unique — deux proprietes qui appartiennent desormais a la **regle versionnee**,
     * et que `CausalEventOrderTest` verifie pour chaque version connue.
     *
     * Ce qui reste ici est ce que la cle garantit d'elle-meme : elle refuse un rang nul, quelle
     * que soit la regle qui le lui donne.
     */
    public function testTheKeyRefusesARankOfZeroWhateverTheRuleSays(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EffectOrderKey::forEvent(1_000, CombatEventType::FleetArrival, 1, new class () implements CausalEventOrder {
            public function version(): string
            {
                return 'causal_event_order_zero_test';
            }

            public function rankOf(CombatEventType $type): int
            {
                return 0;
            }
        });
    }

    /**
     * Une barriere se place avant tout evenement de la meme seconde.
     */
    public function testABarrierComesBeforeEveryEventOfTheSameSecond(): void
    {
        $barriere = EffectOrderKey::barrierAt(1_000, new CausalEventOrderV1());

        $this->assertTrue($barriere->isBarrier());

        foreach (CombatEventType::cases() as $type) {
            $evenement = EffectOrderKey::forEvent(1_000, $type, 1, new CausalEventOrderV1());

            $this->assertTrue(
                $barriere->isBefore($evenement),
                "A barrier did not come before a « {$type->value} » scheduled at the same second."
            );

            $this->assertFalse($evenement->isBarrier());
        }
    }

    /**
     * Deux identifiants egaux venant de tables differentes ne se confondent pas.
     *
     * Un missile numero douze et une arrivee numero douze viennent de tables distinctes, dont les
     * espaces se recouvrent. Sans le rang du type, ils seraient a egalite.
     */
    public function testEqualIdentifiersFromDifferentTablesDoNotCollide(): void
    {
        $arrivee = EffectOrderKey::forEvent(1_000, CombatEventType::FleetArrival, 12, new CausalEventOrderV1());
        $missile = EffectOrderKey::forEvent(1_000, CombatEventType::MissileImpact, 12, new CausalEventOrderV1());

        $this->assertFalse($arrivee->equals($missile), 'A fleet arrival and a missile impact sharing an id were treated as one event.');
        $this->assertTrue($arrivee->isBefore($missile) xor $missile->isBefore($arrivee), 'Their order is not total.');
    }

    /**
     * Le tri rend le meme ordre, quel que soit l'ordre d'entree.
     *
     * C'est la propriete qui compte reellement : un worker qui traite les evenements dans le
     * desordre doit aboutir au meme classement logique.
     */
    public function testSortingYieldsTheSameOrderWhateverTheInputOrder(): void
    {
        $cles = [
            EffectOrderKey::forEvent(1_000, CombatEventType::QueueCompletion, 3, new CausalEventOrderV1()),
            EffectOrderKey::barrierAt(1_000, new CausalEventOrderV1()),
            EffectOrderKey::forEvent(999, CombatEventType::MissileImpact, 50, new CausalEventOrderV1()),
            EffectOrderKey::forEvent(1_000, CombatEventType::FleetArrival, 12, new CausalEventOrderV1()),
            EffectOrderKey::forEvent(1_000, CombatEventType::FleetArrival, 7, new CausalEventOrderV1()),
        ];

        $attendu = null;

        // Plusieurs melanges deterministes du meme ensemble.
        foreach ([[0, 1, 2, 3, 4], [4, 3, 2, 1, 0], [2, 0, 4, 1, 3], [1, 4, 0, 3, 2]] as $permutation) {
            $melange = array_map(static fn (int $rang): EffectOrderKey => $cles[$rang], $permutation);

            usort($melange, static fn (EffectOrderKey $a, EffectOrderKey $b): int => $a->compareTo($b));

            $signature = array_map(
                static fn (EffectOrderKey $k): string => $k->plannedAt . '/' . $k->typeRank . '/' . $k->sourceId,
                $melange
            );

            $attendu ??= $signature;

            $this->assertSame($attendu, $signature, 'Sorting the same events in another input order produced another logical order.');
        }

        // Et l'ordre obtenu est bien celui qu'on attend. Les rangs sont ceux de
        // `causal_event_order_v1` : chantier 2, missile 3, arrivee 4.
        $this->assertSame(
            ['999/3/50', '1000/0/0', '1000/2/3', '1000/4/7', '1000/4/12'],
            $attendu
        );
    }

    /**
     * Un evenement sans identifiant de source est refuse.
     */
    public function testAnEventWithoutASourceIdentifierIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EffectOrderKey::forEvent(1_000, CombatEventType::FleetArrival, 0, new CausalEventOrderV1());
    }

    /**
     * Les lois d'un ordre total, verifiees sur toutes les paires et tous les triplets.
     *
     * **Quatre permutations illustrent une propriete, elles ne la demontrent pas.** Un
     * comparateur peut rendre le bon resultat sur un echantillon et rester incoherent ailleurs —
     * typiquement en violant l'antisymetrie sur une egalite partielle, ce qu'un tri ne revele
     * qu'occasionnellement.
     *
     * Les trois lois sont donc verifiees directement :
     *
     * - **reflexivite** : une cle est egale a elle-meme ;
     * - **antisymetrie** : deux cles distinctes ne sont jamais egales, et leurs comparaisons sont
     *   de signes opposes ;
     * - **transitivite** : si a precede b et b precede c, alors a precede c.
     *
     * L'echantillon est **construit depuis `CombatEventType::cases()`** : l'ajout futur d'une
     * sorte d'evenement etendra ce test tout seul, sans que personne ait a y penser.
     */
    public function testTheOrderObeysTheLawsOfATotalOrder(): void
    {
        $cles = $this->everyKindOfKey();

        $this->assertGreaterThan(6, count($cles), 'The sample is too small to say anything about the order.');

        foreach ($cles as $a) {
            $this->assertSame(0, $a->compareTo($a), 'A key is not equal to itself.');

            foreach ($cles as $b) {
                $ab = $a->compareTo($b);
                $ba = $b->compareTo($a);

                if ($a->equals($b)) {
                    $this->assertSame(0, $ab, 'Two equal keys did not compare as equal.');

                    continue;
                }

                $this->assertNotSame(0, $ab, 'Two distinct keys compared as equal, so the order is not total.');
                $this->assertSame(
                    -($ab <=> 0),
                    $ba <=> 0,
                    'Comparing two keys in both directions did not give opposite signs.'
                );

                foreach ($cles as $c) {
                    if ($ab < 0 && $b->compareTo($c) < 0) {
                        $this->assertLessThan(0, $a->compareTo($c), 'The order is not transitive.');
                    }
                }
            }
        }
    }

    /**
     * Chaque axe de departage est exerce separement.
     *
     * Sans cela, une comparaison qui ignorerait un des trois niveaux passerait inapercue tant que
     * les deux autres suffisent a departager.
     */
    public function testEachTiebreakLevelIsExercisedOnItsOwn(): void
    {
        $type = CombatEventType::FleetArrival;

        // Heures differentes : seule l'heure decide.
        $this->assertTrue(
            EffectOrderKey::forEvent(999, CombatEventType::QueueCompletion, 999, new CausalEventOrderV1())
                ->isBefore(EffectOrderKey::forEvent(1_000, $type, 1, new CausalEventOrderV1())),
            'A later planned time was not ordered after an earlier one.'
        );

        // Meme heure, genres differents : le rang du genre decide. Sous `causal_event_order_v1`,
        // le missile precede l'arrivee — la flotte qui se pose voit la cible telle que le missile
        // l'a laissee.
        $this->assertTrue(
            EffectOrderKey::forEvent(1_000, CombatEventType::MissileImpact, 999, new CausalEventOrderV1())
                ->isBefore(EffectOrderKey::forEvent(1_000, CombatEventType::FleetArrival, 1, new CausalEventOrderV1())),
            'At the same second, the event kind did not decide.'
        );

        // Meme heure, meme type : l'identifiant decide.
        $this->assertTrue(
            EffectOrderKey::forEvent(1_000, $type, 1, new CausalEventOrderV1())->isBefore(EffectOrderKey::forEvent(1_000, $type, 2, new CausalEventOrderV1())),
            'At the same second and same type, the source identifier did not decide.'
        );

        // La barriere precede chaque type a la meme seconde.
        foreach (CombatEventType::cases() as $sorte) {
            $this->assertTrue(
                EffectOrderKey::barrierAt(1_000, new CausalEventOrderV1())->isBefore(EffectOrderKey::forEvent(1_000, $sorte, 1, new CausalEventOrderV1())),
                "The barrier did not come before a « {$sorte->value} » at the same second."
            );
        }
    }

    /**
     * De tres grands identifiants ne font pas deborder la comparaison.
     *
     * Un comparateur ecrit comme une soustraction — `$a - $b` — deborderait sur des entiers
     * proches de la limite d'un bigint et rendrait un ordre faux. La comparaison est
     * lexicographique, avec l'operateur combine, precisement pour cela.
     */
    public function testVeryLargeIdentifiersDoNotOverflowTheComparison(): void
    {
        $type = CombatEventType::FleetArrival;
        $tresGrand = PHP_INT_MAX;
        $presqueAussiGrand = PHP_INT_MAX - 1;

        $this->assertTrue(
            EffectOrderKey::forEvent(1_000, $type, $presqueAussiGrand, new CausalEventOrderV1())
                ->isBefore(EffectOrderKey::forEvent(1_000, $type, $tresGrand, new CausalEventOrderV1())),
            'Two identifiers near the bigint limit were ordered wrongly, which a subtraction-based comparison would do.'
        );

        $this->assertFalse(
            EffectOrderKey::forEvent(1_000, $type, $tresGrand, new CausalEventOrderV1())
                ->isBefore(EffectOrderKey::forEvent(1_000, $type, 1, new CausalEventOrderV1())),
            'A huge identifier was ordered before a small one.'
        );
    }

    /**
     * La comparaison rend un signe, jamais un ecart.
     *
     * **La difference avec une soustraction, et elle est reelle.** En PHP, `$a - $b` sur des
     * entiers positifs ne deborde pas — le resultat est promu en flottant — si bien qu'un
     * comparateur ecrit ainsi trierait correctement. Mais il rendrait des amplitudes arbitraires
     * la ou l'operateur combine rend un signe, et tout appelant qui se fierait a la valeur elle-
     * meme se retrouverait avec des nombres colossaux, voire un flottant la ou un entier est
     * attendu.
     *
     * Ce test fixe donc le contrat : `compareTo()` rend -1, 0 ou 1, et rien d'autre.
     */
    public function testTheComparisonYieldsASignAndNeverADifference(): void
    {
        $cles = $this->everyKindOfKey();

        foreach ($cles as $a) {
            foreach ($cles as $b) {
                $resultat = $a->compareTo($b);

                $this->assertIsInt($resultat, 'The comparison returned something other than an integer.');
                $this->assertContains(
                    $resultat,
                    [-1, 0, 1],
                    'The comparison returned ' . $resultat . ' instead of a sign, so it is a difference rather than a comparison.'
                );
            }
        }
    }

    /**
     * Plusieurs melanges reproductibles donnent tous le meme classement.
     *
     * Generateur local et graine fixe : aucun generateur global n'est touche, et un echec est
     * rejouable.
     */
    public function testSeveralReproducibleShufflesAllYieldTheSameOrder(): void
    {
        $cles = $this->everyKindOfKey();
        $graine = 20260902;
        $etat = $graine;

        $reference = $this->signatureOfSorted($cles);

        for ($melange = 0; $melange < 20; $melange++) {
            $desordre = $cles;

            // Melange de Fisher-Yates, avec un generateur congruentiel local.
            for ($index = count($desordre) - 1; $index > 0; $index--) {
                $etat = (1103515245 * $etat + 12345) % 2147483648;
                $tire = intdiv($etat, 65536) % ($index + 1);
                [$desordre[$index], $desordre[$tire]] = [$desordre[$tire], $desordre[$index]];
            }

            $this->assertSame(
                $reference,
                $this->signatureOfSorted($desordre),
                'Shuffle ' . $melange . ' (graine ' . $graine . ') produced a different order.'
            );
        }
    }

    /**
     * Une cle de chaque sorte, plus les barrieres, sur plusieurs secondes.
     *
     * Construite depuis `CombatEventType::cases()` pour que l'echantillon suive l'enumeration.
     *
     * @return array<int, EffectOrderKey>
     */
    private function everyKindOfKey(): array
    {
        $cles = [];

        foreach ([999, 1_000, 1_001] as $seconde) {
            $cles[] = EffectOrderKey::barrierAt($seconde, new CausalEventOrderV1());

            foreach (CombatEventType::cases() as $type) {
                foreach ([1, 12, 4_294_967_296] as $identifiant) {
                    $cles[] = EffectOrderKey::forEvent($seconde, $type, $identifiant, new CausalEventOrderV1());
                }
            }
        }

        return $cles;
    }

    /**
     * @param array<int, EffectOrderKey> $cles
     * @return array<int, string>
     */
    private function signatureOfSorted(array $cles): array
    {
        usort($cles, static fn (EffectOrderKey $a, EffectOrderKey $b): int => $a->compareTo($b));

        return array_map(
            static fn (EffectOrderKey $k): string => $k->plannedAt . '/' . $k->typeRank . '/' . $k->sourceId,
            $cles
        );
    }
}
