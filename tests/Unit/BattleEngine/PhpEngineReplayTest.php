<?php

namespace Tests\Unit\BattleEngine;

use OGame\Combat\Allocation\FrozenLootAllocation;
use OGame\Combat\Support\LiveLootContextFactory;
use OGame\GameMissions\BattleEngine\Draws\SeededDraws;
use OGame\GameMissions\BattleEngine\Models\AttackerFleet;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameMissions\BattleEngine\Models\DefenderFleet;
use OGame\GameMissions\BattleEngine\Parity\CanonicalProjection;
use OGame\GameMissions\BattleEngine\PhpBattleEngine;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use Tests\UnitTestCase;

/**
 * Le moteur PHP rejoue une bataille a la graine, et l'ordre des flottes n'y change rien.
 *
 * ## Ce que ce banc prouve, ici, sans bibliotheque
 *
 * La moitie PHP du banc de parite : une meme graine joue la meme bataille — les tirages viennent
 * bien de la source injectee, et de nulle part ailleurs — et une permutation des flottes donnees
 * joue la meme bataille aussi, parce que l'expansion est canonique. Le banc de parite compare
 * ensuite cette bataille a celle du moteur Rust, la ou il existe.
 *
 * ## Le temoin qui compte
 *
 * Deux batailles avec deux graines differentes sont comparees pour etablir que la projection
 * **sait** voir une difference : sans cela, « meme projection » pourrait vouloir dire « projection
 * vide ».
 */
class PhpEngineReplayTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createAndSetUserTechModel([]);
    }

    public function testTheSameSeedFightsTheSameBattle(): void
    {
        $resultatPremier = $this->fight(20260904);
        $resultatSecond = $this->fight(20260904);
        $premiere = CanonicalProjection::of($resultatPremier);
        $seconde = CanonicalProjection::of($resultatSecond);

        $this->assertNotSame([], $premiere['rounds'], 'The battle had no round: the projection would compare nothing.');
        $this->assertNull(CanonicalProjection::firstDivergence($premiere, $seconde));

        // Le journal des rounds : meme bande, consommee a l'identique.
        $this->assertNotNull($resultatPremier->drawsConsumed, 'A seeded battle kept no journal of its draws.');
        $this->assertGreaterThan(0, $resultatPremier->drawsConsumed['count']);
        $this->assertSame($resultatPremier->drawsConsumed, $resultatSecond->drawsConsumed, 'Two battles fed the same seed did not consume the same draws.');

        // La projection distingue bien deux batailles : une autre graine, une autre bataille.
        $autre = CanonicalProjection::of($this->fight(1));
        $this->assertNotNull(CanonicalProjection::firstDivergence($premiere, $autre), 'Two seeds fought the same battle: the projection could not tell any two battles apart.');
    }

    public function testAPermutationOfTheFleetsFightsTheSameBattle(): void
    {
        $droit = CanonicalProjection::of($this->fight(77));
        $permute = CanonicalProjection::of($this->fight(77, permute: true));

        $divergence = CanonicalProjection::firstDivergence($droit, $permute);
        $this->assertNull($divergence, 'The order the fleets were listed in changed the battle at ' . ($divergence ?? ''));
    }

    private function fight(int $graine, bool $permute = false): BattleResult
    {
        $this->createAndSetPlanetModel(['metal' => 50_000, 'crystal' => 50_000, 'deuterium' => 0, 'rocket_launcher' => 100]);

        $compositions = [['light_fighter' => 150, 'cruiser' => 20], ['heavy_fighter' => 30]];
        $renforts = [['light_fighter' => 60], ['heavy_fighter' => 25]];

        $flottes = [];
        foreach ($permute ? array_reverse($compositions, true) : $compositions as $rang => $composition) {
            $flotte = new AttackerFleet();
            $flotte->units = $this->units($composition);
            $flotte->player = $this->playerService;
            $flotte->fleetMissionId = 1000 + $rang;
            $flotte->ownerId = $this->playerService->getId();
            $flotte->cargoResources = new Resources(0, 0, 0, 0);
            $flotte->isInitiator = $rang === 0;
            $flotte->fleetMission = null;
            $flottes[] = $flotte;
        }

        // L'initiatrice reste en tete : le moteur l'exige. Tout le reste de l'ordre est permute.
        usort($flottes, static fn (AttackerFleet $a, AttackerFleet $b): int => $b->isInitiator <=> $a->isInitiator);

        $defenseurs = [];
        foreach ($permute ? array_reverse($renforts, true) : $renforts as $rang => $composition) {
            $renfort = new DefenderFleet();
            $renfort->units = $this->units($composition);
            $renfort->player = $this->playerService;
            $renfort->fleetMissionId = 2000 + $rang;
            $renfort->ownerId = 5;
            $renfort->fleetMission = null;
            $defenseurs[] = $renfort;
        }

        // La garnison est donnee en dernier quand on permute, en premier sinon.
        $garnison = DefenderFleet::fromPlanet($this->planetService);
        $defenseurs = $permute ? [...$defenseurs, $garnison] : [$garnison, ...$defenseurs];

        $moteur = new PhpBattleEngine(
            $flottes,
            $this->planetService,
            $defenseurs,
            $this->settingsService,
            LiveLootContextFactory::forBattle($flottes, $this->planetService, FrozenLootAllocation::atOperationStart())
        );

        return $moteur->withDraws(new SeededDraws($graine))->simulateBattle();
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
