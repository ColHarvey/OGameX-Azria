<?php

namespace Tests\Unit\BattleEngine;

use InvalidArgumentException;
use OGame\GameMissions\BattleEngine\Draws\Draw;
use OGame\GameMissions\BattleEngine\Draws\SeededDraws;
use OGame\GameMissions\BattleEngine\Draws\SystemDraws;
use PHPUnit\Framework\TestCase;

/**
 * La bande de tirages : rejouable a la graine, identique a celle du moteur Rust, et bornee.
 */
class DrawsTest extends TestCase
{
    /**
     * Les trois premiers tirages de la graine 1, calcules a la main depuis l'algorithme.
     *
     * Le moteur Rust affirme les memes trois valeurs (`the_xorshift_matches_the_php_sequence`) : c'est
     * ce qui fait qu'une meme graine joue la meme bataille des deux cotes.
     */
    public function testTheSeededSequenceIsTheOneTheRustEngineDraws(): void
    {
        $tirages = new SeededDraws(1);

        $this->assertSame(270369, $tirages->next());
        $this->assertSame(67634689, $tirages->next());
        $this->assertSame(2647435461, $tirages->next());
    }

    public function testTheSameSeedDrawsTheSameSequence(): void
    {
        $premiere = new SeededDraws(20260904);
        $seconde = new SeededDraws(20260904);

        for ($i = 0; $i < 1000; $i++) {
            $this->assertSame($premiere->next(), $seconde->next());
        }
    }

    public function testEveryDrawIsAThirtyTwoBitInteger(): void
    {
        foreach ([new SeededDraws(0xFFFFFFFF), new SystemDraws()] as $source) {
            for ($i = 0; $i < 1000; $i++) {
                $tirage = $source->next();
                $this->assertGreaterThanOrEqual(0, $tirage);
                $this->assertLessThanOrEqual(0xFFFFFFFF, $tirage);
            }
        }
    }

    public function testTheSeedZeroAndOutOfRangeSeedsAreRefused(): void
    {
        foreach ([0, -1, 0x100000000] as $graine) {
            try {
                new SeededDraws($graine);
                $this->fail('Seed ' . $graine . ' was accepted.');
            } catch (InvalidArgumentException $refus) {
                $this->assertStringContainsString((string)$graine, $refus->getMessage());
            }
        }
    }

    public function testAnIndexIsDrawnAmongTheCandidatesAndNeverAmongNone(): void
    {
        $tirages = new SeededDraws(7);

        for ($i = 0; $i < 500; $i++) {
            $index = Draw::index($tirages, 13);
            $this->assertGreaterThanOrEqual(0, $index);
            $this->assertLessThan(13, $index);
        }

        $this->expectException(InvalidArgumentException::class);
        Draw::index($tirages, 0);
    }

    public function testAnExplosionNeverHappensAtChanceZeroAndAlwaysAboveAHundred(): void
    {
        $tirages = new SeededDraws(11);

        for ($i = 0; $i < 300; $i++) {
            $this->assertFalse(Draw::explodes($tirages, 0.0));
            $this->assertTrue(Draw::explodes($tirages, 100.5));
        }
    }

    public function testRapidfireNeverHappensAtChanceZeroAndAlwaysAtAHundred(): void
    {
        $tirages = new SeededDraws(13);

        for ($i = 0; $i < 300; $i++) {
            $this->assertFalse(Draw::rapidfire($tirages, 0.0));
            $this->assertTrue(Draw::rapidfire($tirages, 100.0));
        }
    }
}
