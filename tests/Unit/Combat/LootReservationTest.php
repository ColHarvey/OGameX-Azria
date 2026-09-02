<?php

namespace Tests\Unit\Combat;

use OGame\Combat\Enums\LootReservationState;
use OGame\Combat\Enums\ReservationRaise;
use OGame\Combat\Exceptions\LootReservationRefused;
use OGame\Combat\Policies\CargoWeightedV1;
use OGame\Combat\Support\AttackerCargoShare;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Combat\Support\LootEnvelope;
use OGame\Combat\Support\LootPolicy;
use OGame\Combat\Support\LootReservation;
use Tests\UnitTestCase;

/**
 * La reserve de butin : ce qu'un combat immobilise, et ce qu'elle ne doit jamais reveler.
 *
 * Le butin est calcule a la fermeture du ralliement mais preleve a la resolution, parfois deux
 * heures plus tard. Sans reserve, le defenseur depenserait entre-temps exactement ce qui allait
 * lui etre pris : le resultat serait juste, mais applique a une matiere disparue.
 */
class LootReservationTest extends UnitTestCase
{
    /**
     * La borne monte, et dit qu'elle a monte.
     */
    public function testTheBoundRisesAndSaysSo(): void
    {
        $reservation = $this->ouverte(new LootEnvelope(50, 25, 5));

        $this->assertSame(ReservationRaise::Raised, $reservation->ensureAtLeast(new LootEnvelope(80, 25, 5)));
        $this->assertTrue($reservation->reserved()->equals(new LootEnvelope(80, 25, 5)));
    }

    /**
     * Une borne plus basse ne libere rien, et le dit aussi.
     *
     * **Le cas qui a motive le renommage.** Le taux pondere par le fret peut reculer pendant le
     * ralliement : une immense flotte sans Decouvreur qui rejoint une attaque ouverte par un
     * Decouvreur ramene le taux de 75 % vers 50 %. Laisser le solde du defenseur remonter lui
     * annoncerait que la composition adverse a change.
     */
    public function testALowerBoundReleasesNothing(): void
    {
        $reservation = $this->ouverte(new LootEnvelope(75, 75, 75));

        $this->assertSame(ReservationRaise::Unchanged, $reservation->ensureAtLeast(new LootEnvelope(50, 50, 50)));
        $this->assertTrue(
            $reservation->reserved()->equals(new LootEnvelope(75, 75, 75)),
            'A lower proposal released reserved resources, which would reveal the enemy composition.'
        );
    }

    /**
     * La borne monte composante par composante, jamais en somme.
     */
    public function testTheBoundRisesComponentByComponent(): void
    {
        $reservation = $this->ouverte(new LootEnvelope(50, 25, 5));
        $reservation->ensureAtLeast(new LootEnvelope(40, 30, 5));

        $this->assertTrue(
            $reservation->reserved()->equals(new LootEnvelope(50, 30, 5)),
            'The bound was summed rather than taken component-wise.'
        );
    }

    /**
     * Le scellement rejoue a l'identique ne fait rien ; un autre est refuse.
     */
    public function testSealingIsIdempotentButADifferentSealIsRefused(): void
    {
        $reservation = $this->ouverte(new LootEnvelope(50, 25, 5));

        $reservation->seal('photo-abc');
        $reservation->seal('photo-abc');

        $this->assertSame(LootReservationState::Sealed, $reservation->state());

        $this->expectException(LootReservationRefused::class);
        $this->expectExceptionMessageMatches('/pas etre vraies toutes les deux/');

        $reservation->seal('photo-xyz');
    }

    /**
     * La borne ne monte plus une fois la photographie prise.
     */
    public function testTheBoundIsFrozenOnceTheSnapshotIsTaken(): void
    {
        $reservation = $this->ouverte(new LootEnvelope(50, 25, 5));
        $reservation->seal('photo-abc');

        $this->expectException(LootReservationRefused::class);
        $this->expectExceptionMessageMatches('/figee/');

        $reservation->ensureAtLeast(new LootEnvelope(999, 999, 999));
    }

    /**
     * Le reglage rejoue a l'identique ne preleve pas deux fois ; un autre est refuse.
     */
    public function testSettlingIsIdempotentButADifferentSettlementIsRefused(): void
    {
        $reservation = $this->scellee(new LootEnvelope(50, 25, 5));

        $reservation->settle(new LootEnvelope(30, 10, 0), 'resolution-1', 'hash-1');
        $reservation->settle(new LootEnvelope(30, 10, 0), 'resolution-1', 'hash-1');

        $this->assertTrue($reservation->actualLoot()?->equals(new LootEnvelope(30, 10, 0)) ?? false);
        $this->assertTrue($reservation->stillHeld()->isNothing(), 'Something stayed held after settlement.');

        $this->expectException(LootReservationRefused::class);
        $this->expectExceptionMessageMatches('/payer deux fois/');

        $reservation->settle(new LootEnvelope(30, 10, 0), 'resolution-2', 'hash-2');
    }

