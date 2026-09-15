<?php

namespace Tests\Unit\Lifeforms;

use InvalidArgumentException;
use OGame\Lifeforms\Research\LifeformExperience;
use OGame\Lifeforms\Research\LifeformSlotRules;
use Tests\UnitTestCase;

/**
 * Les regles des emplacements de recherche et de l experience, pures.
 */
final class LifeformResearchRulesTest extends UnitTestCase
{
    public function testTheEighteenSlotsFallInThreeTiersOfSix(): void
    {
        $this->assertSame([1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 2, 2, 3, 3, 3, 3, 3, 3], array_map(fn (int $s) => LifeformSlotRules::tierOf($s), range(1, 18)));
        $this->assertSame([1, 2, 3, 4, 5, 6, 1, 2, 3, 4, 5, 6, 1, 2, 3, 4, 5, 6], array_map(fn (int $s) => LifeformSlotRules::positionOf($s), range(1, 18)));
        $this->assertSame(7, LifeformSlotRules::slotOf(2, 1));
        $this->assertSame([13, 14, 15, 16, 17, 18], LifeformSlotRules::slotsOfTier(3));
        $this->expectException(InvalidArgumentException::class);
        LifeformSlotRules::tierOf(19);
    }

    public function testTierTwoRequirementsAreTheObservedOnesAndTheOthersAreLinear(): void
    {
        $this->assertSame([1200000.0, 3000000.0, 5000000.0, 7000000.0, 9000000.0, 11000000.0], array_map(fn (int $s) => LifeformSlotRules::populationRequired($s), range(7, 12)), 'Page reelle : « Requires 1,200,000 T2 population » … 11 000 000.');
        $this->assertSame(200000.0, LifeformSlotRules::populationRequired(1));
        $this->assertSame(1000000.0, LifeformSlotRules::populationRequired(6));
        $this->assertSame(13000000.0, LifeformSlotRules::populationRequired(13));
        $this->assertSame(448000000.0, LifeformSlotRules::populationRequired(18));
        for ($slot = 2; $slot <= 18; $slot++) {
            $this->assertGreaterThan(LifeformSlotRules::populationRequired($slot - 1), LifeformSlotRules::populationRequired($slot), "L exigence croit avec l emplacement ($slot).");
        }
        $this->assertEqualsWithDelta(140000.0, LifeformSlotRules::populationRequired(1, 0.30), 1e-6, 'Le Modulateur psionique retire sa fraction.');
        $this->assertSame([1 => 200, 2 => 400, 3 => 600], LifeformSlotRules::ARTIFACT_COST);
    }

    public function testExperienceLevelsCostAThousandTimesTheLevelAndGiveATenthOfAPercentEach(): void
    {
        $this->assertSame(0, LifeformExperience::levelOf(0));
        $this->assertSame(0, LifeformExperience::levelOf(999));
        $this->assertSame(1, LifeformExperience::levelOf(1000));
        $this->assertSame(1, LifeformExperience::levelOf(2999));
        $this->assertSame(2, LifeformExperience::levelOf(3000));
        $this->assertSame(100, LifeformExperience::levelOf(5050000));
        $this->assertSame(100, LifeformExperience::levelOf(99999999), 'Le niveau 100 est le dernier.');
        $this->assertSame([500, 1000], LifeformExperience::progressOf(500));
        $this->assertSame([0, 2000], LifeformExperience::progressOf(1000));
        $this->assertSame([1500, 3000], LifeformExperience::progressOf(4500));
        $this->assertSame([0, 0], LifeformExperience::progressOf(5050000));
        $this->assertEqualsWithDelta(0.004, LifeformExperience::bonusFraction(4), 1e-12, 'Page reelle : niveau 4, bonus 0,4 %.');
        $this->assertEqualsWithDelta(0.1, LifeformExperience::bonusFraction(100), 1e-12);
        $this->assertEqualsWithDelta(0.1, LifeformExperience::bonusFraction(250), 1e-12, 'Plafonne a 10 %.');
    }
}
