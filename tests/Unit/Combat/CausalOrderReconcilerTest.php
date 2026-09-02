<?php

namespace Tests\Unit\Combat;

use InvalidArgumentException;
use LogicException;
use OGame\Combat\Causality\CausalAdmission;
use OGame\Combat\Causality\CausalEvent;
use OGame\Combat\Causality\CausalOrderReconciler;
use OGame\Combat\Causality\CausalWindow;
use OGame\Combat\Causality\CausallyReconciledSnapshot;
use OGame\Combat\Causality\CompleteEventSlice;
use OGame\Combat\Causality\DecisionOrder;
use OGame\Combat\Causality\EffectOrderKey;
use OGame\Combat\Causality\OpeningProvenance;
use OGame\Combat\Causality\ProtectedOpeningState;
use OGame\Combat\Causality\ReconciledEvent;
use OGame\Combat\Enums\CombatEventType;
use OGame\Combat\Enums\SnapshotContribution;
use OGame\Combat\Enums\TargetScope;
use OGame\Combat\Exceptions\ContradictoryCausalEvent;
use OGame\Combat\Exceptions\IncompleteEventSlice;
use Tests\UnitTestCase;

/**
 * La reconciliation causale : ce qui entre dans la photographie, et ce qui n'y entre pas deux fois.
 *
 * ## La fenetre de reference
 *
 * Ouverture a **1000**, fermeture a **1060**. Les instants sont choisis pour tomber juste avant,
 * exactement sur, et juste apres chaque barriere — c'est la seule facon de verifier qu'une egalite
 * compte pour « apres ».
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
     * Les deux barrieres, aux trois instants qui comptent.
     *
     * Une egalite avec une barriere compte pour « apres » : sans cette convention, le sort d'un
     * evenement tombant a la seconde exacte dependrait d'une course entre deux workers.
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

            $this->assertSame(
                $attendu,
                $this->admissionOf($etat, 'e1'),
                "The rule for « {$quoi} » changed."
            );
        }
    }

    /**
     * Les deux ordres ne se melangent jamais.
     *
     * ## L'essai le plus important de ce fichier
     *
     * Les deux evenements sont volontairement **opposes** : celui qui a ete decide en premier produit
     * son effet en second. Trier par ordre de decision, par identifiant, ou par heure de reveil du
     * worker donnerait l'ordre inverse — plausible, et faux.
     */
    public function testTheDecisionOrderAndTheEffectOrderAreNeverMixed(): void
    {
        $etat = $this->reconcile([
            // Decide tot, effet tard, identifiant petit.
            $this->anEvent('tot-tard', decidedAt: 100, effectAt: 1_050, identifier: 1),
            // Decide tard, effet tot, identifiant grand.
            $this->anEvent('tard-tot', decidedAt: 900, effectAt: 1_010, identifier: 900),
        ]);

        $ordre = array_map(
            static fn (ReconciledEvent $e): string => $e->event->identity,
            $etat->reconciled
        );

        $this->assertSame(
            ['ouverture', 'tard-tot', 'tot-tard'],
            $ordre,
            'The effects were sorted by decision order, by identifier, or by worker time.'
        );

        // Et les deux sont bien admis : l'ordre est une question separee de l'admission.
        $this->assertSame(CausalAdmission::AppliedBeforeSnapshot, $this->admissionOf($etat, 'tot-tard'));
        $this->assertSame(CausalAdmission::AppliedBeforeSnapshot, $this->admissionOf($etat, 'tard-tot'));
    }

    /**
     * Un evenement deja reflete dans l'etat d'ouverture n'est pas rejoue.
     *
     * ## Le piege que la provenance existe pour supprimer
     *
     * Le transport a livre ses 100 metal **avant** l'ouverture. Son engagement precede l'ouverture,
     * son effet precede la fermeture : les deux barrieres disent oui. Seule la provenance dit que
     * c'est deja fait.
     *
     * Sans elle, ces 100 seraient ajoutes une seconde fois, et aucune comparaison temporelle ne
     * serait fausse.
     */
    public function testAnEventAlreadyReflectedAtOpeningIsNotReplayed(): void
    {
        $transport = $this->anEvent('transport-deja-livre', decidedAt: 500, effectAt: 900);

        $etat = $this->reconcile(
            [$transport],
            provenance: OpeningProvenance::ofIdentities(['transport-deja-livre'])
        );

        $this->assertSame(CausalAdmission::AlreadyInOpeningState, $this->admissionOf($etat, 'transport-deja-livre'));

        // Il entre bien dans la photographie — le solde d'ouverture le contient…
        $this->assertContains(
            'transport-deja-livre',
            array_map(static fn (ReconciledEvent $e): string => $e->event->identity, $etat->inTheSnapshot())
        );

        // … mais il ne figure pas parmi les effets a produire.
        $this->assertSame(
            [],
            array_map(static fn (ReconciledEvent $e): string => $e->event->identity, $etat->toApply()),
            'An event already reflected at opening was scheduled for application: its effect would be doubled.'
        );

        $this->assertCount(1, $etat->alreadyReflected());
    }

    /**
     * Le meme evenement sans provenance serait applique : c'est bien elle qui fait la difference.
     *
     * Sans ce controle, l'essai precedent resterait vert meme si le reconciliateur excluait ce
     * transport pour une tout autre raison.
     */
    public function testWithoutProvenanceTheVerySameEventWouldBeApplied(): void
    {
        $etat = $this->reconcile([$this->anEvent('transport-deja-livre', decidedAt: 500, effectAt: 900)]);

        $this->assertSame(CausalAdmission::AppliedBeforeSnapshot, $this->admissionOf($etat, 'transport-deja-livre'));
        $this->assertCount(1, $etat->toApply());
    }

    /**
     * L'initiateur survit a une fenetre nulle.
     *
     * Il n'a pas a preceder une ouverture qu'il a lui-meme provoquee. La fenetre nulle est le cas ou
     * la fermeture se deroule dans sa propre transaction, sans worker intermediaire.
     */
    public function testTheFoundingInitiatorSurvivesAZeroLengthWindow(): void
    {
        $reconciliateur = new CausalOrderReconciler();

        $etat = $reconciliateur->reconcile(
            $this->anOpeningState(),
            'ouverture',
            new CausalWindow(self::OPENING, self::OPENING),
            CompleteEventSlice::readUnderLock([
                $this->anEvent('ouverture', decidedAt: self::OPENING, effectAt: self::OPENING),
                // Engage avant l'ouverture, mais son effet tombe exactement sur la barriere : une
                // fenetre nulle n'admet aucun effet exterieur, puisque toute egalite compte pour apres.
                $this->anEvent('sur-la-barriere', decidedAt: 999, effectAt: self::OPENING, identifier: 2),
                // Celui-la a produit son effet avant l'ouverture : il est bien admissible, et c'est la
                // provenance de l'etat d'ouverture qui l'empechera d'etre rejoue.
                $this->anEvent('avant-l-ouverture', decidedAt: 998, effectAt: 999, identifier: 3),
            ])
        );

        $this->assertSame(CausalAdmission::FoundingInitiator, $this->admissionOf($etat, 'ouverture'));
        $this->assertSame(CausalAdmission::OutsideSnapshot, $this->admissionOf($etat, 'sur-la-barriere'));
        $this->assertSame(CausalAdmission::AppliedBeforeSnapshot, $this->admissionOf($etat, 'avant-l-ouverture'));
    }

    /**
     * L'ordre des entrees ne change pas le plan.
     *
     * Six permutations de trois evenements doivent donner exactement le meme resultat : sinon l'ordre
     * de lecture de la base deciderait de la photographie.
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
        $this->assertSame(4, count($reference), 'The three events and the opener should all appear.');
    }

    /**
     * Un doublon identique est idempotent ; deux contenus differents sont refuses.
     */
    public function testAnIdenticalDuplicateIsIdempotentButTwoContentsAreRefused(): void
    {
        $tranche = CompleteEventSlice::readUnderLock([
            $this->anEvent('e1', decidedAt: 500, effectAt: 1_010),
            $this->anEvent('e1', decidedAt: 500, effectAt: 1_010),
            $this->anEvent('ouverture'),
        ]);

        $this->assertSame(2, $tranche->count(), 'An identical duplicate was not reduced to one.');

        $this->expectException(ContradictoryCausalEvent::class);

        CompleteEventSlice::readUnderLock([
            $this->anEvent('e1', decidedAt: 500, effectAt: 1_010),
            $this->anEvent('e1', decidedAt: 500, effectAt: 1_020),
        ]);
    }

    /**
     * Une tranche qui ne se declare pas complete est refusee.
     */
    public function testASliceThatDoesNotDeclareItselfCompleteIsRefused(): void
    {
        $this->expectException(IncompleteEventSlice::class);

        CompleteEventSlice::readUnderLock([$this->anEvent('e1')], readUnderLock: false);
    }

    /**
     * Une tranche sans son engagement fondateur est refusee.
     *
     * Elle se dit complete et ne l'est pas : la photographie n'aurait aucun attaquant, et le combat
     * n'aurait pas eu lieu d'etre ouvert.
     */
    public function testASliceWithoutItsFoundingInitiatorIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CausalOrderReconciler())->reconcile(
            $this->anOpeningState(),
            'ouverture',
            new CausalWindow(self::OPENING, self::CLOSING),
            CompleteEventSlice::readUnderLock([$this->anEvent('e1', decidedAt: 500, effectAt: 1_010)])
        );
    }

    /**
     * Une egalite de coordonnees ne fait entrer personne dans la photographie.
     *
     * Une planete et sa lune partagent leur position ; un champ de debris aussi. Seul l'identifiant
     * du corps, et une portee de corps celeste, comptent.
     */
    public function testCoordinateEqualityAdmitsNobody(): void
    {
        $etat = $this->reconcile([
            $this->anEvent('autre-corps', decidedAt: 500, effectAt: 1_010, targetBodyId: self::TARGET_BODY + 1),
            $this->anEvent('champ-de-debris', decidedAt: 500, effectAt: 1_010, scope: TargetScope::DebrisField),
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
     *
     * Le cas d'un espionnage : il a bien lieu, dans le temps admissible, et ne change rien a ce que
     * le moteur verra.
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
                $this->anEvent(
                    $quoi,
                    decidedAt: 500,
                    effectAt: 1_010,
                    identifier: $rang,
                    type: $genre,
                    contributions: $contributions
                ),
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
     *
     * Les contributions sont des **traces d'audit** : elles disent ce que chaque evenement apporte.
     * L'etat global, lui, est declare une seule fois par le service de fermeture.
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
     *
     * C'est la garde qui empeche un futur appelant de reordonner le plan avant de le transmettre.
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
     * Le reconciliateur ne lit aucune horloge : c'est ce qui rend un worker en retard inoffensif. Un
     * plan qui dependrait de l'instant de son calcul ferait d'un retard de traitement une decision de
     * jeu.
     */
    public function testAWorkerRunningHoursLaterProducesTheSamePlan(): void
    {
        $evenements = [
            $this->anEvent('a', decidedAt: 500, effectAt: 1_010, identifier: 1),
            $this->anEvent('b', decidedAt: self::OPENING, effectAt: 1_020, identifier: 2),
        ];

        $premier = $this->reconcile($evenements)->describe();
        $second = $this->reconcile($evenements)->describe();

        $this->assertSame($premier, $second);
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
        // L'engagement fondateur est toujours present : une tranche qui l'omet est refusee, et ce
        // refus a son propre essai.
        $events[] = $this->anEvent('ouverture', decidedAt: self::OPENING, effectAt: self::OPENING, identifier: 999_999);

        return (new CausalOrderReconciler())->reconcile(
            $this->anOpeningState($provenance),
            'ouverture',
            new CausalWindow(self::OPENING, self::CLOSING),
            CompleteEventSlice::readUnderLock($events)
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
            $provenance ?? OpeningProvenance::nothing(),
            'empreinte-ouverture'
        );
    }

    /**
     * Un evenement causal, avec des valeurs par defaut admissibles.
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
    ): CausalEvent {
        return new CausalEvent(
            $identity,
            'fleet_arrival_v1',
            new DecisionOrder($decidedAt, $identifier),
            new EffectOrderKey($effectAt, $type, $identifier),
            $targetBodyId,
            $scope,
            $contributions ?? [SnapshotContribution::DeliveredCargo],
            'empreinte-' . $identity,
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