    /**
     * Un butin qui deborde sur une seule ressource arrete tout, sans rien muter.
     *
     * Meme si son total reste inferieur. Plafonner masquerait un desaccord entre la regle qui a
     * produit la borne et celle qui a produit le butin.
     */
    public function testLootExceedingOneSingleResourceStopsEverything(): void
    {
        $reservation = $this->scellee(new LootEnvelope(50, 25, 5));

        try {
            // Total de 60, bien inferieur aux 80 reserves — mais le cristal deborde.
            $reservation->settle(new LootEnvelope(30, 30, 0), 'resolution-1', 'hash-1');
            $this->fail('Loot exceeding a single resource was accepted.');
        } catch (LootReservationRefused) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(LootReservationState::Sealed, $reservation->state(), 'The reservation moved despite the refusal.');
        $this->assertNull($reservation->actualLoot(), 'Loot was recorded despite the refusal.');
        $this->assertTrue($reservation->reserved()->equals(new LootEnvelope(50, 25, 5)), 'The bound changed despite the refusal.');
    }

    /**
     * L'annulation n'est possible que depuis l'ouverture.
     *
     * Une reservation deja reglee ne s'annule pas : le butin serait preleve **puis** les fonds
     * liberes. Une defaillance apres le scellement conserve le verrou et reprend la resolution.
     */
    public function testCancellingIsOnlyPossibleWhileOpen(): void
    {
        $ouverte = $this->ouverte(new LootEnvelope(50, 25, 5));
        $ouverte->cancel('annulation-1');
        $ouverte->cancel('annulation-1');

        $this->assertSame(LootReservationState::Cancelled, $ouverte->state());
        $this->assertTrue($ouverte->stillHeld()->isNothing(), 'A cancelled reservation still holds resources.');

        foreach (['scellee' => $this->scellee(new LootEnvelope(50, 25, 5)), 'reglee' => $this->reglee()] as $quoi => $reservation) {
            try {
                $reservation->cancel('annulation-2');
                $this->fail("A « {$quoi} » reservation was cancelled.");
            } catch (LootReservationRefused) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Aucun etat terminal ne revient en arriere.
     */
    public function testNoFinalStateEverGoesBack(): void
    {
        foreach ([LootReservationState::Settled, LootReservationState::Cancelled] as $etat) {
            $this->assertTrue($etat->isFinal(), "The state {$etat->value} is not final.");
            $this->assertSame([], $etat->allowedTransitions());
        }

        $this->assertFalse(LootReservationState::Sealed->canTransitionTo(LootReservationState::Cancelled));
        $this->assertFalse(LootReservationState::Settled->canTransitionTo(LootReservationState::Open));
    }

    /**
     * Le meme contexte donne la meme reserve, que le combat soit gagne ou perdu.
     *
     * **La propriete anti-oracle, et la raison d'etre de toute cette conception.** La reserve est
     * une borne etablie sans rien connaitre de l'issue : deux batailles identiques avant le premier
     * round doivent montrer au defenseur exactement le meme solde disponible, jusqu'au rapport.
     *
     * Si l'une reservait moins parce que son attaquant va perdre, le defenseur lirait le resultat
     * dans son propre stock avant qu'on ne le lui annonce.
     */
    public function testTheSameContextHoldsTheSameAmountWhetherWonOrLost(): void
    {
        $contexte = new LootPolicy(true, new AttackerCargoShare(300, 1_000));
        $borne = $this->borneDe($contexte, new LootEnvelope(1_000, 800, 200));

        $victoire = LootReservation::open('res-a', 1, CombatParticipantKey::forPlanet(1), CargoWeightedV1::VERSION, $borne);
        $defaite = LootReservation::open('res-b', 2, CombatParticipantKey::forPlanet(2), CargoWeightedV1::VERSION, $borne);

        $victoire->seal('photo-a');
        $defaite->seal('photo-b');

        $this->assertTrue(
            $victoire->stillHeld()->equals($defaite->stillHeld()),
            'Two identical battles held different amounts, so the defender could read the outcome in his own stock.'
        );

        // Et le butin reel differe bel et bien — c'est ce que la reserve ne doit pas trahir.
        $victoire->settle(new LootEnvelope(575, 460, 115), 'r-a', 'h-a');
        $defaite->settle(LootEnvelope::nothing(), 'r-b', 'h-b');

        $this->assertFalse($victoire->actualLoot()?->equals($defaite->actualLoot() ?? LootEnvelope::nothing()) ?? true);
    }

    /**
     * Une candidate rappelee ne fait pas baisser la reserve.
     *
     * Le cas exact soumis : l'initiateur est Decouvreur, la borne monte a 75 % ; une immense flotte
     * sans Decouvreur rejoint, le taux final retombe vers 50 %. La reserve reste a 75 % jusqu'au
     * rapport, et le surplus n'est libere qu'au reglement.
     */
    public function testARecalledCandidateNeverLowersTheReservation(): void
    {
        $enCaisse = new LootEnvelope(1_000, 1_000, 1_000);

        // L'initiateur est Decouvreur, seul : 75 %.
        $seul = new LootPolicy(true, new AttackerCargoShare(1_000, 1_000));
        $reservation = LootReservation::open('res-1', 1, CombatParticipantKey::forPlanet(1), CargoWeightedV1::VERSION, $this->borneDe($seul, $enCaisse));

        $this->assertTrue($reservation->reserved()->equals(new LootEnvelope(750, 750, 750)));

        // Une immense flotte sans Decouvreur rejoint : le taux final tombe a environ 50 %.
        $ensemble = new LootPolicy(true, new AttackerCargoShare(1_000, 1_000_000));
        $this->assertSame(ReservationRaise::Unchanged, $reservation->ensureAtLeast($this->borneDe($ensemble, $enCaisse)));

        $this->assertTrue(
            $reservation->reserved()->equals(new LootEnvelope(750, 750, 750)),
            'The reservation fell back when the final rate dropped, which reveals the final composition.'
        );

        // Le surplus n'est libere qu'au reglement.
        $reservation->seal('photo-1');
        $this->assertTrue($reservation->stillHeld()->equals(new LootEnvelope(750, 750, 750)));

        $reservation->settle(new LootEnvelope(502, 502, 502), 'r-1', 'h-1');
        $this->assertTrue($reservation->stillHeld()->isNothing());
    }

    /**
     * Le cas inverse : une candidate Decouvreur fait monter une borne partie a 50 %.
     */
    public function testADiscovererCandidateRaisesABoundThatStartedAtFifty(): void
    {
        $enCaisse = new LootEnvelope(1_000, 1_000, 1_000);

        $sansDecouvreur = new LootPolicy(true, new AttackerCargoShare(0, 1_000));
        $reservation = LootReservation::open('res-1', 1, CombatParticipantKey::forPlanet(1), CargoWeightedV1::VERSION, $this->borneDe($sansDecouvreur, $enCaisse));

        $this->assertTrue($reservation->reserved()->equals(new LootEnvelope(500, 500, 500)));

        $avecDecouvreur = new LootPolicy(true, new AttackerCargoShare(1_000, 1_000));

        $this->assertSame(ReservationRaise::Raised, $reservation->ensureAtLeast($this->borneDe($avecDecouvreur, $enCaisse)));
        $this->assertTrue($reservation->reserved()->equals(new LootEnvelope(750, 750, 750)));
    }

    /**
     * La borne que cette politique impose sur ce qui est en caisse.
     */
    private function borneDe(LootPolicy $politique, LootEnvelope $enCaisse): LootEnvelope
    {
        $taux = $politique->maximumRateInBasisPoints();

        return new LootEnvelope(
            floor($enCaisse->metal * $taux / CargoWeightedV1::FULL_RATE),
            floor($enCaisse->crystal * $taux / CargoWeightedV1::FULL_RATE),
            floor($enCaisse->deuterium * $taux / CargoWeightedV1::FULL_RATE),
        );
    }

    private function ouverte(LootEnvelope $borne): LootReservation
    {
        return LootReservation::open('res-1', 77, CombatParticipantKey::forPlanet(1_234), CargoWeightedV1::VERSION, $borne);
    }

    private function scellee(LootEnvelope $borne): LootReservation
    {
        $reservation = $this->ouverte($borne);
        $reservation->seal('photo-abc');

        return $reservation;
    }

    private function reglee(): LootReservation
    {
        $reservation = $this->scellee(new LootEnvelope(50, 25, 5));
        $reservation->settle(new LootEnvelope(10, 10, 0), 'resolution-1', 'hash-1');

        return $reservation;
    }
}
