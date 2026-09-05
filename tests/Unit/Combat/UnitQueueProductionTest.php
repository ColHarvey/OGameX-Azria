<?php

namespace Tests\Unit\Combat;

use OGame\Combat\Support\UnitQueueProduction;
use PHPUnit\Framework\TestCase;

/**
 * La formule de production progressive, aux bornes.
 *
 * Le monde et la fermeture d'un combat durable comptent avec elle ; une unite d'ecart a une borne
 * serait une unite creee ou perdue entre les deux.
 */
final class UnitQueueProductionTest extends TestCase
{
    public function testNothingIsFinishedBeforeTheStart(): void
    {
        $this->assertSame(0, UnitQueueProduction::unitsFinishedBy(100, 200, 10, 99));
        $this->assertSame(0, UnitQueueProduction::unitsFinishedBy(100, 200, 10, 100));
    }

    public function testEverythingIsFinishedAtTheEnd(): void
    {
        $this->assertSame(10, UnitQueueProduction::unitsFinishedBy(100, 200, 10, 200));
        $this->assertSame(10, UnitQueueProduction::unitsFinishedBy(100, 200, 10, 5_000));
    }

    /**
     * Une unite toutes les dix secondes : la n-ieme finit a `debut + 10 n`, et compte a cet instant meme.
     */
    public function testAUnitCountsAtTheVeryInstantItFinishes(): void
    {
        $this->assertSame(0, UnitQueueProduction::unitsFinishedBy(100, 200, 10, 109));
        $this->assertSame(1, UnitQueueProduction::unitsFinishedBy(100, 200, 10, 110));
        $this->assertSame(5, UnitQueueProduction::unitsFinishedBy(100, 200, 10, 159));
        $this->assertSame(6, UnitQueueProduction::unitsFinishedBy(100, 200, 10, 160));
    }

    public function testADegenerateBatchIsFinishedAtOnce(): void
    {
        $this->assertSame(0, UnitQueueProduction::unitsFinishedBy(100, 200, 0, 150));
        $this->assertSame(3, UnitQueueProduction::unitsFinishedBy(100, 100, 3, 100));
    }

    public function testTheFinishInstantOfTheNthUnitIsWhereTheCountChanges(): void
    {
        $this->assertSame(100, UnitQueueProduction::finishInstantOf(100, 200, 10, 0));
        $this->assertSame(110, UnitQueueProduction::finishInstantOf(100, 200, 10, 1));
        $this->assertSame(160, UnitQueueProduction::finishInstantOf(100, 200, 10, 6));
        $this->assertSame(200, UnitQueueProduction::finishInstantOf(100, 200, 10, 10));
        $this->assertSame(200, UnitQueueProduction::finishInstantOf(100, 200, 10, 11));

        // Le compte change exactement la : une unite de plus a cet instant, pas une seconde avant.
        $instant = UnitQueueProduction::finishInstantOf(100, 205, 12, 7);
        $this->assertSame(7, UnitQueueProduction::unitsFinishedBy(100, 205, 12, $instant));
        $this->assertSame(6, UnitQueueProduction::unitsFinishedBy(100, 205, 12, $instant - 1));
    }
}
