<?php

namespace Tests\Unit\BattleEngine;

use OGame\Enums\CharacterClass;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameMissions\BattleEngine\Models\DefenderFleetResult;
use OGame\GameMissions\BattleEngine\Parity\CanonicalProjection;
use OGame\GameMissions\BattleEngine\PhpBattleEngine;
use OGame\GameMissions\BattleEngine\RustBattleEngine;
use Tests\UnitTestCase;

/**
 * **Les deux moteurs rendent le meme etat de coque.**
 *
 * ## Ce que ces essais remplacent
 *
 * Ils etaient, jusqu au 13 septembre 2026, des **epingles** : ils decrivaient deux divergences connues et
 * laissaient l integration continue verte. Les deux ecarts sont corriges, et les epingles sont devenues ce
 * qu elles devaient devenir — des **exigences d egalite**. Les supprimer aurait ete perdre le seul regard porte
 * sur les coques.
 *
 * ## Pourquoi rien ne les voyait
 *
 * La projection canonique, que le banc de parite compare, **ne porte pas les coques** : son empreinte est figee
 * par les vecteurs de reference, et on ne lui ajoute pas de clef. Les coques se comparent donc par
 * `CanonicalProjection::hullsOf()`, une projection a part — employee ici et par le banc, sur tous ses scenarios.
 *
 * ## Les deux ecarts qui vivaient la
 *
 * 1. **Sans round**, le moteur Rust ne fixait pas les coques des survivants : une flotte entamee rentrait
 *    reparee. Le chemin est atteignable en jeu — attaquer un corps sans defense — et la manoeuvre de Hamill qui
 *    prend le dernier defenseur en ouvre un second.
 * 2. **Les defenses survivantes** : la bibliotheque rend l etat de toutes les unites, le moteur PHP ne garde que
 *    les vaisseaux — une defense a deja sa propre reparation, et lui donner une coque persistante ferait deux
 *    mecanismes concurrents. C est le moteur PHP qui fait reference ; le tri se fait desormais des deux cotes.
 */
final class RustSurvivorHullParityTest extends UnitTestCase
{
    use BuildsParityScenarios;

    private int $chance = 1_000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipWhenTheRustLibraryIsUnavailable();

