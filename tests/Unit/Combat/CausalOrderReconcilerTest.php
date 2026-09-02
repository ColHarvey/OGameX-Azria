<?php

namespace Tests\Unit\Combat;

use InvalidArgumentException;
use LogicException;
use OGame\Combat\Causality\AppliedEffectReceipt;
use OGame\Combat\Causality\CausalAdmission;
use OGame\Combat\Causality\CausalEvent;
use OGame\Combat\Causality\CausalEventSliceClaim;
use OGame\Combat\Causality\CausalEventSource;
use OGame\Combat\Causality\CausallyReconciledSnapshot;
use OGame\Combat\Causality\CausalOrderReconciler;
use OGame\Combat\Causality\CausalWindow;
use OGame\Combat\Causality\DecisionOrder;
use OGame\Combat\Causality\OpeningProvenance;
use OGame\Combat\Causality\PartitionBarrier;
use OGame\Combat\Causality\ProtectedOpeningState;
use OGame\Combat\Causality\ReconciledEvent;
use OGame\Combat\Causality\VerifiedCompleteEventSlice;
use OGame\Combat\Enums\CombatEventType;
use OGame\Combat\Enums\SnapshotContribution;
use OGame\Combat\Enums\TargetScope;
use OGame\Combat\Exceptions\ContradictoryCausalEvent;
use OGame\Combat\Exceptions\ContradictoryOpeningProvenance;
use OGame\Combat\Exceptions\IncompleteEventSlice;
use OGame\Combat\Support\EffectOrderKey;
use Tests\UnitTestCase;

/**
 * La reconciliation causale : ce qui entre dans la photographie, et ce qui n'y entre pas deux fois.
 *
 * ## La fenetre de reference
 *
 * Ouverture et capture a **1000**, fermeture a **1060**. Les instants sont choisis pour tomber juste
 * avant, exactement sur, et juste apres chaque barriere — c'est la seule facon de verifier qu'une
 * egalite compte pour « apres ».
 *
 * ## Ce que ces essais ne prouvent pas
 *
 * Le reconciliateur est pur : il ne lit aucune base et n'applique aucun effet. Ces essais prouvent
 * qu'il **selectionne et ordonne** correctement. Ils ne disent rien de la transaction de fermeture,
 * des verrous, ni de l'idempotence des gestionnaires canoniques.
 */
class CausalOrderReconcilerTest extends UnitTestCase
{
    private const int OPENING = 1_000;

    private const int CLOSING = 1_060;

    private const int TARGET_BODY = 77;

    /**
     * Les deux barrieres, aux instants qui comptent.
     */
    public function testBothBarriersAreStrictAndEqualityCountsAsAfter(): void
    {
        $cas = [
            'decision juste avant, effet juste avant' => [999, 1_059, CausalAdmission::AppliedBeforeSnapshot],
            'decision exactement a l ouverture' => [self::OPENING, 1_059, CausalAdmission::OutsideSnapshot],
            'decision juste apres l ouverture' => [1_001, 1_059, CausalAdmission::OutsideSnapshot],
            'effet exactement a la fermeture' => [999, self::CLOSING, CausalAdmission::OutsideSnapshot],
            'effet juste apres la fermeture' => [999, 1_061, CausalAdmission::OutsideSnapshot],
        ];

        foreach ($cas as $quoi => [$decide, $effet, $attendu]) {
            $etat = $this->reconcile([$this->anEvent('e1', decidedAt: $decide, effectAt: $effet)]);

            $this->assertSame($attendu, $this->admissionOf($etat, 'e1'), "The rule for « {$quoi} » changed.");
        }
    }

    /**
     * A la fermeture exacte, tous les genres sont exclus, quel que soit leur rang.
     *
     * Le rang ne sert qu'a **ordonner ce qui est admis**. L'exclusion precede le departage : sans
     * cela, une recherche de rang 0 pourrait se glisser dans une photographie que sa propre barriere
     * lui ferme.
     */
    public function testAtTheExactClosingEveryKindIsExcludedWhateverItsRank(): void
    {
        foreach (CombatEventType::cases() as $rang => $genre) {
            $etat = $this->reconcile([
                $this->anEvent(
                    'e' . $rang,
                    decidedAt: 999,
                    effectAt: self::CLOSING,
                    identifier: $rang + 1,
                    type: $genre
                ),
            ]);

            $this->assertSame(
                CausalAdmission::OutsideSnapshot,
                $this->admissionOf($etat, 'e' . $rang),
                'A ' . $genre->value . ' slipped past the closing barrier because of its rank.'
            );
        }
    }

