<?php

namespace Tests\Unit\BattleEngine;

use OGame\Combat\Enums\HamillManoeuvreRule;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Enums\CharacterClass;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameMissions\BattleEngine\Models\DefenderFleetResult;
use OGame\GameMissions\BattleEngine\Parity\CanonicalProjection;
use OGame\GameMissions\BattleEngine\PhpBattleEngine;
use OGame\Services\PlanetService;
use Tests\UnitTestCase;

/**
 * **La manoeuvre de Hamill nomme sa victime et son auteur a l instant du retrait**, sans changer ni la cible, ni les
 * tirages, ni le resultat.
 *
 * ## La convention Azria de l auteur
 *
 * Le moteur consulte le General de la **premiere flotte attaquante dans l ordre canonique** (`attackers[0]`) et
 * cherche des chasseurs legers dans **toutes** les flottes attaquantes : il ne designe pas la flotte dont les
 * chasseurs « ont vole », et ils ne sont pas consommes. L auteur enregistre est donc cette premiere flotte, meme
 * quand seul un allie porte les chasseurs. Changer cela changerait le declenchement : decision de jeu, hors tranche.
 */
final class HamillManoeuvreRecordTest extends UnitTestCase
{
    use BuildsParityScenarios;

    private int $chance = 1_000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chance = $this->settingsService->hamillManoeuvreChance();
    }

    protected function tearDown(): void
    {
        $this->settingsService->set('hamill_manoeuvre_chance', $this->chance);
        parent::tearDown();
    }

    public function testTheManoeuvreNamesTheGarrisonItTakesTheStarFromAndTheFleetWhoseGeneralWasConsulted(): void
    {
        $resultat = $this->laBataille(1);

        $this->assertTrue($resultat->hamillManoeuvreTriggered, 'Premisse : la manoeuvre a eu lieu.');
        $this->assertTrue($resultat->hamill->isNamed(), 'La manoeuvre a eu lieu sans nommer sa victime.');
        // La garnison porte une Etoile et vient en tete de l ordre canonique : c est a elle que la manoeuvre la prend.
        $this->assertSame(CombatParticipantKey::forPlanet($this->laCible->getPlanetId()), $resultat->hamill->victim, 'La victime n est pas la garnison, premiere dans l ordre canonique.');
        $this->assertSame(CombatParticipantKey::forFleet(1_000), $resultat->hamill->author, 'L auteur n est pas la premiere flotte attaquante.');
        $this->assertSame(HamillManoeuvreRule::current()->value, $resultat->hamill->rule);
        $this->assertSame(1, $this->laFlotteDefensive($resultat, 0)->unitsLost->getAmountByMachineName('deathstar'), 'La victime nommee n a pas perdu l Etoile.');
        $this->assertSame(0, $this->laFlotteDefensive($resultat, 2_000)->unitsLost->getAmountByMachineName('deathstar'), 'Une autre flotte a perdu une Etoile.');
        $this->assertSame($resultat->hamill->toStorage(), CanonicalProjection::hamillOf($resultat), 'La projection ne rend pas la manoeuvre telle qu enregistree.');
    }

    /**
     * **La victime est la flotte a qui l Etoile est prise, pas la garnison par defaut** : quand seul un renfort porte
     * une Etoile, c est lui qui est nomme, et c est lui qui la perd.
     */
    public function testWhenOnlyAReinforcementCarriesAStarItIsTheNamedVictim(): void
    {
        $this->settingsService->set('hamill_manoeuvre_chance', 1);
        $technologies = ['weapon_technology' => 6, 'shielding_technology' => 5, 'armor_technology' => 6];

        $resultat = $this->fight(PhpBattleEngine::class, $this->aBattle(
            planete: ['metal' => 40_000, 'crystal' => 20_000, 'rocket_launcher' => 40],
            attaquantes: [['units' => ['light_fighter' => 300, 'cruiser' => 30], 'tech' => $technologies, 'classe' => CharacterClass::GENERAL]],
            renforts: [['units' => ['deathstar' => 1, 'light_fighter' => 5], 'tech' => $technologies]],
        ));

        $this->assertTrue($resultat->hamillManoeuvreTriggered, 'Premisse : la manoeuvre a eu lieu.');
        $this->assertSame(CombatParticipantKey::forFleet(2_000), $resultat->hamill->victim, 'La victime nommee n est pas le renfort qui portait l Etoile.');
        $this->assertSame(1, $this->laFlotteDefensive($resultat, 2_000)->unitsLost->getAmountByMachineName('deathstar'));
        $this->assertSame(0, $this->laFlotteDefensive($resultat, 0)->unitsLost->getAmountByMachineName('deathstar'), 'La garnison a perdu une Etoile qu elle n avait pas.');
    }

    public function testWithoutARoundTheManoeuvreIsStillNamed(): void
    {
        $resultat = $this->laBatailleDuDernierDefenseur(1);

        $this->assertSame([], $resultat->rounds, 'Premisse : aucun round.');
        $this->assertTrue($resultat->hamill->isNamed(), 'Sans round, la manoeuvre n est pas nommee.');
        $this->assertSame(CombatParticipantKey::forPlanet($this->laCible->getPlanetId()), $resultat->hamill->victim);
        $this->assertSame(CombatParticipantKey::forFleet(1_000), $resultat->hamill->author);
    }

    public function testWhenTheManoeuvreDoesNotHappenNothingIsNamed(): void
    {
        $resultat = $this->laBataille(1_000_000);

        $this->assertFalse($resultat->hamillManoeuvreTriggered, 'Premisse : la manoeuvre n a pas eu lieu.');
        $this->assertFalse($resultat->hamill->triggered);
        $this->assertFalse($resultat->hamill->isNamed());
        $this->assertNull($resultat->hamill->victim);
    }

    public function testUnderTheRuleAsDeliveredTheManoeuvreStaysUnnamed(): void
    {
        $resultat = $this->laBataille(1, HamillManoeuvreRule::AsDelivered);

        $this->assertTrue($resultat->hamillManoeuvreTriggered);
        $this->assertFalse($resultat->hamill->isNamed(), 'Sous la regle telle que livree, aucune flotte ne perd l Etoile : rien ne doit etre nomme.');
        $this->assertSame(HamillManoeuvreRule::AsDelivered->value, $resultat->hamill->rule);
    }

    /**
     * **Les chasseurs d un allie seul** : le General consulte est celui de la premiere flotte, sans chasseur ; les
     * chasseurs sont ceux de l allie ; la manoeuvre se declenche (mecanisme inchange) et l auteur enregistre est la
     * premiere flotte — convention Azria.
     */
    public function testWhenOnlyAnAllyCarriesTheFightersTheAuthorIsStillTheFleetWhoseGeneralWasConsulted(): void
    {
        $this->settingsService->set('hamill_manoeuvre_chance', 1);
        $technologies = ['weapon_technology' => 6, 'shielding_technology' => 5, 'armor_technology' => 6];

        $resultat = $this->fight(PhpBattleEngine::class, $this->aBattle(
            planete: ['metal' => 40_000, 'crystal' => 20_000, 'deathstar' => 1, 'rocket_launcher' => 40],
            attaquantes: [
                ['units' => ['cruiser' => 30], 'tech' => $technologies, 'classe' => CharacterClass::GENERAL],
                ['units' => ['light_fighter' => 300], 'tech' => $technologies],
            ],
        ));

        $this->assertTrue($resultat->hamillManoeuvreTriggered, 'Premisse : la manoeuvre se declenche avec les chasseurs de l allie seul.');
        $this->assertSame(CombatParticipantKey::forFleet(1_000), $resultat->hamill->author, 'L auteur n est pas la flotte dont le General a ete consulte.');
        $this->assertSame(0, $resultat->attackerFleetResults[0]->unitsStart->getAmountByMachineName('light_fighter'), 'Premisse : la premiere flotte n a aucun chasseur.');
    }

    private function laBataille(int $chance, HamillManoeuvreRule|null $regle = null): BattleResult
    {
        $this->settingsService->set('hamill_manoeuvre_chance', $chance);

        return $this->fight(PhpBattleEngine::class, $this->uneBatailleGardee($this->aGeneralWhoseHamillManoeuvreSucceeds()), $regle);
    }

    private function laBatailleDuDernierDefenseur(int $chance): BattleResult
    {
        $this->settingsService->set('hamill_manoeuvre_chance', $chance);

        return $this->fight(PhpBattleEngine::class, $this->uneBatailleGardee($this->aGeneralWhoseHamillManoeuvreTakesTheLastDefender()));
    }

    private PlanetService $laCible;

    /**
     * @param array{attaquantes: array<int, \OGame\GameMissions\BattleEngine\Models\AttackerFleet>, defenseurs: array<int, \OGame\GameMissions\BattleEngine\Models\DefenderFleet>, cible: \OGame\Services\PlanetService, contexte: \OGame\Combat\Support\LootContext} $bataille
     * @return array{attaquantes: array<int, \OGame\GameMissions\BattleEngine\Models\AttackerFleet>, defenseurs: array<int, \OGame\GameMissions\BattleEngine\Models\DefenderFleet>, cible: \OGame\Services\PlanetService, contexte: \OGame\Combat\Support\LootContext}
     */
    private function uneBatailleGardee(array $bataille): array
    {
        $this->laCible = $bataille['cible'];

        return $bataille;
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
}
