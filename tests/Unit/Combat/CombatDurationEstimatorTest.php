<?php

namespace Tests\Unit\Combat;

use OGame\Combat\Services\CombatDurationEstimator;
use OGame\Combat\Support\CombatCalibrationScenarios;
use Tests\UnitTestCase;

/**
 * Le calculateur de duree rend-il ce que la regle annonce ?
 *
 * Service pur : ni base de donnees, ni reglages, ni horloge. Ces tests s'en tiennent donc a la
 * regle elle-meme, sur des batailles ecrites a la main — un vrai combat varierait d'une
 * execution a l'autre, et une duree qui bouge toute seule ne prouve rien.
 *
 * Ce qui est verifie ici : la **forme**. L'echelle — le coefficient de rythme — est un choix
 * de jeu, pas une propriete du moteur, et se calibre avec `ogamex:combat:durees`.
 */
class CombatDurationEstimatorTest extends UnitTestCase
{
    /**
     * Assert that the prototype model still produces the durations that were agreed.
     *
     * Racine cubique, rythme 2083, plancher 5 s, aucun plafond. Ces trois nombres n'ont pas ete
     * choisis au jugé : ils viennent du tableau de calibrage, et ils decrivent une **sensation
     * de jeu** — un combat courant de quelques minutes, une grande bataille d'une vingtaine de
     * minutes, deux armadas de deux heures.
     *
     * Une constante qu'on modifie sans y penser deplacerait tout cela en silence. Ce test rend
     * ce changement visible : le faire tomber doit etre une decision, jamais une derive.
     */
    public function testThePrototypeModelStillProducesTheAgreedDurations(): void
    {
        $attendu = [
            'Ecrasement — petite flotte contre grosse defense' => 5,
            'Nuee de sondes contre une defense' => 5,
            'Flotte contre planete sans defense' => 5,
            'Forces moyennes comparables' => 134,
            'Attaquant legerement superieur' => 200,
            'Etoiles de la mort contre grande defense' => 276,
            'Grande flotte contre grande defense' => 1188,
            'Tres grandes forces equilibrees' => 7413,
        ];

        $calculateur = new CombatDurationEstimator();

        foreach (CombatCalibrationScenarios::all() as $nom => $bataille) {
            $this->assertArrayHasKey($nom, $attendu, "The calibration scenario « {$nom} » has no agreed duration: the table and this test have drifted apart.");

            $this->assertEqualsWithDelta(
                $attendu[$nom],
                $calculateur->estimate($bataille)->seconds,
                2,
                "« {$nom} » no longer lasts what the calibration table agreed on. Changing the model is a game decision, so make it deliberately."
            );
        }

        $this->assertCount(
            count($attendu),
            CombatCalibrationScenarios::all(),
            'A calibration scenario was added or removed without agreeing on its duration.'
        );
    }

    /**
     * Assert that a battle with no round resolves immediately.
     *
     * Une retraite tactique ne doit pas ouvrir un combat de deux heures. Le minimum ne
     * s'applique pas non plus : il est fait pour les batailles qui ont eu lieu.
     */
    public function testABattleWithoutRoundsResolvesImmediately(): void
    {
        $estimation = (new CombatDurationEstimator())->estimate(CombatCalibrationScenarios::fromRounds([]));

        $this->assertTrue($estimation->instant, 'A battle with no rounds is not marked as instant.');
        $this->assertSame(0, $estimation->seconds, 'A tactical retreat would open a lasting combat.');
        $this->assertFalse($estimation->minimumApplied, 'The minimum duration was applied to a battle that never happened.');
    }

    /**
     * Assert that a crushing defeat lasts far less than an even fight.
     *
     * C'est la propriete centrale de la regle, et la seule qui ne depende d'aucun reglage :
     * l'equilibre des forces, pas leur taille, fait durer un combat.
     */
    public function testACrushingDefeatLastsFarLessThanAnEvenFight(): void
    {
        $calculateur = new CombatDurationEstimator();
        $scenarios = CombatCalibrationScenarios::all();

        $ecrasement = $calculateur->estimate($scenarios['Ecrasement — petite flotte contre grosse defense']);
        $equilibre = $calculateur->estimate($scenarios['Forces moyennes comparables']);

        $this->assertLessThan(
            $equilibre->totalWork,
            $ecrasement->totalWork,
            'A crushing defeat produces as much combat work as an even fight, so the balance factor is not doing its job.'
        );
    }

    /**
     * Assert that the balance factor, not sheer size, drives the duration.
     *
     * Deux camps dix fois plus gros mais aussi desequilibres ne doivent pas produire un combat
     * plus long *par equilibre* : c'est la taille qui monte, pas la resistance mutuelle.
     */
    public function testTheBalanceFactorFallsWithTheImbalance(): void
    {
        $calculateur = new CombatDurationEstimator();

        $equilibre = $calculateur->estimate(CombatCalibrationScenarios::fromRounds([
            [1_000, 1_000, 100_000, 100_000, 5_000_000, 5_000_000],
        ]));

        $desequilibre = $calculateur->estimate(CombatCalibrationScenarios::fromRounds([
            [1_000, 1_000, 100_000, 100_000, 5_000_000, 50_000],
        ]));

        $this->assertEqualsWithDelta(1.0, $equilibre->rounds[0]->balance, 0.0001, 'Two equal forces do not give a balance factor of 1.');
        $this->assertLessThan(0.15, $desequilibre->rounds[0]->balance, 'A hundredfold imbalance still reads as a balanced fight.');
    }

