<?php

namespace Tests\Unit\Combat;

use OGame\Combat\Causality\CausalAdmission;
use OGame\Combat\Causality\CausalEvent;
use OGame\Combat\Causality\CausalEventOrderV1;
use OGame\Combat\Causality\CausalEventSliceClaim;
use OGame\Combat\Causality\CausalEventSource;
use OGame\Combat\Causality\CausallyReconciledSnapshot;
use OGame\Combat\Causality\CausalOrderReconciler;
use OGame\Combat\Causality\CausalWindow;
use OGame\Combat\Causality\DecisionOrder;
use OGame\Combat\Causality\OpeningProvenance;
use OGame\Combat\Causality\PartitionBarrier;
use OGame\Combat\Causality\ProtectedOpeningState;
use OGame\Combat\Causality\VerifiedCompleteEventSlice;
use OGame\Combat\Enums\CombatEventType;
use OGame\Combat\Enums\SnapshotContribution;
use OGame\Combat\Enums\TargetScope;
use OGame\Combat\Support\EffectOrderKey;
use Tests\UnitTestCase;

/**
 * Les frontieres de la photographie, portees sur le chemin canonique.
 *
 * ## Pourquoi ce fichier existe
 *
 * `CombatSnapshotEligibility` exprimait la meme regle que `CausalWindow` et
 * `CausalOrderReconciler` : initiateur toujours admis, sinon decision avant l'ouverture **et** effet
 * avant la fermeture. Deux API pour une regle finissent par diverger, et la plus pauvre survit dans
 * un coin du code longtemps apres qu'on l'a crue morte.
 *
 * Les preuves sont donc **transferees avant la suppression**, cas par cas et sous les memes noms :
 * ce fichier reprend les dix essais de `CombatSnapshotEligibilityTest`, ecrits contre le
 * reconciliateur. La classe ancienne ne part qu'ensuite.
 *
 * ## Ce que le reconciliateur ajoute, et que l'ancienne classe n'avait pas
 *
 * La provenance. Un effet deja reflete dans l'etat d'ouverture satisfait les deux barrieres et ne
 * doit pourtant pas etre rejoue : `AlreadyInOpeningState`. L'ancienne regle ne savait pas le dire,
 * et un appelant qui s'y serait fie aurait compte deux fois chaque livraison anterieure.
 */
class CausalSnapshotBoundaryTest extends UnitTestCase
{
    private const int OUVERTURE = 1_000;

    private const int FERMETURE = 1_060;

    private const int CORPS = 77;

    /**
     * Un engagement pris avant, arrivant a temps, entre.
     */
    public function testACommitmentTakenBeforeAndArrivingInTimeEnters(): void
    {
        $this->assertSame(
            CausalAdmission::AppliedBeforeSnapshot,
            $this->issueDe(self::OUVERTURE - 100, self::FERMETURE - 1)
        );
    }

    /**
     * Une decision prise pendant le ralliement n'entre jamais.
     */
    public function testADecisionTakenDuringTheRallyNeverEnters(): void
    {
        foreach ([self::OUVERTURE, self::OUVERTURE + 1, self::FERMETURE - 1] as $decision) {
            $this->assertSame(
                CausalAdmission::OutsideSnapshot,
                $this->issueDe($decision, self::FERMETURE - 1),
                'A commitment taken at or after the opening reached the snapshot.'
            );
        }
    }

    /**
     * Un engagement dont l'effet tombe apres la photographie n'entre pas.
     */
    public function testACommitmentArrivingAfterTheSnapshotDoesNotEnter(): void
    {
        $this->assertSame(
            CausalAdmission::OutsideSnapshot,
            $this->issueDe(self::OUVERTURE - 100, self::FERMETURE + 1)
        );
    }

    /**
     * Les deux barrieres sont fermees **du meme cote**.
     *
     * Une egalite compte pour « apres », a l'ouverture comme a la fermeture. Sans cette symetrie, le
     * sort d'un evenement tombant pile sur une barriere dependrait de laquelle.
     */
    public function testBothBarriersAreClosedOnTheSameSide(): void
    {
        $this->assertSame(
            CausalAdmission::OutsideSnapshot,
            $this->issueDe(self::OUVERTURE, self::FERMETURE - 1),
            'Equality with the opening barrier was treated as before.'
        );

        $this->assertSame(
            CausalAdmission::OutsideSnapshot,
            $this->issueDe(self::OUVERTURE - 1, self::FERMETURE),
            'Equality with the closing barrier was treated as before.'
        );

        // Et juste en deca des deux, l'evenement entre : sans ce controle, une regle qui refuserait
        // tout passerait les deux assertions ci-dessus.
        $this->assertSame(
            CausalAdmission::AppliedBeforeSnapshot,
            $this->issueDe(self::OUVERTURE - 1, self::FERMETURE - 1)
        );
    }

