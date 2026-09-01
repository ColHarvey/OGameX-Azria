<?php

namespace Tests\Unit\Combat;

use OGame\Combat\Services\CombatDurationEstimator;
use OGame\Combat\Support\CombatCalibrationScenarios;
use Tests\UnitTestCase;

/**
 * Les proprietes que la regle de duree doit tenir, quel que soit le reglage.
 *
 * Le tableau de calibrage montre ce que quatre batailles donnent ; il ne dit rien de ce qui
 * arrive **entre** elles. Choisir la racine cubique parce qu'elle rend bien sur ces
 * quatre-la serait choisir sur un echantillon. Ces tests verifient le comportement de la
 * regle elle-meme, par comparaison de batailles qui ne different que d'une chose.
 *
 * Chaque propriete est verifiee **sans amortissement et avec** : un amortissement qui
 * inverserait une de ces relations ne reglerait plus l'echelle, il changerait la regle.
 *
 * La duree plancher est mise a zero : elle masquerait les ecarts qu'on cherche justement a
 * mesurer.
 */
class CombatDurationPropertiesTest extends UnitTestCase
{
    /**
     * Les deux modeles compares : la regle nue, et la candidate.
     *
     * @return array<string, float>
     */
    private function modeles(): array
    {
        return ['sans amortissement' => 1.0, 'racine cubique' => 3.0];
    }

    /**
     * Compute the raw duration, floor removed so differences stay visible.
     */
    private function duree(array $rounds, float $amortissement): float
    {
        return (new CombatDurationEstimator())
            ->estimate(CombatCalibrationScenarios::fromRounds($rounds), 1.0, 0, $amortissement)
            ->rawSeconds;
    }

    /**
     * Assert that a one-sided strength advantage never blows up the duration.
     *
     * C'est la propriete la plus importante du modele : ce qui fait durer un combat est la
     * resistance mutuelle, pas la puissance. Une flotte ecrasante doit expedier la bataille,
     * pas l'allonger parce qu'elle est grosse.
     */
    public function testAOneSidedStrengthAdvantageNeverBlowsUpTheDuration(): void
    {
        $equilibre = [[2_000, 2_000, 300_000, 300_000, 5_000_000, 5_000_000]];
        $ecrasant = [[2_000, 2_000, 300_000, 300_000, 500_000_000, 5_000_000]];

        foreach ($this->modeles() as $nom => $amortissement) {
            $this->assertLessThanOrEqual(
                $this->duree($equilibre, $amortissement),
                $this->duree($ecrasant, $amortissement),
                "Avec « {$nom} », multiplier par cent la force d'un seul camp allonge le combat au lieu de l'expedier."
            );
        }
    }

    /**
     * Assert that adding comparable forces to both sides lengthens the battle.
     *
     * L'autre moitie de la propriete precedente : ce n'est pas la taille qui est ignoree,
     * c'est le desequilibre qui compte. Deux camps quatre fois plus gros doivent se battre
     * plus longtemps.
     */
    public function testAddingComparableForcesToBothSidesLengthensTheBattle(): void
    {
        $petit = [[2_000, 2_000, 300_000, 300_000, 5_000_000, 5_000_000]];
        $grand = [[8_000, 8_000, 1_200_000, 1_200_000, 20_000_000, 20_000_000]];

        foreach ($this->modeles() as $nom => $amortissement) {
            $this->assertGreaterThan(
                $this->duree($petit, $amortissement),
                $this->duree($grand, $amortissement),
                "Avec « {$nom} », quadrupler les deux camps ne rallonge pas le combat."
            );
        }
    }

    /**
     * Assert that an extra round never shortens the battle.
     */
    public function testAnExtraRoundNeverShortensTheBattle(): void
    {
        $round = [2_000, 2_000, 300_000, 300_000, 5_000_000, 5_000_000];

        foreach ($this->modeles() as $nom => $amortissement) {
            $this->assertGreaterThanOrEqual(
                $this->duree([$round], $amortissement),
                $this->duree([$round, $round], $amortissement),
                "Avec « {$nom} », un round supplementaire raccourcit le combat."
            );
        }
    }

    /**
     * Assert that more absorbed shield damage never shortens the battle.
     */
    public function testMoreAbsorbedShieldDamageNeverShortensTheBattle(): void
    {
        $faible = [[2_000, 2_000, 100_000, 100_000, 5_000_000, 5_000_000]];
        $fort = [[2_000, 2_000, 900_000, 900_000, 5_000_000, 5_000_000]];

        foreach ($this->modeles() as $nom => $amortissement) {
            $this->assertGreaterThanOrEqual(
                $this->duree($faible, $amortissement),
                $this->duree($fort, $amortissement),
                "Avec « {$nom} », des boucliers plus sollicites raccourcissent le combat."
            );
        }
    }