    /**
     * Assert that the minimum duration is respected and reported.
     */
    public function testTheMinimumDurationIsRespectedAndReported(): void
    {
        $minuscule = CombatCalibrationScenarios::fromRounds([[1, 1, 1, 1, 10, 10]]);

        $estimation = (new CombatDurationEstimator())->estimate($minuscule, 1.0e18, 30);

        $this->assertSame(30, $estimation->seconds, 'The configured minimum duration was not applied.');
        $this->assertTrue($estimation->minimumApplied, 'The estimate does not report that the minimum was applied.');
    }

    /**
     * Assert that no maximum duration is ever imposed.
     *
     * Regle explicite : aucun plafond de jeu. Une bataille dont le calcul donne des jours doit
     * rendre des jours — c'est une alerte technique, pas une valeur a raboter.
     */
    public function testNoMaximumDurationIsEverImposed(): void
    {
        $enorme = CombatCalibrationScenarios::fromRounds([
            [500_000, 500_000, 900_000_000, 900_000_000, 9_000_000_000, 9_000_000_000],
        ]);

        $estimation = (new CombatDurationEstimator())->estimate($enorme, 1.0, 5);

        $this->assertGreaterThan(
            86_400,
            $estimation->seconds,
            'A duration was capped: the rule says there is no gameplay maximum, only a technical alert.'
        );
    }

    /**
     * Assert that the same battle always yields the same duration.
     *
     * La regle doit rendre le meme resultat pour la meme entree, quel que soit le moteur qui
     * l'a produite : le calculateur ne lit que le `BattleResult`, jamais le moteur.
     */
    public function testTheSameBattleAlwaysYieldsTheSameDuration(): void
    {
        $calculateur = new CombatDurationEstimator();

        foreach (CombatCalibrationScenarios::all() as $nom => $bataille) {
            $premier = $calculateur->estimate($bataille);
            $second = $calculateur->estimate($bataille);

            $this->assertSame($premier->seconds, $second->seconds, "The scenario « {$nom} » gives two different durations for the same battle.");
        }
    }

    /**
     * Assert that the rate coefficient scales the duration and nothing else.
     *
     * C'est ce qui rend le calibrage possible : changer le rythme change l'echelle, jamais
     * l'ordre relatif des scenarios.
     */
    public function testTheRateScalesTheDurationWithoutChangingTheOrder(): void
    {
        $calculateur = new CombatDurationEstimator();
        $scenarios = CombatCalibrationScenarios::all();

        $ordre = function (float $rythme) use ($calculateur, $scenarios): array {
            $durees = [];

            foreach ($scenarios as $nom => $bataille) {
                $durees[$nom] = $calculateur->estimate($bataille, $rythme, 0)->totalWork;
            }

            arsort($durees);

            return array_keys($durees);
        };

        $this->assertSame(
            $ordre(1.0e18),
            $ordre(1.0e12),
            'Changing the pace coefficient reorders the scenarios, so it does more than set the scale.'
        );
    }

    /**
     * Assert that damping compresses the spread without reordering the scenarios.
     *
     * La formule multiplie quatre grandeurs qui croissent chacune avec la taille des flottes :
     * mesure sur les scenarios de calibrage, le travail s'etale sur **onze ordres de grandeur**,
     * et les rythmes qu'il faudrait pour donner a chacun une duree sensee s'etalent eux-memes
     * sur huit. Aucun coefficient unique ne convient.
     *
     * L'amortissement comprime cet ecart. Ce qu'il ne doit jamais faire, c'est changer quelle
     * bataille dure le plus longtemps — sans quoi il ne reglerait plus l'echelle mais la regle.
     */
    public function testDampingCompressesTheSpreadWithoutReorderingTheScenarios(): void
    {
        $calculateur = new CombatDurationEstimator();
        $scenarios = CombatCalibrationScenarios::all();

        $durees = function (float $amortissement) use ($calculateur, $scenarios): array {
            $sortie = [];

            foreach ($scenarios as $nom => $bataille) {
                $sortie[$nom] = $calculateur->estimate($bataille, 1.0, 0, $amortissement)->rawSeconds;
            }

            return $sortie;
        };

        $brut = $durees(1.0);
        $amorti = $durees(3.0);

        // Le garde n'est pas decoratif : sans scenario, l'etendue n'a pas de sens et le test
        // passerait en ne mesurant rien.
        $etendue = function (array $valeurs): float {
            if ($valeurs === []) {
                $this->fail('There is no calibration scenario left to measure.');
            }

            return max($valeurs) / max(min($valeurs), 1.0);
        };

        $this->assertGreaterThan(
            1.0e9,
            $etendue($brut),
            'The raw spread is no longer huge, so the calibration scenarios no longer cover the real range of battles.'
        );

        $this->assertLessThan(
            $etendue($brut) / 1000.0,
            $etendue($amorti),
            'Damping barely compresses the spread, so a single pace coefficient still cannot suit both a rout and an armada battle.'
        );

        arsort($brut);
        arsort($amorti);

        $this->assertSame(
            array_keys($brut),
            array_keys($amorti),
            'Damping reorders the scenarios: it changes the rule instead of only setting the scale.'
        );
    }

    /**
     * Assert that the round schedule adds up to exactly the announced duration.
     *
     * Sans cela, le compte a rebours du joueur et le calendrier des rounds divergeraient — et
     * c'est precisement le genre d'ecart d'une seconde qui a deja coute cher sur ce projet.
     */
    public function testTheRoundScheduleAddsUpToTheAnnouncedDuration(): void
    {
        $calculateur = new CombatDurationEstimator();

        foreach (CombatCalibrationScenarios::all() as $nom => $bataille) {
            $estimation = $calculateur->estimate($bataille);

            $somme = 0;

            foreach ($estimation->rounds as $round) {
                $somme += $round->seconds;
            }

            $this->assertSame(
                $estimation->seconds,
                $somme,
                "The round schedule of « {$nom} » does not add up to the announced duration."
            );
        }
    }
}