    /**
     * Les deux ordres ne se melangent jamais.
     *
     * Les deux evenements sont volontairement **opposes** : celui qui a ete decide en premier produit
     * son effet en second. Trier par ordre de decision, par identifiant, ou par heure de reveil du
     * worker donnerait l'ordre inverse — plausible, et faux.
     */
    public function testTheDecisionOrderAndTheEffectOrderAreNeverMixed(): void
    {
        $etat = $this->reconcile([
            $this->anEvent('tot-tard', decidedAt: 100, effectAt: 1_050, identifier: 1),
            $this->anEvent('tard-tot', decidedAt: 900, effectAt: 1_010, identifier: 900),
        ]);

        $this->assertSame(
            ['ouverture', 'tard-tot', 'tot-tard'],
            array_map(static fn (ReconciledEvent $e): string => $e->event->identity, $etat->reconciled),
            'The effects were sorted by decision order, by identifier, or by worker time.'
        );

        $this->assertSame(CausalAdmission::AppliedBeforeSnapshot, $this->admissionOf($etat, 'tot-tard'));
        $this->assertSame(CausalAdmission::AppliedBeforeSnapshot, $this->admissionOf($etat, 'tard-tot'));
    }

    /**
     * A effet simultane, le rang par genre decide, et il decide quelque chose de reel.
     *
     * ## Le classement est celui qui existait deja
     *
     * `CombatEventType::rank()` etait ecrit avant ce chantier : arrivee, missile, chantier. J'avais
     * d'abord ecrit l'ordre **inverse** dans une seconde cle d'effet, en raisonnant qu'une defense
     * achevee devait pouvoir etre touchee par un missile de la meme seconde. Cette seconde cle a ete
     * supprimee : deux classements concurrents pour un meme enum finissent par diverger.
     *
     * L'essai fige donc l'ordre **en vigueur**. Si l'autre est preferable, c'est une decision de jeu
     * a prendre separement, et elle changera `rank()` — pas une seconde table.
     *
     * `ResearchCompletion` a ete ajoute **apres** les trois autres, en rang 4 : additif, il ne
     * reordonne aucun couple existant.
     */
    public function testAtEqualEffectTimeTheRankDecidesSomethingReal(): void
    {
        $etat = $this->reconcile([
            $this->anEvent('arrivee', effectAt: 1_020, identifier: 1, type: CombatEventType::FleetArrival),
            $this->anEvent('missile', effectAt: 1_020, identifier: 2, type: CombatEventType::MissileImpact),
            $this->anEvent('chantier', effectAt: 1_020, identifier: 3, type: CombatEventType::QueueCompletion),
            $this->anEvent('recherche', effectAt: 1_020, identifier: 4, type: CombatEventType::ResearchCompletion),
        ]);

        $this->assertSame(
            ['ouverture', 'arrivee', 'missile', 'chantier', 'recherche'],
            array_map(static fn (ReconciledEvent $e): string => $e->event->identity, $etat->reconciled),
            'The simultaneous ranking changed. It is the one CombatEventType::rank() already carried, '
            . 'and changing it is a game decision, not a refactor.'
        );
    }

    /**
     * Un evenement deja reflete dans l'etat d'ouverture n'est pas rejoue.
     *
     * ## Le piege que la provenance existe pour supprimer
     *
     * Le transport a livre ses 100 metal **avant** l'ouverture. Son engagement precede l'ouverture,
     * son effet precede la fermeture : les deux barrieres disent oui. Seule la provenance dit que
     * c'est deja fait.
     */
    public function testAnEventAlreadyReflectedAtOpeningIsNotReplayed(): void
    {
        $etat = $this->reconcile(
            [$this->anEvent('transport-deja-livre', decidedAt: 500, effectAt: 900)],
            provenance: OpeningProvenance::ofReceipts([$this->aReceiptFor('transport-deja-livre', appliedAt: 900)])
        );

        $this->assertSame(CausalAdmission::AlreadyInOpeningState, $this->admissionOf($etat, 'transport-deja-livre'));

        $this->assertContains(
            'transport-deja-livre',
            array_map(static fn (ReconciledEvent $e): string => $e->event->identity, $etat->inTheSnapshot())
        );

        $this->assertSame(
            [],
            array_map(static fn (ReconciledEvent $e): string => $e->event->identity, $etat->toApply()),
            'An event already reflected at opening was scheduled for application: its effect would be doubled.'
        );
    }

