<?php

namespace Tests\Unit\Combat;

use InvalidArgumentException;
use OGame\Combat\Decisions\SnapshotDecision;
use OGame\Combat\Decisions\UnresolvedCombatDecision;
use OGame\Combat\Enums\CombatReasonCode;
use OGame\Combat\Enums\SnapshotContribution;
use OGame\Combat\Enums\SnapshotSource;
use Tests\UnitTestCase;

/**
 * Qui entre dans la photographie, avec quoi, et qui peut retenir la fenetre ouverte.
 *
 * La regle qui a demande le plus de soin : **prolonger le ralliement est reserve aux candidates
 * retenues**. Une premiere version se contentait d'exiger une flotte combattante ; or
 * `DefendingFleet` designe aussi bien une Defense ACS candidate qu'un retour personnel charge. Un
 * retour pouvait donc maintenir la fenetre ouverte pour s'y inclure lui-meme.
 */
class SnapshotDecisionTest extends UnitTestCase
{
    /**
     * Une attaque candidate retenue prolonge la fenetre.
     */
    public function testASelectedAttackExtendsTheWindow(): void
    {
        $decision = SnapshotDecision::includeSelectedRallyCandidate([SnapshotContribution::AttackingFleet]);

        $this->assertTrue($decision->included);
        $this->assertSame([SnapshotContribution::AttackingFleet], $decision->contributions());
        $this->assertTrue($decision->extendsRallyWindow(), 'A selected attacking wave must be able to hold the window open.');
    }

    /**
     * Une Defense ACS candidate retenue prolonge la fenetre.
     */
    public function testASelectedAcsDefenceExtendsTheWindow(): void
    {
        $decision = SnapshotDecision::includeSelectedRallyCandidate([SnapshotContribution::DefendingFleet]);

        $this->assertTrue($decision->extendsRallyWindow(), 'A selected ACS defence must be able to hold the window open.');
    }

    /**
     * Un retour charge entre dans la photographie mais ne prolonge rien.
     *
     * **Le cas qui a revele le trou.** Il apporte des vaisseaux et une cargaison, donc les deux
     * contributions ; il arrive avant une echeance fixee par d'autres, donc il compte. Mais il ne
     * peut pas maintenir cette echeance ouverte pour s'y inclure lui-meme.
     */
    public function testALoadedReturnCountsButExtendsNothing(): void
    {
        $decision = SnapshotDecision::includeWithoutExtendingWindow(
            [SnapshotContribution::DeliveredFleet, SnapshotContribution::DeliveredCargo],
            SnapshotSource::IncidentalArrival
        );

        $this->assertCount(2, $decision->contributions(), 'A loaded return brings both its ships and its cargo.');
        $this->assertFalse($decision->extendsRallyWindow(), 'A personal return held the rally window open to include itself.');
    }

    /**
     * Un deploiement personnel ne prolonge rien non plus.
     */
    public function testAPersonalDeploymentExtendsNothing(): void
    {
        $decision = SnapshotDecision::includeWithoutExtendingWindow(
            [SnapshotContribution::DeliveredFleet, SnapshotContribution::DeliveredCargo],
            SnapshotSource::IncidentalArrival
        );

        $this->assertFalse($decision->extendsRallyWindow());
    }

    /**
     * Un transport apporte des ressources, et rien d'autre.
     */
    public function testATransportBringsResourcesOnly(): void
    {
        $decision = SnapshotDecision::includeWithoutExtendingWindow(
            [SnapshotContribution::DeliveredCargo],
            SnapshotSource::IncidentalArrival
        );

        $this->assertSame([SnapshotContribution::DeliveredCargo], $decision->contributions());
        $this->assertFalse($decision->extendsRallyWindow());
    }

    /**
     * La garnison deja stationnee compte, sans rien prolonger.
     */
    public function testTheStandingGarrisonCountsWithoutExtending(): void
    {
        $decision = SnapshotDecision::includeWithoutExtendingWindow(
            [SnapshotContribution::DefendingFleet, SnapshotContribution::TargetDefences],
            SnapshotSource::ExistingTargetState
        );

        $this->assertTrue($decision->included);
        $this->assertFalse($decision->extendsRallyWindow(), 'What was already there cannot make a battle wait for it.');
    }

