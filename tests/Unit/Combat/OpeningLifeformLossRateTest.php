<?php

namespace Tests\Unit\Combat;

use OGame\Combat\Exceptions\CorruptedFrozenMoonPlan;
use OGame\Combat\Exceptions\MissingOpeningState;
use OGame\Combat\Services\OpeningStateRecorder;
use OGame\Models\CombatInstance;
use ReflectionMethod;
use Tests\UnitTestCase;

/**
 * Le taux de morts de population que l etat d ouverture porte (version 9, journal §155.20) : relu tel qu ecrit,
 * refuse s il manque ou sort de ses bornes, et **jamais remplace par le reglage courant** — un document ecrit
 * avant la version 9 garde la regle de son epoque, cent.
 */
class OpeningLifeformLossRateTest extends UnitTestCase
{
    public function testAVersionNineDocumentGivesBackTheRateItWasOpenedUnder(): void
    {
        $this->assertSame(40, OpeningStateRecorder::openingLifeformLossPercentOf($this->aCombatOpenedWith(['version' => 9, 'lifeform_rules' => ['population_loss_percent' => 40]])));
        $this->assertSame(0, OpeningStateRecorder::openingLifeformLossPercentOf($this->aCombatOpenedWith(['version' => 9, 'lifeform_rules' => ['population_loss_percent' => 0]])));
        $this->assertSame(100, OpeningStateRecorder::openingLifeformLossPercentOf($this->aCombatOpenedWith(['version' => 9, 'lifeform_rules' => ['population_loss_percent' => 100]])));
    }

    /**
     * **Une version anterieure garde sa regle** : cent, la seule sous laquelle elle a pu etre ecrite. Le reglage du
     * moment vaut 25 dans ce banc, et il n est jamais lu.
     */
    public function testADocumentWrittenBeforeVersionNineKeepsTheHardRuleAndNeverTheCurrentRate(): void
    {
        $this->assertSame(25, $this->settingsService->lifeformPopulationLossPercent(), 'Premisse : le reglage courant est bien different de cent.');
        $this->assertSame(100, OpeningStateRecorder::openingLifeformLossPercentOf($this->aCombatOpenedWith(['version' => 8])));
        $this->assertSame(100, OpeningStateRecorder::openingLifeformLossPercentOf($this->aCombatOpenedWith(['version' => 7])));
        $this->assertSame(100, OpeningStateRecorder::openingLifeformLossPercentOf($this->aCombatOpenedWith(['version' => 8, 'lifeform_rules' => ['population_loss_percent' => 40]])), 'Une version 8 ne porte pas de regles : ce qui s y trouverait serait une reparation a la main, et ne se lit pas.');
    }

    public function testAVersionNineDocumentWithoutRulesOrOutOfRangeIsRefused(): void
    {
        try {
            OpeningStateRecorder::openingLifeformLossPercentOf($this->aCombatOpenedWith(['version' => 9]));
            $this->fail('A version 9 document without lifeform rules was read.');
        } catch (MissingOpeningState $refus) {
            $this->assertStringContainsString('regles de formes de vie', $refus->getMessage());
        }

        foreach ([-1, 101] as $horsBornes) {
            try {
                OpeningStateRecorder::openingLifeformLossPercentOf($this->aCombatOpenedWith(['version' => 9, 'lifeform_rules' => ['population_loss_percent' => $horsBornes]]));
                $this->fail('A rate of ' . $horsBornes . ' was read.');
            } catch (MissingOpeningState $refus) {
                $this->assertStringContainsString('entre 0 et 100', $refus->getMessage());
            }
        }

        // Un flottant a valeur entiere (25.0) devient 25 dans la colonne JSON : il n est pas un cas observable.
        foreach (['25', null] as $mauvais) {
            try {
                OpeningStateRecorder::openingLifeformLossPercentOf($this->aCombatOpenedWith(['version' => 9, 'lifeform_rules' => ['population_loss_percent' => $mauvais]]));
                $this->fail('A rate of type ' . get_debug_type($mauvais) . ' was read.');
            } catch (CorruptedFrozenMoonPlan $refus) {
                $this->assertStringContainsString('population_loss_percent', $refus->getMessage());
            }
        }
    }

    /**
     * Un combat dont l etat d ouverture est exactement celui-ci, empreinte comprise : la lecture verifie l empreinte
     * avant de lire quoi que ce soit.
     *
     * @param array<string, mixed> $etat
     */
    private function aCombatOpenedWith(array $etat): CombatInstance
    {
        $etat += ['captured_at' => 1_700_000_000, 'target_body_id' => 1, 'owner_id' => 1];
        $empreinte = new ReflectionMethod(OpeningStateRecorder::class, 'fingerprintOf');
        $combat = new CombatInstance();
        $combat->forceFill([
            'id' => 1,
            'opening_state' => $etat,
            'opening_state_fingerprint' => $empreinte->invoke(null, $etat),
        ]);

        return $combat;
    }
}