    /**
     * Le meme evenement sans provenance serait applique : c'est bien elle qui fait la difference.
     */
    public function testWithoutProvenanceTheVerySameEventWouldBeApplied(): void
    {
        $etat = $this->reconcile([$this->anEvent('transport-deja-livre', decidedAt: 500, effectAt: 900)]);

        $this->assertSame(CausalAdmission::AppliedBeforeSnapshot, $this->admissionOf($etat, 'transport-deja-livre'));
        $this->assertCount(1, $etat->toApply());
    }

    /**
     * La meme mission avec une cargaison alteree est une contradiction, pas une admission.
     *
     * ## Pourquoi l'identifiant seul ne suffisait pas
     *
     * Savoir que `mission:42` a deja ete appliquee ne dit pas **quel** effet de `mission:42` est
     * present. Si la cargaison a change entre l'application et la relecture, se fier au seul
     * identifiant renoncerait a appliquer un effet qui, lui, ne l'a pas ete.
     */
    public function testTheSameMissionWithAnAlteredCargoIsAContradiction(): void
    {
        $this->expectException(ContradictoryOpeningProvenance::class);

        $this->reconcile(
            [$this->anEvent('mission:42', decidedAt: 500, effectAt: 900, effectFingerprint: 'metal:200')],
            provenance: OpeningProvenance::ofReceipts([
                $this->aReceiptFor('mission:42', appliedAt: 900, effectFingerprint: 'metal:100'),
            ])
        );
    }

    /**
     * Un recu concernant un autre corps est une contradiction.
     */
    public function testAReceiptForAnotherBodyIsAContradiction(): void
    {
        $this->expectException(ContradictoryOpeningProvenance::class);

        $this->reconcile(
            [$this->anEvent('mission:42', decidedAt: 500, effectAt: 900)],
            provenance: OpeningProvenance::ofReceipts([
                $this->aReceiptFor('mission:42', appliedAt: 900, aggregateId: self::TARGET_BODY + 1),
            ])
        );
    }

    /**
     * Un effet declare present dans un etat capture avant lui est une contradiction.
     *
     * Ce n'est pas une issue a trancher : la provenance et la chronologie ne peuvent pas se
     * contredire.
     */
    public function testAnEffectAppliedAfterTheCaptureCannotBeInIt(): void
    {
        $this->expectException(ContradictoryOpeningProvenance::class);

        $this->reconcile(
            [$this->anEvent('mission:42', decidedAt: 500, effectAt: 900)],
            provenance: OpeningProvenance::ofReceipts([
                $this->aReceiptFor('mission:42', appliedAt: self::OPENING + 5),
            ])
        );
    }

    /**
     * Un genre versionne different est une contradiction.
     */
    public function testADifferentKindVersionIsAContradiction(): void
    {
        $this->expectException(ContradictoryOpeningProvenance::class);

        $this->reconcile(
            [$this->anEvent('mission:42', decidedAt: 500, effectAt: 900)],
            provenance: OpeningProvenance::ofReceipts([
                $this->aReceiptFor('mission:42', appliedAt: 900, kindVersion: 'fleet_arrival_v2'),
            ])
        );
    }

    /**
     * Deux evenements distincts portant le meme montant restent deux livraisons.
     *
     * Le troisieme cas de la regle de provenance, et il compte autant que les deux autres : les
     * confondre supprimerait une livraison reelle.
     */
    public function testTwoDistinctEventsCarryingTheSameAmountStayTwoDeliveries(): void
    {
        $etat = $this->reconcile([
            $this->anEvent('mission:1', decidedAt: 500, effectAt: 1_010, identifier: 1, effectFingerprint: 'metal:100'),
            $this->anEvent('mission:2', decidedAt: 600, effectAt: 1_020, identifier: 2, effectFingerprint: 'metal:100'),
        ]);

        $this->assertSame(CausalAdmission::AppliedBeforeSnapshot, $this->admissionOf($etat, 'mission:1'));
        $this->assertSame(CausalAdmission::AppliedBeforeSnapshot, $this->admissionOf($etat, 'mission:2'));
        $this->assertCount(2, $etat->toApply(), 'Two real deliveries were collapsed into one.');
    }

