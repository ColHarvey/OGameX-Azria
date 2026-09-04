<?php

namespace Tests\Unit\BattleEngine;

use FFI;
use OGame\Combat\Allocation\FrozenLootAllocation;
use OGame\Combat\Support\LiveLootContextFactory;
use OGame\GameMissions\BattleEngine\Models\AttackerFleet;
use OGame\GameMissions\BattleEngine\Models\DefenderFleet;
use OGame\GameMissions\BattleEngine\RustBattleEngine;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use Tests\UnitTestCase;

/**
 * Le contrat FFI, eprouve la ou la bibliotheque existe.
 *
 * ## Ce que ce fichier prouve, et que rien d'autre ne peut prouver
 *
 * Que la bibliotheque compilee parle la version que le client attend ; qu'une bataille peut etre
 * demandee, lue et liberee plusieurs fois de suite — les fonctions d'allocation et de liberation
 * sont traversees a chaque tour ; qu'une entree d'une autre version revient en document d'erreur
 * lisible, et non en panique ; que liberer un pointeur nul ne fait rien.
 *
 * Il est ignore partout ou le `.so` manque : c'est le run d'integration continue qui le joue.
 */
class RustEngineContractTest extends UnitTestCase
{
    protected function setUp(): void
    {
        $this->skipWhenTheRustLibraryIsUnavailable();

        parent::setUp();

        $this->createAndSetPlanetModel(['metal' => 10_000, 'crystal' => 10_000, 'deuterium' => 1_000, 'rocket_launcher' => 40]);
        $this->createAndSetUserTechModel([]);
    }

    public function testTheLibrarySpeaksTheVersionTheClientExpects(): void
    {
        $ffi = FFI::cdef('unsigned int battle_engine_abi_version(void);', base_path('storage/rust-libs/libbattle_engine_ffi.so'));

        // @phpstan-ignore-next-line
        $this->assertSame(RustBattleEngine::ABI_VERSION, (int)$ffi->battle_engine_abi_version(), 'The compiled library speaks another contract than this client.');
    }

    public function testABattleCanBeFoughtReadAndFreedRepeatedly(): void
    {
        for ($tour = 1; $tour <= 3; $tour++) {
            $resultat = $this->anEngine()->simulateBattle();

            $this->assertNotSame([], $resultat->rounds, 'Battle ' . $tour . ' produced no round.');
            $this->assertNotSame([], $resultat->rounds[0]->lossesInRoundByParticipant, 'Battle ' . $tour . ': the first round names nobody.');
        }
    }

    public function testAnInputOfAnotherVersionComesBackAsAReadableErrorNotAPanic(): void
    {
        $ffi = FFI::cdef(
            "char* fight_battle_rounds(const char* input_json);\nvoid free_battle_output(char* output);",
            base_path('storage/rust-libs/libbattle_engine_ffi.so')
        );

        $entree = json_encode(['schema' => 1, 'attacker_fleets' => [], 'defender_fleets' => []]);

        // @phpstan-ignore-next-line
        $sortie = $ffi->fight_battle_rounds($entree);
        $reponse = json_decode(FFI::string($sortie), true);
        // @phpstan-ignore-next-line
        $ffi->free_battle_output($sortie);
        // @phpstan-ignore-next-line
        $ffi->free_battle_output(null);

        $this->assertIsArray($reponse);
        $this->assertSame(RustBattleEngine::ABI_VERSION, $reponse['schema'] ?? null);
        $this->assertStringContainsString('input schema 1', (string)($reponse['error'] ?? ''));
        $this->assertArrayNotHasKey('rounds', $reponse);
    }

    private function anEngine(): RustBattleEngine
    {
        $attaquante = new AttackerFleet();
        $attaquante->units = new UnitCollection();
        $attaquante->units->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 60);
        $attaquante->player = $this->playerService;
        $attaquante->fleetMissionId = 4242;
        $attaquante->ownerId = $this->playerService->getId();
        $attaquante->cargoResources = new Resources(0, 0, 0, 0);
        $attaquante->isInitiator = true;
        $attaquante->fleetMission = null;

        return new RustBattleEngine(
            [$attaquante],
            $this->planetService,
            [DefenderFleet::fromPlanet($this->planetService)],
            $this->settingsService,
            LiveLootContextFactory::forBattle([$attaquante], $this->planetService, FrozenLootAllocation::atOperationStart())
        );
    }
}
