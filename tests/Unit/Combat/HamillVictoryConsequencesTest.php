<?php

namespace Tests\Unit\Combat;

use OGame\Combat\Enums\HamillManoeuvreRule;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameMissions\BattleEngine\PhpBattleEngine;
use OGame\Services\Npc\NpcDestructionService;
use Tests\Unit\BattleEngine\BuildsParityScenarios;
use Tests\UnitTestCase;

/**
 * **La chute d une base pirate apres une manoeuvre de Hamill, avec ses conditions propres.**
 *
 * ## Ce que cet essai eprouve, et par quel chemin
 *
 * `NpcDestructionService::isDefeatedInBattle()` est **la** fonction que le reglement interroge avant de faire
 * tomber une base. Elle exige trois choses ensemble : des survivants attaquants, une defense entierement
 * balayee, et une **Etoile de la mort attaquante encore vivante** — decision de Keven du 9 septembre 2026 : le
 * vaisseau qui donne le coup de grace doit encore etre la pour le donner.
 *
 * L essai lui donne un vrai resultat de bataille, produit par le moteur, et ne reformule aucune de ces
 * conditions : il les eprouve la ou elles vivent.
 *
 * ## Pourquoi la manoeuvre change quelque chose ici
 *
 * Une base construit une Etoile des qu elle a la graviton. Quand la manoeuvre la prend, la defense n a plus
 * rien — mais sous les regles precedentes l Etoile detruite comptait encore parmi les survivants, et la base
 * **restait debout**, defenses reparees comprises. La regle courante la retire du decompte : la base tombe,
 * a condition que l attaquant ait bien une Etoile survivante.
 */
final class HamillVictoryConsequencesTest extends UnitTestCase
{
    use BuildsParityScenarios;

    /**
     * Une flotte de General capable d achever une base : des chasseurs legers pour la manoeuvre, et l Etoile
     * que la regle du serveur exige.
     */
    private const array AVEC_UNE_ETOILE = ['light_fighter' => 300, 'deathstar' => 3];

    /**
     * La meme, sans Etoile : elle balaie la defense et ne peut rien achever.
     */
    private const array SANS_ETOILE = ['light_fighter' => 300, 'small_cargo' => 40];

    private int $chance = 1_000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->chance = $this->settingsService->hamillManoeuvreChance();
        $this->settingsService->set('hamill_manoeuvre_chance', 1);
    }

    protected function tearDown(): void
    {
        $this->settingsService->set('hamill_manoeuvre_chance', $this->chance);

        parent::tearDown();
    }

    /**
     * **La base tombe** quand la manoeuvre prend sa derniere Etoile et que l attaquant en garde une.
     */
    public function testABaseFallsWhenTheManoeuvreTakesItsLastDefenderAndTheAttackerKeepsADeathstar(): void
    {
        $bataille = $this->laBataille(self::AVEC_UNE_ETOILE);

        // Les premisses, chacune nommee : sans elles, un « vrai » pourrait venir d ailleurs.
        $this->assertTrue($bataille->hamillManoeuvreTriggered, 'La manoeuvre ne s est pas jouee : le reste ne prouverait rien.');
        $this->assertSame([], $bataille->rounds, 'Un round s est joue : la defense n a pas ete videe par la manoeuvre.');
        $this->assertGreaterThan(0, $bataille->attackerUnitsResult->getAmountByMachineName('deathstar'), 'L attaquant n a plus d Etoile : la condition du serveur ne serait pas eprouvee.');
        $this->assertSame(0, $bataille->defenderUnitsResult->getAmount(), 'La defense compte encore un survivant.');

        $this->assertTrue(
            resolve(NpcDestructionService::class)->isDefeatedInBattle($bataille),
            'La base reste debout alors que sa derniere Etoile a ete detruite et que l attaquant en garde une.'
        );
    }

    /**
     * **La regle du serveur tient** : sans Etoile attaquante survivante, la base ne tombe pas, quoi que la
     * manoeuvre ait fait.
     */
    public function testABaseDoesNotFallToAFleetWithoutADeathstar(): void
    {
        $bataille = $this->laBataille(self::SANS_ETOILE);

        $this->assertTrue($bataille->hamillManoeuvreTriggered, 'La manoeuvre ne s est pas jouee : le reste ne prouverait rien.');
        $this->assertSame(0, $bataille->defenderUnitsResult->getAmount(), 'La defense compte encore un survivant : ce n est pas le cas eprouve.');
        $this->assertGreaterThan(0, $bataille->attackerUnitsResult->getAmount(), 'L attaquant est mort : ce n est pas le cas eprouve.');
        $this->assertSame(0, $bataille->attackerUnitsResult->getAmountByMachineName('deathstar'), 'La flotte porte une Etoile : la condition ne serait pas eprouvee.');

        $this->assertFalse(
            resolve(NpcDestructionService::class)->isDefeatedInBattle($bataille),
            'Une base est tombee sous une flotte sans Etoile de la mort : la regle du 9 septembre ne tient plus.'
        );
    }

    /**
     * **Protection des batailles deja ouvertes** : sous la regle precedente, la meme bataille laisse la base
     * debout, parce que l Etoile detruite compte encore parmi les survivants.
     */
    public function testUnderTheEarlierRuleTheBaseStaysStandingAfterTheSameBattle(): void
    {
        $bataille = $this->laBataille(self::AVEC_UNE_ETOILE, HamillManoeuvreRule::Effective);

        $this->assertTrue($bataille->hamillManoeuvreTriggered, 'La manoeuvre ne s est pas jouee : le reste ne prouverait rien.');
        $this->assertGreaterThan(0, $bataille->attackerUnitsResult->getAmountByMachineName('deathstar'), 'L attaquant n a plus d Etoile : la comparaison porterait sur autre chose.');
        $this->assertSame(1, $bataille->defenderUnitsResult->getAmount(), 'Sous la regle precedente, l Etoile detruite comptait encore parmi les survivants.');

        $this->assertFalse(
            resolve(NpcDestructionService::class)->isDefeatedInBattle($bataille),
            'La base tombe sous une regle qui laissait l Etoile detruite parmi les survivants : la protection ne tient plus.'
        );
    }

    /**
     * @param array<string, int> $flotte
     */
    private function laBataille(array $flotte, HamillManoeuvreRule|null $regle = null): BattleResult
    {
        return $this->fight(
            PhpBattleEngine::class,
            $this->aGeneralAttackingAStockedBody(['deathstar' => 1], $flotte),
            $regle
        );
    }
}