    /**
     * Deux recus du meme evenement decrivant des effets differents sont refuses.
     */
    public function testTwoReceiptsOfTheSameEventWithDifferentEffectsAreRefused(): void
    {
        $this->expectException(ContradictoryOpeningProvenance::class);

        OpeningProvenance::ofReceipts([
            $this->aReceiptFor('mission:42', appliedAt: 900, effectFingerprint: 'metal:100'),
            $this->aReceiptFor('mission:42', appliedAt: 900, effectFingerprint: 'metal:200'),
        ]);
    }

    /**
     * L'initiateur survit a une fenetre nulle.
     */
    public function testTheFoundingInitiatorSurvivesAZeroLengthWindow(): void
    {
        $etat = (new CausalOrderReconciler())->reconcile(
            $this->anOpeningState(),
            'ouverture',
            new CausalWindow(self::OPENING, self::OPENING),
            $this->aVerifiedSlice([
                $this->anEvent('ouverture', decidedAt: self::OPENING, effectAt: self::OPENING),
                // Engage avant l'ouverture, mais son effet tombe exactement sur la barriere.
                $this->anEvent('sur-la-barriere', decidedAt: 999, effectAt: self::OPENING, identifier: 2),
                // Celui-la a produit son effet avant l'ouverture : admissible, et c'est la provenance
                // qui l'empecherait d'etre rejoue si elle le connaissait.
                $this->anEvent('avant-l-ouverture', decidedAt: 998, effectAt: 999, identifier: 3),
            ])
        );

        $this->assertSame(CausalAdmission::FoundingInitiator, $this->admissionOf($etat, 'ouverture'));
        $this->assertSame(CausalAdmission::OutsideSnapshot, $this->admissionOf($etat, 'sur-la-barriere'));
        $this->assertSame(CausalAdmission::AppliedBeforeSnapshot, $this->admissionOf($etat, 'avant-l-ouverture'));
    }

    /**
     * L'ordre des entrees ne change pas le plan.
     */
    public function testPermutingTheInputsGivesTheSamePlan(): void
    {
        $evenements = [
            $this->anEvent('a', decidedAt: 500, effectAt: 1_030, identifier: 3),
            $this->anEvent('b', decidedAt: 600, effectAt: 1_010, identifier: 2),
            $this->anEvent('c', decidedAt: 700, effectAt: 1_020, identifier: 1),
        ];

        $reference = null;

        foreach ($this->permutationsOf($evenements) as $permutation) {
            $decrit = $this->reconcile($permutation)->describe();

            $reference ??= $decrit;

            $this->assertSame($reference, $decrit, 'The order of the input rows changed the plan.');
        }

        $this->assertNotNull($reference);
        $this->assertCount(4, $reference, 'The three events and the opener should all appear.');
    }

    /**
     * Un doublon identique est idempotent ; deux contenus differents sont refuses.
     */
    public function testAnIdenticalDuplicateIsIdempotentButTwoContentsAreRefused(): void
    {
        $revendication = CausalEventSliceClaim::assembledFrom(
            [
                $this->anEvent('e1', decidedAt: 500, effectAt: 1_010),
                $this->anEvent('e1', decidedAt: 500, effectAt: 1_010),
                $this->anEvent('ouverture'),
            ],
            CausalEventSource::cases()
        );

        $this->assertSame(2, $revendication->count(), 'An identical duplicate was not reduced to one.');

        $this->expectException(ContradictoryCausalEvent::class);

        CausalEventSliceClaim::assembledFrom(
            [
                $this->anEvent('e1', decidedAt: 500, effectAt: 1_010),
                $this->anEvent('e1', decidedAt: 500, effectAt: 1_020),
            ],
            CausalEventSource::cases()
        );
    }

