<?php

namespace Tests\Unit\Combat;

use OGame\Combat\Exceptions\CorruptedFrozenMoonPlan;
use OGame\Combat\Services\MissileStrikeFacts;
use PHPUnit\Framework\TestCase;

/**
 * La porte de relecture des faits d'une salve refuse ce qui n'est pas un entier.
 *
 * Un document abime — un nombre de missiles ecrit en chaine, une priorite flottante, un fait absent —
 * passerait par un transtypage pour un document valide, et la fermeture projetterait une salve que
 * personne n'a tiree. Le tour complet est verifie aussi.
 */
final class MissileStrikeFactsTest extends TestCase
{
    public function testANumericStringMissileCountIsRefused(): void
    {
        $this->expectException(CorruptedFrozenMoonPlan::class);

        MissileStrikeFacts::fromFrozenFacts(self::facts(['missiles' => '4']));
    }

    public function testAFloatPriorityIsRefused(): void
    {
        $this->expectException(CorruptedFrozenMoonPlan::class);

        MissileStrikeFacts::fromFrozenFacts(self::facts(['priority' => 3.0]));
    }

    public function testAMissingFactIsRefused(): void
    {
        $faits = self::facts();
        unset($faits['parent_interceptors_before']);

        $this->expectException(CorruptedFrozenMoonPlan::class);

        MissileStrikeFacts::fromFrozenFacts($faits);
    }

    public function testWhatIsWrittenIsReadBackIdentical(): void
    {
        $salve = new MissileStrikeFacts(4, 3, 7, 3, 2);

        $this->assertSame($salve->toFrozenFacts(), MissileStrikeFacts::fromFrozenFacts($salve->toFrozenFacts())->toFrozenFacts());
    }

    /**
     * @param array<string, mixed> $remplacements
     * @return array<string, mixed>
     */
    private static function facts(array $remplacements = []): array
    {
        return $remplacements + [
            'missiles' => 4,
            'priority' => 3,
            'weapon_tech' => 0,
            'target_interceptors_before' => 3,
            'parent_interceptors_before' => 0,
        ];
    }
}