    /**
     * Le cas du transport, tel qu'il a ete arrete.
     */
    public function testTheTransportCasesBehaveAsDecided(): void
    {
        // Engage avant, arrive avant la fermeture : la cargaison est livree avant la photo.
        $this->assertSame(
            CausalAdmission::AppliedBeforeSnapshot,
            $this->issueDe(self::OUVERTURE - 100, self::FERMETURE - 1)
        );

        // Engage avant, arrive pile a la fermeture : hors photographie.
        $this->assertSame(
            CausalAdmission::OutsideSnapshot,
            $this->issueDe(self::OUVERTURE - 100, self::FERMETURE)
        );

        // Lance pendant le ralliement : hors photographie.
        $this->assertSame(
            CausalAdmission::OutsideSnapshot,
            $this->issueDe(self::OUVERTURE + 1, self::FERMETURE - 1)
        );
    }

    /**
     * Le cas de la construction, tel qu'il a ete arrete.
     */
    public function testTheBuildQueueCasesBehaveAsDecided(): void
    {
        // Commencee avant l'ouverture et terminee avant la fermeture : elle compte.
        $this->assertSame(
            CausalAdmission::AppliedBeforeSnapshot,
            $this->issueDe(self::OUVERTURE - 3_600, self::OUVERTURE + 30, CombatEventType::QueueCompletion)
        );

        // Commencee avant, terminee apres : les unites sont posterieures a la photographie.
        $this->assertSame(
            CausalAdmission::OutsideSnapshot,
            $this->issueDe(self::OUVERTURE - 3_600, self::FERMETURE + 10, CombatEventType::QueueCompletion)
        );

        // Commencee apres l'ouverture : jamais incluse, meme terminee immediatement.
        $this->assertSame(
            CausalAdmission::OutsideSnapshot,
            $this->issueDe(self::OUVERTURE + 1, self::OUVERTURE + 2, CombatEventType::QueueCompletion),
            'A build started after the barrier reached the snapshot, so the target could add units to the battle.'
        );
    }

    /**
     * Un ralliement de duree nulle n'admet aucun effet supplementaire, mais garde l'initiateur.
     *
     * **La nuance est essentielle.** C'est le cas de l'attaquant isole : la fenetre se ferme a
     * l'instant ou elle s'ouvre. Aucun effet secondaire ne franchit les barrieres — mais il serait
     * absurde d'en conclure que l'attaquant lui-meme n'est pas dans la bataille. Il est la donnee
     * fondatrice : sans lui, il n'y a pas de combat du tout.
     */
    public function testZeroLengthRallyAdmitsNoAdditionalEffectsButKeepsTheOpener(): void
    {
        $etat = $this->reconcilier(
            [$this->evenement('secondaire', self::OUVERTURE - 100, self::OUVERTURE, identifiant: 2)],
            self::OUVERTURE
        );

        $this->assertSame(
            CausalAdmission::OutsideSnapshot,
            $this->admissionDe($etat, 'secondaire'),
            'With no rally window at all, a secondary effect still slipped into the snapshot.'
        );

        $this->assertSame(
            CausalAdmission::FoundingInitiator,
            $this->admissionDe($etat, 'ouverture'),
            'The fleet that opened the combat was left out of its own battle.'
        );

        $this->assertTrue($etat->window->isInstantaneous());
    }

    /**
     * L'initiateur entre quelles que soient les barrieres.
     *
     * Il ne les franchit pas : il les pose. Aucune combinaison ne doit pouvoir l'exclure.
     */
    public function testTheOpenerEntersWhateverTheBarriers(): void
    {
        foreach ([self::OUVERTURE - 10, self::OUVERTURE, self::OUVERTURE + 10] as $decision) {
            foreach ([self::OUVERTURE, self::FERMETURE, self::FERMETURE + 10] as $effet) {
                $etat = $this->reconcilier([], self::FERMETURE, $decision, $effet);

                $this->assertSame(
                    CausalAdmission::FoundingInitiator,
                    $this->admissionDe($etat, 'ouverture'),
                    'A combination of barriers excluded the combat opener from its own battle.'
                );
            }
        }
    }

    /**
     * Une mission creee tot mais prevue tard est classee sur son heure prevue.
     *
     * Les deux ordres sont bien distincts : un transport lent lance a midi arrive apres un transport
     * rapide lance a treize heures. Classer par rang de creation inverserait leur ordre reel.
     */
    public function testAMissionCommittedEarlyButDueLateIsJudgedOnItsDueTime(): void
    {
        $this->assertSame(
            CausalAdmission::OutsideSnapshot,
            $this->issueDe(1, self::FERMETURE + 1),
            'A mission committed very early was let in although its effect lands after the snapshot.'
        );
    }