    /**
     * Une source oubliee empeche la tranche d'etre verifiee.
     *
     * ## Une revendication n'est pas une preuve
     *
     * Une premiere version acceptait un simple `readUnderLock: true`. C'etait une convention de
     * nommage : n'importe quel appelant pouvait declarer une tranche complete, et le reconciliateur
     * lui faisait confiance.
     */
    public function testAForgottenSourcePreventsVerification(): void
    {
        foreach (CausalEventSource::cases() as $oubliee) {
            $restantes = array_values(array_filter(
                CausalEventSource::cases(),
                static fn (CausalEventSource $s): bool => $s !== $oubliee
            ));

            $revendication = CausalEventSliceClaim::assembledFrom([$this->anEvent('e1')], $restantes);

            try {
                VerifiedCompleteEventSlice::verifiedUnderLock($revendication, $this->aBarrier());
                $this->fail("The slice was verified although « {$oubliee->value} » was never queried.");
            } catch (IncompleteEventSlice) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Un evenement au-dela du curseur empeche la tranche d'etre verifiee.
     *
     * Les verrous seuls ne garantissent pas l'ordre : deux workers peuvent les obtenir differemment.
     * Le curseur dit jusqu'ou cette lecture fait autorite.
     */
    public function testAnEventBeyondTheCursorPreventsVerification(): void
    {
        $revendication = CausalEventSliceClaim::assembledFrom(
            [$this->anEvent('trop-loin', effectAt: 5_000_000)],
            CausalEventSource::cases()
        );

        $this->expectException(IncompleteEventSlice::class);

        VerifiedCompleteEventSlice::verifiedUnderLock($revendication, $this->aBarrier());
    }

    /**
     * Une tranche sans son engagement fondateur est refusee.
     */
    public function testASliceWithoutItsFoundingInitiatorIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CausalOrderReconciler())->reconcile(
            $this->anOpeningState(),
            'ouverture',
            new CausalWindow(self::OPENING, self::CLOSING),
            $this->aVerifiedSlice([$this->anEvent('e1', decidedAt: 500, effectAt: 1_010)])
        );
    }

    /**
     * Une egalite de coordonnees ne fait entrer personne dans la photographie.
     */
    public function testCoordinateEqualityAdmitsNobody(): void
    {
        $etat = $this->reconcile([
            $this->anEvent('autre-corps', decidedAt: 500, effectAt: 1_010, targetBodyId: self::TARGET_BODY + 1),
            $this->anEvent('champ-de-debris', decidedAt: 500, effectAt: 1_011, identifier: 2, scope: TargetScope::DebrisField),
        ]);

        $this->assertSame(CausalAdmission::NotApplicable, $this->admissionOf($etat, 'autre-corps'));
        $this->assertSame(CausalAdmission::NotApplicable, $this->admissionOf($etat, 'champ-de-debris'));
    }

    /**
     * Un evenement annule ou remplace n'est jamais rejoue.
     */
    public function testACancelledEventIsNeverReplayed(): void
    {
        $etat = $this->reconcile([
            $this->anEvent('annule', decidedAt: 500, effectAt: 1_010, stillValid: false),
        ]);

        $this->assertSame(CausalAdmission::NotApplicable, $this->admissionOf($etat, 'annule'));
    }

    /**
     * Un evenement qui n'apporte rien a la photographie n'y entre pas.
     */
    public function testAnEventThatContributesNothingDoesNotEnterTheSnapshot(): void
    {
        $etat = $this->reconcile([
            $this->anEvent('sans-effet', decidedAt: 500, effectAt: 1_010, contributions: []),
        ]);

        $this->assertSame(CausalAdmission::OutsideSnapshot, $this->admissionOf($etat, 'sans-effet'));
    }

