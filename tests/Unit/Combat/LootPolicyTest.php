<?php

namespace Tests\Unit\Combat;

use InvalidArgumentException;
use OGame\Combat\Enums\HonorPolicy;
use OGame\Combat\Policies\CargoWeightedV1;
use OGame\Combat\Support\AttackerCargoShare;
use OGame\Combat\Support\LootPolicy;
use Tests\UnitTestCase;

/**
 * Le taux de pillage : `cargo_weighted_v1`.
 *
 *     cible active   : 50 %
 *     cible inactive : 50 % + 25 % x (fret Decouvreur engage / fret total engage)
 *
 * La regle remplace celle du moteur actuel, qui lit `$attackers[0]` et applique le bonus de ce
 * joueur-la a toute l'attaque groupee : une sonde de Decouvreur arrivee en tete faisait passer
 * tout le butin de 50 % a 75 %.
 */
class LootPolicyTest extends UnitTestCase
{
    /**
     * Les taux annonces, cas par cas.
     */
    public function testTheAnnouncedRates(): void
    {
        $attendus = [
            'cible active, que des Decouvreurs' => [false, 1_000, 1_000, 5_000],
            'cible inactive, aucun Decouvreur' => [true, 0, 1_000, 5_000],
            'cible inactive, que des Decouvreurs' => [true, 1_000, 1_000, 7_500],
            'cible inactive, fret partage en deux' => [true, 500, 1_000, 6_250],
            'cible inactive, un dixieme du fret' => [true, 100, 1_000, 5_250],
            'cible inactive, une sonde dans une armada' => [true, 5, 1_000_000, 5_000],
            'cible inactive, aucun fret engage' => [true, 0, 0, 5_000],
        ];

        foreach ($attendus as $quoi => [$inactive, $decouvreur, $total, $attendu]) {
            $politique = new LootPolicy($inactive, new AttackerCargoShare($decouvreur, $total));

            $this->assertSame(
                $attendu,
                $politique->maximumRateInBasisPoints(),
                "The rate for « {$quoi} » is no longer the one decided for cargo_weighted_v1."
            );
        }
    }

    /**
     * Permuter les participants ne change rien.
     *
     * **L'exigence qui a dicte la conception.** Le taux ne depend que de deux sommes, et une somme
     * est commutative : l'ordre est donc structurellement incapable d'influer. Ce test le verifie
     * sur toutes les permutations d'un groupe mixte.
     */
    public function testPermutingTheParticipantsChangesNothing(): void
    {
        // Cinq joueurs : deux Decouvreurs, trois non. Frets volontairement inegaux.
        $participants = [
            [12_000, true],
            [3_000, false],
            [45_000, true],
            [7_000, false],
            [130_000, false],
        ];

        $taux = null;

        foreach ($this->permutationsOf($participants) as $ordre) {
            $part = AttackerCargoShare::none();

            foreach ($ordre as [$fret, $estDecouvreur]) {
                $part = $part->plus($fret, $estDecouvreur);
            }

            $obtenu = (new LootPolicy(true, $part))->maximumRateInBasisPoints();

            $taux ??= $obtenu;

            $this->assertSame($taux, $obtenu, 'Permuting the participants changed the loot rate.');
        }

        // Et la valeur elle-meme : 57 000 sur 197 000 de fret.
        $this->assertSame(5_000 + 723, $taux);
    }

    /**
     * Plusieurs flottes du meme joueur s'agregent correctement.
     */
    public function testSeveralFleetsOfTheSamePlayerAggregate(): void
    {
        $enUnSeulBloc = AttackerCargoShare::none()->plus(30_000, true)->plus(70_000, false);

        $enPlusieursVagues = AttackerCargoShare::none()
            ->plus(10_000, true)
            ->plus(20_000, true)
            ->plus(40_000, false)
            ->plus(30_000, false);

        $this->assertSame(
            (new LootPolicy(true, $enUnSeulBloc))->maximumRateInBasisPoints(),
            (new LootPolicy(true, $enPlusieursVagues))->maximumRateInBasisPoints(),
            'Splitting a player fleet into waves changed the rate.'
        );
    }