    /**
     * Une candidate ecartee par son budget n'apporte rien et ne prolonge rien.
     */
    public function testACandidateExcludedByItsBudgetBringsNothing(): void
    {
        foreach ([CombatReasonCode::FleetLimitReached, CombatReasonCode::PlayerLimitReached] as $raison) {
            $decision = SnapshotDecision::exclude($raison);

            $this->assertFalse($decision->included);
            $this->assertSame([], $decision->contributions(), 'An excluded candidate brought something into the snapshot.');
            $this->assertFalse($decision->extendsRallyWindow(), 'An excluded candidate held the target locked for nothing.');
            $this->assertSame($raison, $decision->reason());
        }
    }

    /**
     * Aucune provenance autre que la selection ne peut prolonger la fenetre.
     *
     * Le controle ne repose pas sur la vigilance de l'appelant : la seule fabrique qui produise
     * `Extend` est celle des candidates retenues.
     */
    public function testNothingIncidentalCanEverExtendTheWindow(): void
    {
        $autres = [
            SnapshotSource::IncidentalArrival->value => [
                'retour charge' => [SnapshotContribution::DeliveredFleet, SnapshotContribution::DeliveredCargo],
                'transport' => [SnapshotContribution::DeliveredCargo],
            ],
            SnapshotSource::ExistingTargetState->value => [
                'garnison et defenses' => [SnapshotContribution::DefendingFleet, SnapshotContribution::TargetDefences],
                'solde de la cible' => [SnapshotContribution::TargetResources],
            ],
        ];

        foreach ($autres as $provenance => $cas) {
            foreach ($cas as $quoi => $contributions) {
                $decision = SnapshotDecision::includeWithoutExtendingWindow(
                    $contributions,
                    SnapshotSource::from($provenance)
                );

                $this->assertFalse(
                    $decision->extendsRallyWindow(),
                    "A {$quoi} arriving as {$provenance} held the rally window open."
                );
            }
        }
    }

    /**
     * Toute contribution admise pour une candidate retenue est une flotte combattante.
     *
     * **L'invariant a remplace un controle inatteignable.** Le code verifiait, a la construction,
     * qu'une candidate retenue apportait bien une flotte — mais la table des provenances n'admet
     * ici que des flottes, si bien que ce controle ne pouvait jamais se declencher. Le garder
     * aurait donne l'illusion d'une protection que rien n'active.
     *
     * L'invariant reel est celui-ci, et il tombera si quelqu'un elargit la liste sans y penser.
     */
    public function testEveryContributionAllowedForASelectedCandidateIsAFightingFleet(): void
    {
        $admises = SnapshotContribution::allowedFor(SnapshotSource::SelectedRallyCandidate);

        $this->assertNotEmpty($admises);

        foreach ($admises as $contribution) {
            $this->assertTrue(
                $contribution->isFightingFleet(),
                "« {$contribution->value} » is allowed for a selected candidate but is not a fighting fleet, "
                . 'so a candidate could now hold the rally window open without any battle to wait for.'
            );
        }
    }

    /**
     * Une candidate retenue ne peut pas declarer autre chose qu'une flotte.
     */
    public function testASelectedCandidateCannotDeclareAnythingButAFleet(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/ne peut pas venir de/');

        SnapshotDecision::includeSelectedRallyCandidate([SnapshotContribution::TargetResources]);
    }

    /**
     * Les contributions vides ou repetees sont refusees.
     */
    public function testEmptyOrRepeatedContributionsAreRefused(): void
    {
        $refus = [
            'aucune contribution' => static fn (): SnapshotDecision => SnapshotDecision::includeWithoutExtendingWindow([], SnapshotSource::IncidentalArrival),
            'contribution repetee' => static fn (): SnapshotDecision => SnapshotDecision::includeWithoutExtendingWindow(
                [SnapshotContribution::DeliveredCargo, SnapshotContribution::DeliveredCargo],
                SnapshotSource::IncidentalArrival
            ),
        ];

        foreach ($refus as $quoi => $tentative) {
            try {
                $tentative();
                $this->fail("The construction « {$quoi} » was accepted.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Une case non tranchee ne rend aucune action, et se reconnait sans la provoquer.
     */
    public function testAnUnresolvedDecisionYieldsNoActionButCanBeIdentified(): void
    {
        $decision = SnapshotDecision::unresolved('Un transport livre pendant le ralliement entre-t-il dans les ressources photographiees ?');

        // Reconnaissable sans declencher l'exception : c'est ce qui permet a un test d'activation
        // d'enumerer les cases manquantes.
        $this->assertFalse($decision->isResolved());
        $this->assertSame(CombatReasonCode::Undecided, $decision->reason());
        $this->assertStringContainsString('transport', (string)$decision->openQuestion());

        $this->expectException(UnresolvedCombatDecision::class);

        $decision->contributions();
    }
}