    /**
     * Chaque genre d'effet apporte sa contribution nommee.
     *
     * ## Pourquoi la question n'est pas « depose-t-il des vaisseaux »
     *
     * Un missile modifie des defenses, un chantier acheve ajoute des unites, une recherche change des
     * caracteristiques de combat. Aucun ne pose de flotte, et tous les trois modifient la
     * photographie.
     */
    public function testEachKindOfEffectCarriesItsNamedContribution(): void
    {
        $attendus = [
            'transport' => [CombatEventType::FleetArrival, [SnapshotContribution::DeliveredCargo]],
            'deploiement' => [CombatEventType::FleetArrival, [SnapshotContribution::DeliveredFleet, SnapshotContribution::DeliveredCargo]],
            'retour' => [CombatEventType::FleetArrival, [SnapshotContribution::DeliveredFleet, SnapshotContribution::DeliveredCargo]],
            'missile' => [CombatEventType::MissileImpact, [SnapshotContribution::TargetDefences]],
            'chantier' => [CombatEventType::QueueCompletion, [SnapshotContribution::TargetDefences]],
            'recherche' => [CombatEventType::ResearchCompletion, [SnapshotContribution::CombatTechnology]],
        ];

        $rang = 0;

        foreach ($attendus as $quoi => [$genre, $contributions]) {
            $rang++;

            $etat = $this->reconcile([
                $this->anEvent($quoi, decidedAt: 500, effectAt: 1_010, identifier: $rang, type: $genre, contributions: $contributions),
            ]);

            $this->assertSame(
                CausalAdmission::AppliedBeforeSnapshot,
                $this->admissionOf($etat, $quoi),
                "« {$quoi} » no longer enters the snapshot."
            );

            foreach ($contributions as $contribution) {
                $this->assertContains($contribution, $etat->contributions(), $quoi);
            }
        }
    }

    /**
     * Une contribution n'est jamais comptee deux fois dans le total.
     */
    public function testAContributionIsNeverCountedTwiceInTheTotal(): void
    {
        $etat = $this->reconcile([
            $this->anEvent('t1', decidedAt: 500, effectAt: 1_010, identifier: 1),
            $this->anEvent('t2', decidedAt: 600, effectAt: 1_020, identifier: 2),
            $this->anEvent('t3', decidedAt: 700, effectAt: 1_030, identifier: 3),
        ]);

        $this->assertCount(3, $etat->toApply());
        $this->assertSame([SnapshotContribution::DeliveredCargo], $etat->contributions());
    }

    /**
     * Un etat reconcilie mal ordonne est refuse.
     */
    public function testABadlyOrderedSnapshotIsRefused(): void
    {
        $this->expectException(LogicException::class);

        new CausallyReconciledSnapshot(
            $this->anOpeningState(),
            new CausalWindow(self::OPENING, self::CLOSING),
            [
                new ReconciledEvent($this->anEvent('tard', effectAt: 1_050, identifier: 1), CausalAdmission::AppliedBeforeSnapshot, ''),
                new ReconciledEvent($this->anEvent('tot', effectAt: 1_010, identifier: 2), CausalAdmission::AppliedBeforeSnapshot, ''),
            ]
        );
    }

    /**
     * Le meme evenement ne peut pas figurer deux fois dans un etat reconcilie.
     */
    public function testTheSameEventMayNotAppearTwiceInASnapshot(): void
    {
        $this->expectException(LogicException::class);

        new CausallyReconciledSnapshot(
            $this->anOpeningState(),
            new CausalWindow(self::OPENING, self::CLOSING),
            [
                new ReconciledEvent($this->anEvent('e1', effectAt: 1_010), CausalAdmission::AppliedBeforeSnapshot, ''),
                new ReconciledEvent($this->anEvent('e1', effectAt: 1_010), CausalAdmission::AppliedBeforeSnapshot, ''),
            ]
        );
    }

    /**
     * Deux appels a deux heures d'intervalle donnent le meme plan.
     *
     * Le reconciliateur ne lit aucune horloge : c'est ce qui rend un worker en retard inoffensif.
     */
    public function testAWorkerRunningHoursLaterProducesTheSamePlan(): void
    {
        $evenements = [
            $this->anEvent('a', decidedAt: 500, effectAt: 1_010, identifier: 1),
            $this->anEvent('b', decidedAt: self::OPENING, effectAt: 1_020, identifier: 2),
        ];

        $this->assertSame($this->reconcile($evenements)->describe(), $this->reconcile($evenements)->describe());
        $this->assertSame(CausalAdmission::OutsideSnapshot, $this->admissionOf($this->reconcile($evenements), 'b'));
    }