    /**
     * Les deux conditions sont necessaires, et aucune ne suffit.
     */
    public function testBothConditionsAreNecessaryAndNeitherSuffices(): void
    {
        // Decision bonne, effet trop tard.
        $this->assertSame(CausalAdmission::OutsideSnapshot, $this->issueDe(self::OUVERTURE - 1, self::FERMETURE + 1));

        // Effet bon, decision trop tard.
        $this->assertSame(CausalAdmission::OutsideSnapshot, $this->issueDe(self::OUVERTURE + 1, self::FERMETURE - 1));

        // Les deux mauvaises.
        $this->assertSame(CausalAdmission::OutsideSnapshot, $this->issueDe(self::OUVERTURE + 1, self::FERMETURE + 1));

        // Les deux bonnes.
        $this->assertSame(CausalAdmission::AppliedBeforeSnapshot, $this->issueDe(self::OUVERTURE - 1, self::FERMETURE - 1));
    }

    /**
     * Ce que le reconciliateur ajoute, et que l'ancienne regle ne savait pas dire.
     *
     * Un effet deja reflete dans l'etat d'ouverture satisfait les **deux** barrieres. L'ancienne
     * classe l'aurait donc declare admis, et son appelant l'aurait applique une seconde fois.
     */
    public function testAnEffectAlreadyReflectedIsNotJustAdmittedButNotReplayed(): void
    {
        $etat = $this->reconcilier(
            [$this->evenement('deja-livre', self::OUVERTURE - 100, self::OUVERTURE - 50, identifiant: 3)],
            self::FERMETURE,
            provenance: OpeningProvenance::ofReceipts([
                new \OGame\Combat\Causality\AppliedEffectReceipt(
                    'deja-livre',
                    'fleet_arrival_v1',
                    'effet-canonique',
                    self::CORPS,
                    self::OUVERTURE - 50,
                    'recu-deja-livre'
                ),
            ])
        );

        $this->assertSame(CausalAdmission::AlreadyInOpeningState, $this->admissionDe($etat, 'deja-livre'));
        $this->assertSame([], $etat->toApply(), 'An effect already in the opening state was scheduled again.');
        $this->assertCount(2, $etat->inTheSnapshot(), 'It should still belong to the snapshot.');
    }

    /**
     * L'issue d'un evenement unique, decrit par sa decision et son effet.
     */
    private function issueDe(
        int $decision,
        int $effet,
        CombatEventType $genre = CombatEventType::FleetArrival,
    ): CausalAdmission {
        $etat = $this->reconcilier(
            [$this->evenement('sujet', $decision, $effet, identifiant: 2, genre: $genre)],
            self::FERMETURE
        );

        return $this->admissionDe($etat, 'sujet');
    }

    /**
     * Un plan, avec l'initiateur toujours present.
     *
     * @param array<int, CausalEvent> $evenements
     */
    private function reconcilier(
        array $evenements,
        int $fermeture,
        int $decisionOuvreur = self::OUVERTURE,
        int $effetOuvreur = self::OUVERTURE,
        OpeningProvenance|null $provenance = null,
    ): CausallyReconciledSnapshot {
        $evenements[] = $this->evenement('ouverture', $decisionOuvreur, $effetOuvreur, identifiant: 999_999);

        $ordre = new CausalEventOrderV1();

        return (new CausalOrderReconciler())->reconcile(
            new ProtectedOpeningState(
                42,
                self::CORPS,
                self::OUVERTURE,
                $provenance ?? OpeningProvenance::nothing(),
                'empreinte-ouverture'
            ),
            'ouverture',
            new CausalWindow(self::OUVERTURE, $fermeture, $ordre),
            VerifiedCompleteEventSlice::verifiedUnderLock(
                CausalEventSliceClaim::assembledFrom($evenements, CausalEventSource::cases()),
                new PartitionBarrier(
                    42,
                    self::CORPS,
                    EffectOrderKey::forEvent(9_000_000, CombatEventType::FleetArrival, 9_000_000, $ordre)
                )
            )
        );
    }

    private function evenement(
        string $identite,
        int $decision,
        int $effet,
        int $identifiant,
        CombatEventType $genre = CombatEventType::FleetArrival,
    ): CausalEvent {
        $ordre = new CausalEventOrderV1();

        return new CausalEvent(
            $identite,
            'fleet_arrival_v1',
            new DecisionOrder($decision, $identifiant),
            EffectOrderKey::forEvent($effet, $genre, $identifiant, $ordre),
            self::CORPS,
            TargetScope::CelestialBody,
            [SnapshotContribution::DeliveredCargo],
            'empreinte-' . $identite,
            'effet-canonique'
        );
    }

    private function admissionDe(CausallyReconciledSnapshot $etat, string $identite): CausalAdmission
    {
        foreach ($etat->reconciled as $reconcilie) {
            if ($reconcilie->event->identity === $identite) {
                return $reconcilie->admission;
            }
        }

        $this->fail("L evenement « {$identite} » est absent du plan.");
    }
}