    /**
     * Un participant sans fret est accepte et n'influence pas le taux.
     */
    public function testAParticipantWithNoCargoChangesNothing(): void
    {
        $sans = AttackerCargoShare::none()->plus(1_000, true)->plus(1_000, false);
        $avec = AttackerCargoShare::none()->plus(1_000, true)->plus(0, true)->plus(1_000, false)->plus(0, false);

        $this->assertSame(
            (new LootPolicy(true, $sans))->maximumRateInBasisPoints(),
            (new LootPolicy(true, $avec))->maximumRateInBasisPoints(),
        );
    }

    /**
     * Un acteur pilote par le serveur n'obtient aucun bonus de classe.
     *
     * Un pirate n'a pas de classe, et ne doit surtout pas heriter du comportement d'un Decouvreur
     * faute d'avoir ete traite explicitement.
     */
    public function testAServerDrivenAttackerGetsNoClassBonus(): void
    {
        foreach ([true, false] as $cibleInactive) {
            $this->assertSame(
                5_000,
                LootPolicy::forNpcAttacker($cibleInactive)->maximumRateInBasisPoints(),
                'A server-driven attacker received a class bonus it has no class for.'
            );
        }
    }

    /**
     * Le systeme d'honneur est desactive, et ne peut donc jamais gagner le maximum.
     */
    public function testTheHonorSystemIsDisabledAndNeverWins(): void
    {
        $this->assertSame(0, HonorPolicy::Disabled->minimumRateInBasisPoints());

        $politique = new LootPolicy(true, new AttackerCargoShare(0, 1_000), HonorPolicy::Disabled);

        $this->assertSame(5_000, $politique->maximumRateInBasisPoints());
    }

    /**
     * Le taux reste dans ses bornes, quelle que soit la part.
     */
    public function testTheRateStaysBetweenItsBounds(): void
    {
        $total = 10_000;

        for ($decouvreur = 0; $decouvreur <= $total; $decouvreur += 137) {
            $taux = (new LootPolicy(true, new AttackerCargoShare($decouvreur, $total)))->maximumRateInBasisPoints();

            $this->assertGreaterThanOrEqual(CargoWeightedV1::BASE_RATE, $taux);
            $this->assertLessThanOrEqual(CargoWeightedV1::BASE_RATE + CargoWeightedV1::DISCOVERER_BONUS, $taux);
        }
    }

    /**
     * Les frets impossibles sont refuses, jamais convertis.
     *
     * **Le garde-fou reste necessaire.** La capacite de fret n'a pas de borne formelle : le bonus
     * de recherche vaut cinq pour cent par niveau d'hyperespace, et cette colonne est un entier
     * sans plafond applicatif. Un total impossible signale donc une donnee corrompue, et doit
     * s'arreter plutot que basculer en flottant.
     */
    public function testImpossibleCargoValuesAreRefused(): void
    {
        $refus = [
            'fret negatif' => static fn (): AttackerCargoShare => new AttackerCargoShare(-1, 10),
            'total negatif' => static fn (): AttackerCargoShare => new AttackerCargoShare(0, -1),
            'part superieure au total' => static fn (): AttackerCargoShare => new AttackerCargoShare(11, 10),
            'ajout negatif' => static fn (): AttackerCargoShare => AttackerCargoShare::none()->plus(-1, false),
            'somme qui deborderait' => static fn (): AttackerCargoShare => (new AttackerCargoShare(0, PHP_INT_MAX))->plus(1, false),
        ];

        foreach ($refus as $quoi => $tentative) {
            try {
                $tentative();
                $this->fail("The cargo value « {$quoi} » was accepted.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * La version de la politique est nommee, et persistee avec chaque combat.
     *
     * Changer la formule plus tard ne doit toucher que les combats suivants.
     */
    public function testThePolicyVersionIsNamed(): void
    {
        $this->assertSame('cargo_weighted_v1', CargoWeightedV1::VERSION);
    }

    /**
     * Toutes les permutations d'une liste.
     *
     * @param array<int, array{0: int, 1: bool}> $elements
     * @return array<int, array<int, array{0: int, 1: bool}>>
     */
    private function permutationsOf(array $elements): array
    {
        if (count($elements) <= 1) {
            return [$elements];
        }

        $permutations = [];

        foreach ($elements as $rang => $element) {
            $reste = $elements;
            unset($reste[$rang]);

            foreach ($this->permutationsOf(array_values($reste)) as $suite) {
                $permutations[] = array_merge([$element], $suite);
            }
        }

        return $permutations;
    }
}
