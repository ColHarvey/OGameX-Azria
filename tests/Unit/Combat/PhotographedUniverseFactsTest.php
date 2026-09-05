<?php

namespace Tests\Unit\Combat;

use OGame\Combat\Exceptions\CorruptedFrozenMoonPlan;
use OGame\Combat\Services\PhotographedUniverse;
use PHPUnit\Framework\TestCase;

/**
 * La porte de relecture des reglages d'univers refuse ce qui n'est pas un entier.
 *
 * ## Pourquoi un refus, et pas un transtypage
 *
 * `(int)'30'`, `(int)30.9` et `(int)true` donnent tous un entier plausible. Un document abime — une
 * valeur ecrite par une version anterieure, un JSON relu autrement, une main qui a corrige la base —
 * passerait pour un document valide, et la bataille se jouerait sur une part d'epaves que personne
 * n'a ecrite. Aux portes de confiance, l'exactitude se tient par un refus.
 *
 * Le tour complet est verifie aussi : ce qui est ecrit se relit identique, sinon le refus ne
 * protegerait qu'un chemin que le jeu n'emprunte jamais.
 */
final class PhotographedUniverseFactsTest extends TestCase
{
    public function testANumericStringSettingIsRefused(): void
    {
        $this->expectException(CorruptedFrozenMoonPlan::class);

        PhotographedUniverse::fromFrozenFacts(self::facts(['debris_field_from_ships' => '30']));
    }

    public function testAFloatSettingIsRefused(): void
    {
        $this->expectException(CorruptedFrozenMoonPlan::class);

        PhotographedUniverse::fromFrozenFacts(self::facts(['maximum_moon_chance' => 20.0]));
    }

    public function testAMissingSettingIsRefused(): void
    {
        $faits = self::facts();
        unset($faits['defense_repair_rate']);

        $this->expectException(CorruptedFrozenMoonPlan::class);

        PhotographedUniverse::fromFrozenFacts($faits);
    }

    public function testWhatIsWrittenIsReadBackIdentical(): void
    {
        $univers = new PhotographedUniverse(30, 15, 1, 150_000, 5, 70, 20);

        $relu = PhotographedUniverse::fromFrozenFacts($univers->toFrozenFacts());

        $this->assertSame($univers->toFrozenFacts(), $relu->toFrozenFacts());
    }

    /**
     * @param array<string, mixed> $remplacements
     * @return array<string, mixed>
     */
    private static function facts(array $remplacements = []): array
    {
        return $remplacements + [
            'debris_field_from_ships' => 30,
            'debris_field_from_defense' => 0,
            'debris_field_deuterium_on' => 0,
            'wreck_field_min_resources_loss' => 150_000,
            'wreck_field_min_fleet_percentage' => 5,
            'defense_repair_rate' => 70,
            'maximum_moon_chance' => 20,
        ];
    }
}
