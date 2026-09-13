<?php

namespace Tests\Unit\BattleEngine;

use OGame\Enums\CharacterClass;
use OGame\GameMissions\BattleEngine\PhpBattleEngine;
use OGame\GameMissions\BattleEngine\RustBattleEngine;
use Tests\UnitTestCase;

/**
 * **Deux ecarts de coques entre les deux moteurs : ouverts, epingles, non corriges.**
 *
 * ## Pourquoi des temoins pour un defaut qu on ne corrige pas
 *
 * Le banc de parite compare une projection canonique — et **cette projection ne porte pas les coques**. Les
 * deux ecarts ci-dessous lui sont donc invisibles, et rien ne les aurait signales. Les epingler, c est les
 * rendre observables : le jour ou l un se corrige, l essai qui le decrit rougit, et personne ne referme un
 * defaut sans le savoir.
 *
 * **Ces essais ne prouvent aucune parite.** Ils documentent une divergence connue : ils decrivent ce que
 * chaque moteur fait aujourd hui, et la CI reste verte tant que l ecart existe.
 *
 * ## Ce qu il faudra en faire, et ce qu il ne faudra pas
 *
 * Le jour ou un ecart est corrige, l essai qui le decrit **devient une exigence d egalite** entre les deux
 * moteurs — le meme montage, la meme comparaison, mais `assertSame` sur l etat des survivants des deux
 * cotes. **Le supprimer serait perdre le seul temoin qui regarde les coques** : la projection canonique ne
 * les compare pas, et plus rien ne verrait une divergence revenir.
 *
 * L interrupteur des degats de coque est **desarme** en jeu : ces ecarts n ont aucun effet aujourd hui. Cela
 * reduit leur portee, cela ne les corrige pas.
 *
 * ## Les deux ecarts
 *
 * 1. **Sans round, le moteur Rust ne fixe pas les coques des survivants.** Sa branche « aucune bataille » pose
 *    les effectifs et s arrete la ; le moteur PHP, lui, balaie ses unites etendues et rend leur etat. Une
 *    flotte entamee rentrerait donc **reparee** sous Rust. Ce chemin est atteignable en jeu — attaquer un
 *    corps sans defense — et la manoeuvre qui prend le dernier defenseur en ouvre un second.
 * 2. **Les defenses survivantes.** La bibliotheque rend l etat de **toutes** les unites, defenses comprises ;
 *    le moteur PHP ne garde que les vaisseaux, parce que les defenses ont deja leur propre reparation. Le
 *    corps recevrait donc, sous Rust seulement, des lance-missiles abimes.
 *
 * Ces essais passent par la vraie bibliotheque : ils sont ignores sur le poste et executes en integration
 * continue.
 */
final class RustSurvivorHullGapsTest extends UnitTestCase
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
     * **Ecart 1 — sans round, l etat des survivants attaquants disparait sous Rust.**
     *
     * La manoeuvre prend la seule Etoile de la garnison : aucun round n est joue, et la flotte attaquante
     * ressort telle qu elle est entree — entamee sous PHP, intacte sous Rust.
     */
    public function testWithoutARoundTheAttackerSurvivorHullsAreLostUnderRust(): void
    {
        $bataille = $this->uneFlotteEntameeContreUneSeuleEtoile();

        $php = $this->fight(PhpBattleEngine::class, $bataille);
        $rust = $this->fight(RustBattleEngine::class, $bataille);

        // Les premisses : la manoeuvre s est jouee, aucun round, et la flotte est entiere des deux cotes.
        foreach (['PHP' => $php, 'Rust' => $rust] as $moteur => $resultat) {
            $this->assertTrue($resultat->hamillManoeuvreTriggered, $moteur . ' : la manoeuvre ne s est pas jouee.');
            $this->assertSame([], $resultat->rounds, $moteur . ' : un round s est joue, l ecart vise n est pas atteint.');
            $this->assertSame(30, $resultat->attackerUnitsResult->getAmountByMachineName('light_fighter'), $moteur . ' : la flotte n est pas rentree entiere.');
        }

        $this->assertFalse(
            $php->attackerFleetResults[0]->survivorHulls()->isEmpty(),
            'Le moteur PHP a perdu l etat des survivants : l ecart aurait change de forme.'
        );

        // **L ecart, epingle.** Cet essai rougit le jour ou la branche sans round fixe enfin les coques.
        $this->assertTrue(
            $rust->attackerFleetResults[0]->survivorHulls()->isEmpty(),
            'Le moteur Rust rend desormais l etat des survivants sans round : l ecart est ferme, il faut le dire au journal et retirer cette epingle.'
        );
    }

    /**
     * **Ecart 2 — les defenses survivantes gardent leur etat sous Rust, pas sous PHP.**
     *
     * La garnison entre avec des lance-missiles deja entames et n est pas balayee : sous PHP son etat ressort
     * vide, sous Rust il porte les paliers que la bibliotheque a rendus.
     */
    public function testTheSurvivingDefencesKeepTheirHullsUnderRustOnly(): void
    {
        $bataille = $this->uneGarnisonEntameeQuiTient();

        $php = $this->fight(PhpBattleEngine::class, $bataille);
        $rust = $this->fight(RustBattleEngine::class, $bataille);

        foreach (['PHP' => $php, 'Rust' => $rust] as $moteur => $resultat) {
            $this->assertNotSame([], $resultat->rounds, $moteur . ' : aucun round, la garnison n a pas combattu.');
            $this->assertGreaterThan(0, $resultat->defenderUnitsResult->getAmountByMachineName('rocket_launcher'), $moteur . ' : la garnison a ete balayee, aucun survivant a decrire.');
        }

        $this->assertTrue(
            $php->defenderFleetResults[0]->survivorHulls()->isEmpty(),
            'Le moteur PHP garde desormais l etat des defenses : l ecart est ferme, il faut le dire au journal.'
        );

        // **L ecart, epingle.** La bibliotheque ne filtre pas par type.
        $this->assertFalse(
            $rust->defenderFleetResults[0]->survivorHulls()->isEmpty(),
            'Le moteur Rust ne rend plus l etat des defenses : l ecart est ferme, il faut le dire au journal et retirer cette epingle.'
        );
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
     * Une garnison de lance-missiles deja entames, qu une petite flotte ne peut pas balayer.
     *
     * @return array{attaquantes: array<int, \OGame\GameMissions\BattleEngine\Models\AttackerFleet>, defenseurs: array<int, \OGame\GameMissions\BattleEngine\Models\DefenderFleet>, cible: \OGame\Services\PlanetService, contexte: \OGame\Combat\Support\LootContext}
     */
    private function uneGarnisonEntameeQuiTient(): array
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
        );
    }
}
