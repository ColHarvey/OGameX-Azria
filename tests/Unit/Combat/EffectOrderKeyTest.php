<?php

namespace Tests\Unit\Combat;

use InvalidArgumentException;
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
     * Chaque sorte d'evenement a un rang strictement positif.
     *
     * Zero est reserve aux barrieres. Un evenement qui le porterait se placerait avant une
     * fermeture tombant a la meme seconde, alors que la convention veut l'inverse.
     */
    public function testEveryEventTypeHasAStrictlyPositiveRank(): void
    {
        $this->assertNotEmpty(CombatEventType::cases());

        foreach (CombatEventType::cases() as $type) {
            $this->assertGreaterThan(
                0,
                $type->rank(),
                "The event type « {$type->value} » carries rank zero, which belongs to barriers alone."
            );
        }
    }

    /**
     * Deux sortes d'evenements n'ont jamais le meme rang.
     *
     * **Le garde-fou pour l'avenir.** Le jour ou une sorte sera ajoutee, elle devra recevoir son
     * propre rang. Lui laisser celui d'une autre rendrait l'ordre non deterministe entre les deux,
     * exactement le defaut que ce classement existe pour supprimer — et cela ne se verrait qu'en
     * production, sur deux evenements simultanes.
     */
    public function testNoTwoEventTypesShareARank(): void
    {
        $rangs = array_map(static fn (CombatEventType $t): int => $t->rank(), CombatEventType::cases());

        $this->assertSame(
            count($rangs),
            count(array_unique($rangs)),
            'Two event types share the same rank, so their order at the same second is left to chance.'
        );
    }

    /**
     * Une barriere se place avant tout evenement de la meme seconde.
     */
    public function testABarrierComesBeforeEveryEventOfTheSameSecond(): void
    {
        $barriere = EffectOrderKey::barrierAt(1_000);

        $this->assertTrue($barriere->isBarrier());

        foreach (CombatEventType::cases() as $type) {
            $evenement = EffectOrderKey::forEvent(1_000, $type, 1);

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
        $arrivee = EffectOrderKey::forEvent(1_000, CombatEventType::FleetArrival, 12);
        $missile = EffectOrderKey::forEvent(1_000, CombatEventType::MissileImpact, 12);

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
            EffectOrderKey::forEvent(1_000, CombatEventType::QueueCompletion, 3),
            EffectOrderKey::barrierAt(1_000),
            EffectOrderKey::forEvent(999, CombatEventType::MissileImpact, 50),
            EffectOrderKey::forEvent(1_000, CombatEventType::FleetArrival, 12),
            EffectOrderKey::forEvent(1_000, CombatEventType::FleetArrival, 7),
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

        // Et l'ordre obtenu est bien celui qu'on attend.
        $this->assertSame(
            ['999/2/50', '1000/0/0', '1000/1/7', '1000/1/12', '1000/3/3'],
            $attendu
        );
    }

    /**
     * Un evenement sans identifiant de source est refuse.
     */
    public function testAnEventWithoutASourceIdentifierIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EffectOrderKey::forEvent(1_000, CombatEventType::FleetArrival, 0);
    }
}
