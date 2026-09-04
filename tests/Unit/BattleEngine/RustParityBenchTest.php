<?php

namespace Tests\Unit\BattleEngine;

use OGame\Combat\Allocation\FrozenLootAllocation;
use OGame\Combat\Support\LiveLootContextFactory;
use OGame\GameMissions\BattleEngine\BattleEngine;
use OGame\GameMissions\BattleEngine\Draws\SeededDraws;
use OGame\GameMissions\BattleEngine\Models\AttackerFleet;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameMissions\BattleEngine\Models\DefenderFleet;
use OGame\GameMissions\BattleEngine\Parity\CanonicalProjection;
use OGame\GameMissions\BattleEngine\PhpBattleEngine;
use OGame\GameMissions\BattleEngine\RustBattleEngine;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\UnitTestCase;

/**
 * Le banc de parite : une seule entree gelee, une seule bande de tirages, deux moteurs, une projection.
 *
 * ## Ce que ce banc prouve
 *
 * Que les deux moteurs, nourris des memes flottes et de la meme graine, produisent la meme bataille :
 * survivants et pertes par participant et par periode, capacites survivantes, taux et versions,
 * butin et parts, debris. Une difference nomme **le premier chemin divergent** et laisse les deux
 * projections JSON dans `storage/logs/` comme artefacts — pas un `assertEquals` illisible sur deux
 * gros tableaux.
 *
 * ## Ce qu'il ne prouve pas
 *
 * Que l'un des deux moteurs a raison. Il prouve qu'ils disent la meme chose ; les regles, elles,
 * sont eprouvees par les bancs de chaque moteur. Et il n'est joue que la ou la bibliotheque existe.
 */
class RustParityBenchTest extends UnitTestCase
{
    private const int GRAINE = 20260904;

    protected function setUp(): void
    {
        $this->skipWhenTheRustLibraryIsUnavailable();

        parent::setUp();

        $this->createAndSetUserTechModel([]);
    }

    /**
     * @return iterable<string, array{0: string, 1: array<string, int>, 2: array<array<string, int>>, 3: array<array<string, int>>}>
     */
    public static function scenarios(): iterable
    {
        // 1. Un duel sans pillage : la cible n'a rien.
        yield 'duel sans pillage' => ['duel', ['rocket_launcher' => 60], [['light_fighter' => 80]], []];

        // 2. Une attaque pillarde : la cible est riche, l'attaquant a du fret.
        yield 'attaque pillarde' => ['pillage', ['metal' => 400_000, 'crystal' => 200_000, 'deuterium' => 50_000, 'rocket_launcher' => 30], [['small_cargo' => 40, 'light_fighter' => 200]], []];

        // 3. Deux attaquantes en union, avec du fret : la ponderation par le fret donne un taux non entier.
        yield 'union ponderee par le fret' => ['union', ['metal' => 600_000, 'crystal' => 300_000, 'deuterium' => 100_000, 'rocket_launcher' => 40], [['small_cargo' => 60, 'light_fighter' => 120], ['large_cargo' => 10, 'cruiser' => 15]], []];

        // 4. Une garnison et deux renforts : les pertes defensives par participant ont trois noms.
        yield 'defense avec deux renforts' => ['acs', ['metal' => 50_000, 'crystal' => 50_000, 'rocket_launcher' => 100], [['light_fighter' => 150, 'cruiser' => 20]], [['light_fighter' => 60], ['heavy_fighter' => 25]]];

        // 5. Un fret limitant et un butin qui ne se divise pas : les plus forts restes departagent.
        yield 'fret limitant et restes' => ['restes', ['metal' => 1_000_003, 'crystal' => 500_001, 'deuterium' => 250_007, 'rocket_launcher' => 5], [['small_cargo' => 7, 'light_fighter' => 30], ['small_cargo' => 3, 'light_fighter' => 30]], []];
    }

    /**
     * @param array<string, int> $planete
     * @param array<array<string, int>> $attaquantes
     * @param array<array<string, int>> $renforts
     */
    #[DataProvider('scenarios')]
    public function testBothEnginesFightTheSameBattle(string $nom, array $planete, array $attaquantes, array $renforts): void
    {
        $resultatPhp = $this->fight(PhpBattleEngine::class, $planete, $attaquantes, $renforts, self::GRAINE);
        $resultatRust = $this->fight(RustBattleEngine::class, $planete, $attaquantes, $renforts, self::GRAINE);

        $php = CanonicalProjection::of($resultatPhp);
        $rust = CanonicalProjection::of($resultatRust);

        $this->assertNotSame([], $php['rounds'], 'The PHP battle had no round: the projection would compare nothing.');

        $this->assertProjectionsAgree($nom, $php, $rust);

        // **La bande a ete consommee entierement et a l'identique** : meme nombre de tirages, meme
        // empreinte de genre, borne et valeur. Deux batailles egales tirees differemment seraient une
        // coincidence, pas une parite.
        $this->assertNotNull($resultatPhp->drawsConsumed, 'The PHP engine kept no journal of its draws.');
        $this->assertNotNull($resultatRust->drawsConsumed, 'The Rust engine returned no journal of its draws.');
        $this->assertGreaterThan(0, $resultatPhp->drawsConsumed['count']);
        $this->assertSame($resultatPhp->drawsConsumed, $resultatRust->drawsConsumed, 'Scenario « ' . $nom . ' » : the two engines did not consume the same draws.');
    }

