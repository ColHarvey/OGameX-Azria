<?php

namespace Tests\Unit\BattleEngine;

use FFI;
use FFI\CData;
use OGame\Combat\Allocation\FrozenLootAllocation;
use OGame\Combat\Support\LiveLootContextFactory;
use OGame\GameMissions\BattleEngine\Models\AttackerFleet;
use OGame\GameMissions\BattleEngine\Models\DefenderFleet;
use OGame\GameMissions\BattleEngine\RustBattleEngine;
use OGame\GameMissions\BattleEngine\RustEngineAnswer;
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

    /**
     * Un pointeur nul et une panique provoquee apres le decodage reviennent en documents d'erreur,
     * et le processus continue : la bataille suivante se joue normalement.
     */
    public function testANullInputAndAPanicAfterDecodingComeBackAsErrorDocumentsAndTheProcessGoesOn(): void
    {
        $ffi = FFI::cdef(
            "char* fight_battle_rounds(const char* input_json);\nvoid free_battle_output(char* output);",
            base_path('storage/rust-libs/libbattle_engine_ffi.so')
        );

        // @phpstan-ignore-next-line
        $sortie = $ffi->fight_battle_rounds(null);
        $this->assertNotNull($sortie, 'A null input produced no document at all.');
        $reponse = json_decode(FFI::string($sortie), true);
        // @phpstan-ignore-next-line
        $ffi->free_battle_output($sortie);

        $this->assertIsArray($reponse);
        $this->assertStringContainsString('null', (string)($reponse['error'] ?? ''), 'A null input was not refused as such.');

        $entree = json_encode(['schema' => RustBattleEngine::ABI_VERSION, 'bench_provoke_panic' => true, 'attacker_fleets' => [], 'defender_fleets' => []], JSON_THROW_ON_ERROR);
        // @phpstan-ignore-next-line
        $sortie = $ffi->fight_battle_rounds($entree);
        $this->assertNotNull($sortie, 'A provoked panic produced no document at all.');
        $reponse = json_decode(FFI::string($sortie), true);
        // @phpstan-ignore-next-line
        $ffi->free_battle_output($sortie);

        $this->assertIsArray($reponse);
        $this->assertSame(RustBattleEngine::ABI_VERSION, $reponse['schema'] ?? null);
        $this->assertStringContainsString('panicked', (string)($reponse['error'] ?? ''), 'A panic after decoding did not come back as an error document.');
        $this->assertArrayNotHasKey('rounds', $reponse);

        // Le processus continue : une vraie bataille se joue ensuite.
        $resultat = $this->anEngine()->simulateBattle();
        $this->assertNotSame([], $resultat->rounds, 'The engine no longer fights after a caught panic.');
    }

    /**
     * Un `char*` reellement nul, rendu par une bibliotheque, est reconnu — sous la forme que la
     * plateforme lui donne.
     *
     * ## Ce que la mesure a dit
     *
     * Codex avancait qu'un `char*` nul arrive en PHP comme un objet `FFI\CData` nul, et qu'un
     * client testant `=== null` le laisserait passer. Sur cette plateforme, mesure faite par ce
     * meme essai en integration continue, **PHP le rend comme la valeur `null`**. Les deux formes
     * restent possibles selon la version et le type declare ; le jugement du client couvre les deux,
     * et cet essai constate laquelle arrive plutot que de la supposer.
     *
     * Le moteur de combat ne rend jamais nul : c'est la petite bibliotheque d'essai qui le fait.
     */
    public function testAGenuinelyNullPointerIsRecognisedWhateverFormPhpGivesIt(): void
    {
        $this->skipWhenTheRustLibraryIsUnavailable('libtest_ffi.so');

        $ffi = FFI::cdef("char* rust_null_string(void);\nchar* rust_hello(void);", base_path('storage/rust-libs/libtest_ffi.so'));

        // @phpstan-ignore-next-line
        $nul = $ffi->rust_null_string();

        $this->assertTrue(
            $nul === null || (($nul instanceof CData) && FFI::isNull($nul)),
            'A null C pointer came back as neither the PHP null nor a null CData: the client would not know what to check.'
        );
        $this->assertTrue(RustEngineAnswer::isNullPointer($nul), 'The client does not recognise a genuinely null pointer.');

        // **Le temoin qui discrimine** : un pointeur reel n'est pas juge nul. Sans lui, un jugement
        // qui repondrait toujours « nul » passerait l'assertion precedente.
        // @phpstan-ignore-next-line
        $reel = $ffi->rust_hello();
        $this->assertFalse(RustEngineAnswer::isNullPointer($reel), 'A real pointer was judged null.');
        $this->assertSame('Hello from Rust!', FFI::string($reel));
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
