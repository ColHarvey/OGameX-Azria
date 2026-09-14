<?php

namespace Tests\Unit\BattleEngine;

use OGame\Enums\CharacterClass;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameMissions\BattleEngine\Parity\CanonicalProjection;
use OGame\GameMissions\BattleEngine\PhpBattleEngine;
use Tests\UnitTestCase;

/**
 * **La projection qui compare les coques dit bien ce que chaque flotte garde.**
 *
 * ## Pourquoi elle vit a part de la projection canonique
 *
 * `BattleReferenceVectorsTest` epingle **l empreinte** de la projection canonique, relevee sur le moteur d avant
 * la couture d etat et figee telle quelle : y ajouter une clef la changerait, et ces vecteurs ne se recopient
 * jamais depuis la version courante. Les coques se comparent donc par `hullsOf()`, que rien ne fige.
 *
 * ## Pourquoi cet essai-ci existe
 *
 * `hullsOf()` porte desormais toute la comparaison des coques entre les deux moteurs — dans le banc de parite et
 * dans les essais d egalite —, et ceux-la ne s executent qu en integration continue. Sans cet essai, **rien sur
 * ce poste** ne tiendrait la projection elle-meme : une projection qui rendrait toujours vide laisserait les
 * deux moteurs « d accord » sur rien.
 */
final class HullProjectionTest extends UnitTestCase
{
    use BuildsParityScenarios;

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
     * **Sans round, la flotte attaquante ressort avec les degats qu elle portait en entrant.**
     */
    public function testItReportsTheHullsAnUntouchedFleetCameInWith(): void
    {
        $coques = CanonicalProjection::hullsOf($this->uneBatailleSansRound());

        $this->assertSame(['light_fighter' => [4_000 => 12]], $coques['attacker_fleets'][1_000], 'Les degats d entree de l attaquante ne ressortent pas.');
        $this->assertSame([], $coques['defender_fleets'][0], 'La garnison, reduite a l Etoile que la manoeuvre a prise, ne garde rien.');
    }

    /**
     * **Chaque flotte garde les siennes**, et une defense survivante n en porte aucune.
     *
     * Le corps et le renfort sont deux flottes defensives distinctes : si la projection les melangeait, ou
     * rendait les coques du mauvais camp, cet essai le dirait.
     */
    public function testEachFleetKeepsItsOwnHullsAndDefencesCarryNone(): void
    {
        $coques = CanonicalProjection::hullsOf($this->uneGarnisonQuiTientEtUnRenfortEntame());

        $this->assertSame([], $coques['defender_fleets'][0], 'Une defense survivante porte une coque persistante : elle a pourtant sa propre reparation.');
        $this->assertSame(['cruiser' => [3_000 => 8]], $coques['defender_fleets'][2_000], 'Le renfort ne garde pas ses degats d entree.');
        $this->assertSame([], $coques['attacker_fleets'][1_000], 'L attaquante a ete aneantie : elle ne peut avoir aucun survivant entame.');
    }

    private function uneBatailleSansRound(): BattleResult
    {
        return $this->fight(PhpBattleEngine::class, $this->aBattle(
            planete: ['metal' => 100_000, 'crystal' => 50_000, 'deathstar' => 1],
            attaquantes: [
                [
                    'units' => ['light_fighter' => 30],
                    'tech' => ['weapon_technology' => 6, 'shielding_technology' => 5, 'armor_technology' => 6],
                    'classe' => CharacterClass::GENERAL,
                    'degats' => ['light_fighter' => [4_000 => 12]],
                ],
            ],
        ));
    }

    private function uneGarnisonQuiTientEtUnRenfortEntame(): BattleResult
    {
        return $this->fight(PhpBattleEngine::class, $this->aBattle(
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
        ));
    }
}