    /**
     * L'ordre dans lequel les flottes sont donnees ne change pas la bataille — dans les deux moteurs.
     */
    public function testAPermutationOfTheFleetsFightsTheSameBattleInBothEngines(): void
    {
        $planete = ['metal' => 50_000, 'crystal' => 50_000, 'rocket_launcher' => 100];
        $attaquantes = [['light_fighter' => 150, 'cruiser' => 20], ['heavy_fighter' => 30]];
        $renforts = [['light_fighter' => 60], ['heavy_fighter' => 25]];

        $droit = CanonicalProjection::of($this->fight(PhpBattleEngine::class, $planete, $attaquantes, $renforts, 77));
        $permute = CanonicalProjection::of($this->fight(PhpBattleEngine::class, $planete, array_reverse($attaquantes), array_reverse($renforts), 77, permute: true));
        $this->assertProjectionsAgree('permutation-php', $droit, $permute);

        $rustDroit = CanonicalProjection::of($this->fight(RustBattleEngine::class, $planete, $attaquantes, $renforts, 77));
        $rustPermute = CanonicalProjection::of($this->fight(RustBattleEngine::class, $planete, array_reverse($attaquantes), array_reverse($renforts), 77, permute: true));
        $this->assertProjectionsAgree('permutation-rust', $rustDroit, $rustPermute);

        $this->assertProjectionsAgree('permutation', $droit, $rustDroit);
    }

    /**
     * @param class-string<BattleEngine> $classe
     * @param array<string, int> $planete
     * @param array<array<string, int>> $attaquantes
     * @param array<array<string, int>> $renforts
     */
    private function fight(string $classe, array $planete, array $attaquantes, array $renforts, int $graine, bool $permute = false): BattleResult
    {
        $this->createAndSetPlanetModel($planete + ['metal' => 0, 'crystal' => 0, 'deuterium' => 0]);

        // Les identifiants de mission sont fixes par le contenu, pas par la position : une flotte
        // permutee garde son identifiant, et c'est ce qui rend la bataille invariante.
        $flottes = [];
        foreach ($attaquantes as $rang => $composition) {
            $flotte = new AttackerFleet();
            $flotte->units = $this->units($composition);
            $flotte->player = $this->playerService;
            $flotte->fleetMissionId = 1000 + ($permute ? count($attaquantes) - 1 - $rang : $rang);
            $flotte->ownerId = $this->playerService->getId();
            $flotte->cargoResources = new Resources(0, 0, 0, 0);
            $flotte->isInitiator = $flotte->fleetMissionId === 1000;
            $flotte->fleetMission = null;
            $flottes[] = $flotte;
        }

        // L'initiatrice est toujours la flotte 1000, quel que soit l'ordre donne : le moteur exige
        // qu'elle soit en tete de liste.
        usort($flottes, static fn (AttackerFleet $a, AttackerFleet $b): int => $b->isInitiator <=> $a->isInitiator);

        $defenseurs = [DefenderFleet::fromPlanet($this->planetService)];
        foreach ($renforts as $rang => $composition) {
            $renfort = new DefenderFleet();
            $renfort->units = $this->units($composition);
            $renfort->player = $this->playerService;
            $renfort->fleetMissionId = 2000 + ($permute ? count($renforts) - 1 - $rang : $rang);
            $renfort->ownerId = 5;
            $renfort->fleetMission = null;
            $defenseurs[] = $renfort;
        }

        $moteur = new $classe(
            $flottes,
            $this->planetService,
            $defenseurs,
            $this->settingsService,
            LiveLootContextFactory::forBattle($flottes, $this->planetService, FrozenLootAllocation::atOperationStart())
        );

        return $moteur->withDraws(new SeededDraws($graine))->simulateBattle();
    }

    /**
     * @param array<string, mixed> $php
     * @param array<string, mixed> $rust
     */
    private function assertProjectionsAgree(string $nom, array $php, array $rust): void
    {
        $divergence = CanonicalProjection::firstDivergence($php, $rust);

        if ($divergence === null) {
            $this->addToAssertionCount(1);

            return;
        }

        $dossier = storage_path('logs');
        $etiquette = preg_replace('/[^a-z0-9]+/', '-', strtolower($nom)) ?? 'scenario';
        file_put_contents($dossier . '/parite-' . $etiquette . '-php.json', json_encode($php, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($dossier . '/parite-' . $etiquette . '-rust.json', json_encode($rust, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->fail('Scenario « ' . $nom . ' » : the two engines diverge at ' . $divergence . ' (both projections are in storage/logs/parite-' . $etiquette . '-*.json).');
    }

    /**
     * @param array<string, int> $composition
     */
    private function units(array $composition): UnitCollection
    {
        $unites = new UnitCollection();

        foreach ($composition as $nom => $montant) {
            $unites->addUnit(ObjectService::getUnitObjectByMachineName($nom), $montant);
        }

        return $unites;
    }
}
