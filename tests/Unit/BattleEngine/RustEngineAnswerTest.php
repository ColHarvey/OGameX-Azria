<?php

namespace Tests\Unit\BattleEngine;

use OGame\Combat\Exceptions\RustEngineContractMismatch;
use OGame\GameMissions\BattleEngine\RustBattleEngine;
use OGame\GameMissions\BattleEngine\RustEngineAnswer;
use PHPUnit\Framework\TestCase;

/**
 * Ce que le client PHP refuse de lire comme une bataille — eprouve sans bibliotheque.
 *
 * Le jugement porte sur une chaine : il n'a pas besoin de FFI, et c'est la seule part de la
 * couture qu'un poste sans `.so` peut eprouver. Chaque refus nomme sa raison.
 */
class RustEngineAnswerTest extends TestCase
{
    public function testADocumentThatDoesNotParseIsRefused(): void
    {
        $this->assertRefused('{not json', 'ne se lit pas');
    }

    public function testAnErrorDocumentIsRefusedWithItsReason(): void
    {
        $this->assertRefused(
            $this->json(['schema' => RustBattleEngine::ABI_VERSION, 'error' => 'input schema 1 is not the contract version 2 this library speaks']),
            'input schema 1'
        );
    }

    public function testADocumentOfAnotherVersionIsRefusedEvenWhenReadable(): void
    {
        $this->assertRefused($this->json(['schema' => RustBattleEngine::ABI_VERSION + 1, 'rounds' => []]), 'schema ' . (RustBattleEngine::ABI_VERSION + 1));
        $this->assertRefused($this->json(['rounds' => []]), 'schema NULL');
    }

    public function testADocumentWithoutRoundsIsRefused(): void
    {
        $this->assertRefused($this->json(['schema' => RustBattleEngine::ABI_VERSION]), 'aucun round');
    }

    public function testABattleDocumentOfTheExpectedVersionIsAdmitted(): void
    {
        $document = RustEngineAnswer::battleOutputFrom($this->json(['schema' => RustBattleEngine::ABI_VERSION, 'rounds' => [], 'memory_metrics' => ['peak_memory' => 0]]), RustBattleEngine::ABI_VERSION);

        $this->assertSame([], $document['rounds']);
    }

    private function assertRefused(string $json, string $reason): void
    {
        try {
            RustEngineAnswer::battleOutputFrom($json, RustBattleEngine::ABI_VERSION);
            $this->fail('A document that is not a battle was read as one (expected a refusal naming « ' . $reason . ' »).');
        } catch (RustEngineContractMismatch $refus) {
            $this->assertStringContainsString($reason, $refus->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $document
     */
    private function json(array $document): string
    {
        $encode = json_encode($document);
        $this->assertIsString($encode);

        return $encode;
    }
}