    /**
     * Un depart d'egalite non persiste est refuse.
     */
    public function testANonPersistedTieBreakerIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DecisionOrder(500, 0);
    }

    /**
     * Un plan, avec la fenetre de reference.
     *
     * @param array<int, CausalEvent> $events
     * @param OpeningProvenance|null $provenance
     * @return CausallyReconciledSnapshot
     */
    private function reconcile(array $events, OpeningProvenance|null $provenance = null): CausallyReconciledSnapshot
    {
        $events[] = $this->anEvent('ouverture', decidedAt: self::OPENING, effectAt: self::OPENING, identifier: 999_999);

        return (new CausalOrderReconciler())->reconcile(
            $this->anOpeningState($provenance),
            'ouverture',
            new CausalWindow(self::OPENING, self::CLOSING),
            $this->aVerifiedSlice($events)
        );
    }

    /**
     * Une tranche verifiee, toutes sources interrogees.
     *
     * @param array<int, CausalEvent> $events
     * @return VerifiedCompleteEventSlice
     */
    private function aVerifiedSlice(array $events): VerifiedCompleteEventSlice
    {
        return VerifiedCompleteEventSlice::verifiedUnderLock(
            CausalEventSliceClaim::assembledFrom($events, CausalEventSource::cases()),
            $this->aBarrier()
        );
    }

    /**
     * La barriere de partition, avec un curseur assez loin pour couvrir les essais.
     */
    private function aBarrier(): PartitionBarrier
    {
        return new PartitionBarrier(
            42,
            self::TARGET_BODY,
            EffectOrderKey::forEvent(1_000_000, CombatEventType::FleetArrival, 1_000_000)
        );
    }

    /**
     * L'etat d'ouverture, avec ou sans provenance.
     */
    private function anOpeningState(OpeningProvenance|null $provenance = null): ProtectedOpeningState
    {
        return new ProtectedOpeningState(
            42,
            self::TARGET_BODY,
            self::OPENING,
            $provenance ?? OpeningProvenance::nothing(),
            'empreinte-ouverture'
        );
    }

    /**
     * Un recu d'application, avec des valeurs par defaut coherentes.
     */
    private function aReceiptFor(
        string $identity,
        int $appliedAt,
        string $effectFingerprint = 'effet-canonique',
        string $kindVersion = 'fleet_arrival_v1',
        int $aggregateId = self::TARGET_BODY,
    ): AppliedEffectReceipt {
        return new AppliedEffectReceipt(
            $identity,
            $kindVersion,
            $effectFingerprint,
            $aggregateId,
            $appliedAt,
            'recu-' . $identity
        );
    }

    /**
     * Un evenement causal, avec des valeurs par defaut admissibles.
     *
     * L'empreinte d'effet vaut par defaut la meme que celle des recus : c'est ce qui fait que la
     * provenance reconnait l'evenement quand l'essai le veut, et qu'elle proteste des qu'un essai en
     * change une seule.
     *
     * @param array<int, SnapshotContribution>|null $contributions
     */
    private function anEvent(
        string $identity,
        int $decidedAt = 500,
        int $effectAt = 1_010,
        int $identifier = 1,
        int $targetBodyId = self::TARGET_BODY,
        TargetScope $scope = TargetScope::CelestialBody,
        CombatEventType $type = CombatEventType::FleetArrival,
        array|null $contributions = null,
        bool $stillValid = true,
        string $effectFingerprint = 'effet-canonique',
    ): CausalEvent {
        return new CausalEvent(
            $identity,
            'fleet_arrival_v1',
            new DecisionOrder($decidedAt, $identifier),
            EffectOrderKey::forEvent($effectAt, $type, $identifier),
            $targetBodyId,
            $scope,
            $contributions ?? [SnapshotContribution::DeliveredCargo],
            'empreinte-' . $identity,
            $effectFingerprint,
            $stillValid
        );
    }

    /**
     * L'issue d'un evenement dans un plan.
     */
    private function admissionOf(CausallyReconciledSnapshot $snapshot, string $identity): CausalAdmission
    {
        foreach ($snapshot->reconciled as $event) {
            if ($event->event->identity === $identity) {
                return $event->admission;
            }
        }

        $this->fail("The event « {$identity} » is missing from the plan.");
    }

    /**
     * Toutes les permutations d'une liste.
     *
     * @param array<int, CausalEvent> $items
     * @return array<int, array<int, CausalEvent>>
     */
    private function permutationsOf(array $items): array
    {
        if (count($items) <= 1) {
            return [$items];
        }

        $permutations = [];

        foreach ($items as $rang => $item) {
            $reste = $items;
            unset($reste[$rang]);

            foreach ($this->permutationsOf(array_values($reste)) as $permutation) {
                $permutations[] = [$item, ...$permutation];
            }
        }

        return $permutations;
    }
}