    /**
     * Assert that no computation ever produces infinity or not-a-number.
     *
     * Le travail brut atteint des ordres de grandeur inhabituels — 10^24 sur une bataille
     * d'armadas. Une division par zero, une racine d'un negatif ou un debordement rendraient
     * une duree inexploitable sans qu'aucune assertion de valeur ne s'en apercoive.
     */
    public function testNoComputationProducesInfinityOrNotANumber(): void
    {
        $extremes = [
            'tout a zero' => [[0, 0, 0, 0, 0, 0]],
            'un camp absent' => [[5_000, 0, 400_000, 0, 9_000_000, 0]],
            'forces enormes' => [[9_000_000, 9_000_000, 900_000_000, 900_000_000, 9_000_000_000, 9_000_000_000]],
            'un seul tir' => [[1, 1, 1, 1, 1, 1]],
        ];

        foreach ($this->modeles() as $nomModele => $amortissement) {
            foreach ($extremes as $nom => $rounds) {
                $estimation = (new CombatDurationEstimator())
                    ->estimate(CombatCalibrationScenarios::fromRounds($rounds), 1.0, 0, $amortissement);

                $this->assertTrue(is_finite($estimation->totalWork), "« {$nom} » avec « {$nomModele} » : le travail n'est pas fini.");
                $this->assertTrue(is_finite($estimation->rawSeconds), "« {$nom} » avec « {$nomModele} » : la duree brute n'est pas finie.");
                $this->assertFalse(is_nan($estimation->totalWork), "« {$nom} » avec « {$nomModele} » : le travail est NaN.");
                $this->assertFalse(is_nan($estimation->rawSeconds), "« {$nom} » avec « {$nomModele} » : la duree brute est NaN.");
                $this->assertGreaterThanOrEqual(0, $estimation->seconds, "« {$nom} » avec « {$nomModele} » : la duree est negative.");
            }
        }
    }

    /**
     * Assert that nothing is cast to an integer before the final duration.
     *
     * Le travail d'une grande bataille depasse ce qu'un entier PHP contient — 8 x 10^24 contre
     * 9,2 x 10^18. Une conversion trop tot ecreterait la valeur en silence, et deux batailles
     * tres differentes rendraient la meme duree.
     */
    public function testNothingIsCastToAnIntegerBeforeTheFinalDuration(): void
    {
        $enorme = CombatCalibrationScenarios::fromRounds([
            [900_000, 900_000, 900_000_000, 900_000_000, 9_000_000_000, 9_000_000_000],
        ]);

        $estimation = (new CombatDurationEstimator())->estimate($enorme, 1.0, 0, 1.0);

        $this->assertGreaterThan(
            (float)PHP_INT_MAX,
            $estimation->rawSeconds,
            'The raw duration fits in an integer, so this battle no longer exercises the overflow it was built for.'
        );

        // La seule borne est celle du langage, et elle n'intervient qu'a la toute fin.
        $this->assertSame(PHP_INT_MAX, $estimation->seconds, 'The usable duration is not clamped at the language limit.');
        $this->assertTrue($estimation->implausible, 'A duration beyond any plausible battle is not flagged for the technical alert.');
    }

    /**
     * Assert that two battles differing only in scale still differ in duration.
     *
     * Corollaire du test precedent : si une conversion prematuree ecretait le calcul, deux
     * batailles separees par un facteur mille rendraient la meme duree.
     */
    public function testTwoBattlesSeparatedByAThousandStillDifferInDuration(): void
    {
        $petite = [[1_000, 1_000, 100_000, 100_000, 1_000_000, 1_000_000]];
        $grande = [[1_000_000, 1_000_000, 100_000_000, 100_000_000, 1_000_000_000, 1_000_000_000]];

        foreach ($this->modeles() as $nom => $amortissement) {
            $this->assertGreaterThan(
                $this->duree($petite, $amortissement) * 10.0,
                $this->duree($grande, $amortissement),
                "Avec « {$nom} », mille fois plus gros ne se distingue plus : une precision a ete perdue en route."
            );
        }
    }

    /**
     * Assert that no gameplay maximum is ever applied, whatever the model.
     */
    public function testNoGameplayMaximumIsEverApplied(): void
    {
        foreach ($this->modeles() as $nom => $amortissement) {
            $estimation = (new CombatDurationEstimator())->estimate(
                CombatCalibrationScenarios::fromRounds([[500_000, 500_000, 900_000_000, 900_000_000, 9_000_000_000, 9_000_000_000]]),
                1.0,
                5,
                $amortissement
            );

            $this->assertGreaterThan(
                CombatDurationEstimator::IMPLAUSIBLE_SECONDS,
                $estimation->rawSeconds,
                "Avec « {$nom} », une duree a ete rabotee : la regle dit qu'il n'y a pas de plafond de jeu."
            );
        }
    }
}
