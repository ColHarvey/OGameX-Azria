<?php

namespace Tests\Unit\Combat;

use OGame\Combat\Exceptions\CorruptedFrozenMoonPlan;
use OGame\Combat\Support\FrozenLifeformCombatBonuses;
use OGame\Services\ObjectService;
use Tests\UnitTestCase;

/**
 * Les bonus de formes de vie geles pour un combat : tour complet, lecture par unite, et refus de tout ce qui
 * n est pas un fait (journal §155.6).
 */
final class FrozenLifeformCombatBonusesTest extends UnitTestCase
{
    public function testWhatIsWrittenIsReadBackIdentical(): void
    {
        $bonus = new FrozenLifeformCombatBonuses(['light_fighter' => 3.0, 'defence' => 5.0], 0.3, 0.1, 0.06, 0.13);
        $relu = FrozenLifeformCombatBonuses::fromFrozenFacts($bonus->toFrozenFacts());

        $this->assertSame($bonus->toFrozenFacts(), $relu->toFrozenFacts());
        $this->assertFalse($relu->isNone());
        $this->assertTrue(FrozenLifeformCombatBonuses::none()->isNone());
        $this->assertSame(['unit_stats' => [], 'protected_share' => null, 'moon_chance' => 0.0, 'debris_recovery' => 0.0, 'wreck_recovery' => 0.0], FrozenLifeformCombatBonuses::none()->toFrozenFacts());
    }

    public function testTheUnitPercentIsReadByShipNameOrForAllDefences(): void
    {
        $bonus = new FrozenLifeformCombatBonuses(['light_fighter' => 3.0, 'defence' => 5.0], null, 0.0, 0.0, 0.0);

        $this->assertSame(3.0, $bonus->unitStatsPercent(ObjectService::getShipObjectByMachineName('light_fighter')));
        $this->assertSame(0.0, $bonus->unitStatsPercent(ObjectService::getShipObjectByMachineName('cruiser')));
        $this->assertSame(5.0, $bonus->unitStatsPercent(ObjectService::getUnitObjectByMachineName('rocket_launcher')));
        $this->assertSame(5.0, $bonus->unitStatsPercent(ObjectService::getUnitObjectByMachineName('plasma_turret')));
        $this->assertSame(0.0, FrozenLifeformCombatBonuses::none()->unitStatsPercent(ObjectService::getShipObjectByMachineName('light_fighter')));
    }

    public function testTheUnitsOfAnotherPhotographReplaceOnlyTheUnits(): void
    {
        $corps = new FrozenLifeformCombatBonuses(['defence' => 5.0], 0.3, 0.1, 0.06, 0.13);
        $flotte = new FrozenLifeformCombatBonuses(['cruiser' => 3.0], null, 0.0, 0.0, 0.0);

        $mixte = $corps->withUnitStatsOf($flotte);
        $this->assertSame(['cruiser' => 3.0], $mixte->unitStats);
        $this->assertSame(0.3, $mixte->protectedShare);
        $this->assertSame(0.1, $mixte->moonChance);
        $this->assertSame(0.06, $mixte->debrisRecovery);
        $this->assertSame(0.13, $mixte->wreckRecovery);
    }

    public function testANumericStringPercentIsRefused(): void
    {
        $this->expectException(CorruptedFrozenMoonPlan::class);

        FrozenLifeformCombatBonuses::fromFrozenFacts(self::facts(['unit_stats' => ['light_fighter' => '3']]));
    }

    public function testEveryOtherNonFactIsRefused(): void
    {
        foreach ([
            ['unit_stats' => 'rien'],
            ['unit_stats' => [0 => 3.0]],
            ['unit_stats' => ['light_fighter' => true]],
            ['unit_stats' => ['light_fighter' => -1]],
            ['protected_share' => '0.3'],
            ['protected_share' => 1.5],
            ['protected_share' => true],
            ['moon_chance' => null],
            ['debris_recovery' => '0.06'],
            ['wreck_recovery' => -0.1],
        ] as $i => $remplacement) {
            try {
                FrozenLifeformCombatBonuses::fromFrozenFacts(self::facts($remplacement));
                $this->fail("Le non-sens $i a ete relu au lieu d etre refuse.");
            } catch (CorruptedFrozenMoonPlan) {
                $this->addToAssertionCount(1);
            }
        }

        $sansPart = self::facts();
        unset($sansPart['protected_share']);
        $this->expectException(CorruptedFrozenMoonPlan::class);
        FrozenLifeformCombatBonuses::fromFrozenFacts($sansPart);
    }

    public function testAnIntegerPercentIsAcceptedAsANumber(): void
    {
        $relu = FrozenLifeformCombatBonuses::fromFrozenFacts(self::facts(['unit_stats' => ['light_fighter' => 3], 'protected_share' => 0, 'moon_chance' => 0, 'debris_recovery' => 0, 'wreck_recovery' => 0]));
        $this->assertSame(3.0, $relu->unitStats['light_fighter']);
        $this->assertSame(0.0, $relu->protectedShare);
    }

    /**
     * @param array<string, mixed> $remplacements
     * @return array<string, mixed>
     */
    private static function facts(array $remplacements = []): array
    {
        return $remplacements + ['unit_stats' => ['light_fighter' => 3.0], 'protected_share' => 0.3, 'moon_chance' => 0.1, 'debris_recovery' => 0.06, 'wreck_recovery' => 0.13];
    }
}