        $this->chance = $this->settingsService->hamillManoeuvreChance();
        $this->settingsService->set('hamill_manoeuvre_chance', 1);
    }

    protected function tearDown(): void
    {
        $this->settingsService->set('hamill_manoeuvre_chance', $this->chance);

        parent::tearDown();
    }

    /**
     * **Sans round, la flotte entamee ressort telle qu elle est entree** — et les deux moteurs le disent
     * pareil.
     */
    public function testWithoutARoundBothEnginesReturnTheSameSurvivorHulls(): void
    {
        $bataille = $this->uneFlotteEntameeContreUneSeuleEtoile();

        $php = $this->fight(PhpBattleEngine::class, $bataille);
        $rust = $this->fight(RustBattleEngine::class, $bataille);

        // Les premisses : la manoeuvre s est jouee, aucun round, et la flotte est entiere des deux cotes.
        foreach (['PHP' => $php, 'Rust' => $rust] as $moteur => $resultat) {
            $this->assertTrue($resultat->hamillManoeuvreTriggered, $moteur . ' : la manoeuvre ne s est pas jouee.');
            $this->assertSame([], $resultat->rounds, $moteur . ' : un round s est joue, le chemin vise n est pas atteint.');
            $this->assertSame(30, $resultat->attackerUnitsResult->getAmountByMachineName('light_fighter'), $moteur . ' : la flotte n est pas rentree entiere.');
        }

        // **L essai ne prouverait rien sur deux etats vides** : la flotte doit bien ressortir entamee.
        $this->assertFalse($php->attackerFleetResults[0]->survivorHulls()->isEmpty(), 'Le moteur PHP ne rend plus l etat des survivants : l essai comparerait deux riens.');

        $this->assertSame(
            $php->attackerFleetResults[0]->survivorHulls()->toStorage(),
            $rust->attackerFleetResults[0]->survivorHulls()->toStorage(),
            'Sans round, les deux moteurs ne rendent pas le meme etat de coque : une flotte entamee rentre reparee d un cote.'
        );

        $this->assertNull($this->divergenceDesCoques($php, $rust), 'Les deux moteurs divergent sur les coques : ' . $this->divergenceDesCoques($php, $rust));
    }

    /**
     * **Les defenses n ont pas de coque persistante, des deux cotes** — et les vaisseaux du renfort, si.
     *
     * Comparer deux etats vides ne prouverait rien : le renfort entre entame et survit, ce qui donne a
     * l egalite quelque chose a comparer.
     */
    public function testTheSurvivingDefencesCarryNoHullOnEitherEngine(): void
    {
        $bataille = $this->uneGarnisonEntameeEtUnRenfortEntame();

        $php = $this->fight(PhpBattleEngine::class, $bataille);
        $rust = $this->fight(RustBattleEngine::class, $bataille);

        foreach (['PHP' => $php, 'Rust' => $rust] as $moteur => $resultat) {
            $this->assertNotSame([], $resultat->rounds, $moteur . ' : aucun round, la garnison n a pas combattu.');
            $this->assertGreaterThan(0, $resultat->defenderUnitsResult->getAmountByMachineName('rocket_launcher'), $moteur . ' : la garnison a ete balayee, aucun survivant a decrire.');
            $this->assertGreaterThan(0, $resultat->defenderUnitsResult->getAmountByMachineName('cruiser'), $moteur . ' : le renfort a ete balaye, aucun survivant a decrire.');

            $this->assertTrue(
                $this->laFlotteDefensive($resultat, 0)->survivorHulls()->isEmpty(),
                $moteur . ' : une defense survivante porte une coque persistante, alors qu elle a deja sa propre reparation.'
            );
        }

        // Le renfort, lui, garde son etat : c est ce qui rend l egalite non vide.
        $this->assertFalse($this->laFlotteDefensive($php, 2_000)->survivorHulls()->isEmpty(), 'Le renfort ressort intact : l essai comparerait deux riens.');

        $this->assertSame(
            $this->laFlotteDefensive($php, 2_000)->survivorHulls()->toStorage(),
            $this->laFlotteDefensive($rust, 2_000)->survivorHulls()->toStorage(),
            'Les deux moteurs ne rendent pas le meme etat de coque pour le renfort survivant.'
        );

        $this->assertNull($this->divergenceDesCoques($php, $rust), 'Les deux moteurs divergent sur les coques : ' . $this->divergenceDesCoques($php, $rust));
    }

    private function divergenceDesCoques(BattleResult $php, BattleResult $rust): string|null
    {
        return CanonicalProjection::firstDivergence(
            CanonicalProjection::hullsOf($php),
            CanonicalProjection::hullsOf($rust)
        );
    }

    private function laFlotteDefensive(BattleResult $resultat, int $fleetMissionId): DefenderFleetResult
    {
        foreach ($resultat->defenderFleetResults as $flotte) {
            if ($flotte->fleetMissionId === $fleetMissionId) {
                return $flotte;
            }
        }

        $this->fail('La flotte defensive ' . $fleetMissionId . ' manque au resultat.');
    }

    /**
     * Un General dont les chasseurs sont entames, contre une garnison reduite a une Etoile de la mort.
     *
     * @return array{attaquantes: array<int, \OGame\GameMissions\BattleEngine\Models\AttackerFleet>, defenseurs: array<int, \OGame\GameMissions\BattleEngine\Models\DefenderFleet>, cible: \OGame\Services\PlanetService, contexte: \OGame\Combat\Support\LootContext}
     */
    private function uneFlotteEntameeContreUneSeuleEtoile(): array
    {
        return $this->aBattle(
            planete: ['metal' => 100_000, 'crystal' => 50_000, 'deathstar' => 1],
            attaquantes: [
                [
                    'units' => ['light_fighter' => 30],
                    'tech' => ['weapon_technology' => 6, 'shielding_technology' => 5, 'armor_technology' => 6],
                    'classe' => CharacterClass::GENERAL,
                    'degats' => ['light_fighter' => [4_000 => 12]],
                ],
            ],
        );
    }

    /**
     * Une garnison de lance-missiles entames qu une petite flotte ne peut pas balayer, et un renfort de
     * croiseurs entames qui survit lui aussi.
     *
     * @return array{attaquantes: array<int, \OGame\GameMissions\BattleEngine\Models\AttackerFleet>, defenseurs: array<int, \OGame\GameMissions\BattleEngine\Models\DefenderFleet>, cible: \OGame\Services\PlanetService, contexte: \OGame\Combat\Support\LootContext}
     */
    private function uneGarnisonEntameeEtUnRenfortEntame(): array
    {
        return $this->aBattle(
            planete: [
                'metal' => 100_000,
                'crystal' => 50_000,
                'rocket_launcher' => 200,
                'damaged_hulls' => ['rocket_launcher' => [5_000 => 40]],
            ],
            attaquantes: [
                ['units' => ['light_fighter' => 5], 'tech' => ['weapon_technology' => 1, 'shielding_technology' => 1, 'armor_technology' => 1]],
            ],
            renforts: [
                ['units' => ['cruiser' => 20], 'degats' => ['cruiser' => [3_000 => 8]]],
            ],
        );
    }
}
