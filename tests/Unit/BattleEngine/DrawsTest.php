<?php

namespace Tests\Unit\BattleEngine;

use InvalidArgumentException;
use OGame\GameMissions\BattleEngine\Draws\BattleDraws;
use OGame\GameMissions\BattleEngine\Draws\Draw;
use OGame\GameMissions\BattleEngine\Draws\DrawJournal;
use OGame\GameMissions\BattleEngine\Draws\RawDraws;
use OGame\GameMissions\BattleEngine\Draws\SeededDraws;
use OGame\GameMissions\BattleEngine\Draws\SystemDraws;
use OGame\GameMissions\BattleEngine\Draws\Xorshift32;
use PHPUnit\Framework\TestCase;

/**
 * La bande de tirages : les frontieres PHP epinglees en valeurs ecrites en dur, la suite a graine
 * identique a celle du moteur Rust, le journal qui nomme chaque tirage, et les bornes.
 */
class DrawsTest extends TestCase
{
    /**
     * Les frontieres du moteur PHP, telles qu'elles etaient : un pour-cent entier de 0 a 100
     * compare strictement a la chance, un centieme de pour-cent de 0,01 a 100,00 compare
     * largement. Avec une chance de 50,9, les tirages 0..50 explosent et 51..100 non — cent une
     * valeurs, pas cent. Le moteur Rust reproduit ces frontieres, et le banc de parite le verifie.
     */
    public function testTheExplosionAndRapidfireBoundariesArePinned(): void
    {
        $this->assertTrue(Draw::explodes($this->drawing(explosion: 50), 50.9));
        $this->assertFalse(Draw::explodes($this->drawing(explosion: 51), 50.9));
        $this->assertTrue(Draw::explodes($this->drawing(explosion: 0), 0.5));
        $this->assertFalse(Draw::explodes($this->drawing(explosion: 0), 0.0));
        $this->assertTrue(Draw::explodes($this->drawing(explosion: 100), 100.5));
        $this->assertFalse(Draw::explodes($this->drawing(explosion: 100), 100.0));

        $this->assertTrue(Draw::rapidfire($this->drawing(rapidfire: 7500), 75.0));
        $this->assertFalse(Draw::rapidfire($this->drawing(rapidfire: 7501), 75.0));
        $this->assertTrue(Draw::rapidfire($this->drawing(rapidfire: 1), 0.01));
        $this->assertFalse(Draw::rapidfire($this->drawing(rapidfire: 1), 0.0));
        $this->assertTrue(Draw::rapidfire($this->drawing(rapidfire: 10000), 100.0));
    }

    /**
     * Les trois premiers tirages bruts de la graine 1, calcules a la main depuis l'algorithme ;
     * le moteur Rust affirme les memes (`the_xorshift_matches_the_php_sequence`).
     */
    public function testTheSeededSequenceIsTheOneTheRustEngineDraws(): void
    {
        $brute = new Xorshift32(1);

        $this->assertSame(270369, $brute->next());
        $this->assertSame(67634689, $brute->next());
        $this->assertSame(2647435461, $brute->next());
    }

    /**
     * Un tirage borne rejette la queue au-dela du plus grand multiple de la borne, puis reduit ;
     * chaque tirage brut consomme est compte, rejete ou retenu. Les memes suites dictees et les
     * memes attendus sont affirmes cote Rust (`a_bounded_draw_rejects_the_tail_and_counts_every_raw_draw`).
     */
    public function testABoundedDrawRejectsTheTailAndCountsEveryRawDraw(): void
    {
        // Borne 3 : 2^32 % 3 = 1, la limite est 2^32 - 1 et 0xFFFFFFFF est rejete une fois.
        $tirages = new SeededDraws($this->dictated([0xFFFFFFFF, 5]));
        $this->assertSame(2, $tirages->targetIndex(3));
        $this->assertSame(1, $tirages->journal()->count(), 'one semantic draw');
        $this->assertSame(2, $tirages->journal()->rawCount(), 'two raw draws: one rejected, one kept');

        // Borne 1 : jamais de rejet, toujours 0.
        $tirages = new SeededDraws($this->dictated([0xFFFFFFFF]));
        $this->assertSame(0, $tirages->targetIndex(1));
        $this->assertSame(1, $tirages->journal()->rawCount());

        // Borne 101 : limite 4294967228.
        $tirages = new SeededDraws($this->dictated([4294967228, 4294967227]));
        $this->assertSame(4294967227 % 101, $tirages->explosionPercent());
        $this->assertSame(2, $tirages->journal()->rawCount());

        // Borne 10000 : limite 4294960000.
        $tirages = new SeededDraws($this->dictated([4294960000, 123456]));
        $this->assertSame(1 + (123456 % 10000), $tirages->rapidfireCentipercent());
        $this->assertSame(2, $tirages->journal()->rawCount());

        // Treize cibles, une borne qui n'est pas une puissance de deux : limite 4294967287.
        $tirages = new SeededDraws($this->dictated([4294967290, 20]));
        $this->assertSame(20 % 13, $tirages->targetIndex(13));
        $this->assertSame(2, $tirages->journal()->rawCount());

        // Une suite dictee n'a pas de graine : le moteur Rust ne pourrait pas la rejouer.
        $this->assertNull($tirages->seed());
        $this->expectException(InvalidArgumentException::class);
        $tirages->forRounds();
    }

    /**
     * Le journal nomme chaque tirage — genre, borne, valeur — et son empreinte est celle que le
     * moteur Rust calcule pour la meme bande (`the_journal_digest_distinguishes_kinds_and_order`).
     */
    public function testTheJournalIsPinnedAgainstTheRustEngine(): void
    {
        $tirages = new SeededDraws(1);

        $this->assertSame(8, $tirages->targetIndex(13));
        $this->assertSame(39, $tirages->explosionPercent());
        $this->assertSame(5462, $tirages->rapidfireCentipercent());

        $this->assertSame(3, $tirages->journal()->count());
        $this->assertSame('3b66012af9879de4', $tirages->journal()->digest());
        $this->assertSame($tirages->journal()->digest(), DrawJournal::fnv1a64('target:13:8;explosion:101:39;rapidfire:10000:5462;'));
    }

    /**
     * L'empreinte distingue deux consommations differentes des memes nombres : un autre genre, un
     * autre ordre. Une suite de nombres bruts ne le dirait pas.
     */
    public function testTheJournalDistinguishesKindsAndOrder(): void
    {
        $premiere = new SeededDraws(9);
        $premiere->targetIndex(13);
        $premiere->explosionPercent();

        $seconde = new SeededDraws(9);
        $seconde->explosionPercent();
        $seconde->targetIndex(13);

        $this->assertSame($premiere->journal()->count(), $seconde->journal()->count());
        $this->assertNotSame($premiere->journal()->digest(), $seconde->journal()->digest());
    }

    /**
     * L'arithmetique de l'empreinte, contre les vecteurs publics de FNV-1a sur soixante-quatre bits.
     */
    public function testTheDigestArithmeticMatchesThePublicFnvVectors(): void
    {
        $this->assertSame('cbf29ce484222325', DrawJournal::fnv1a64(''));
        $this->assertSame('af63dc4c8601ec8c', DrawJournal::fnv1a64('a'));
        $this->assertSame('85944171f73967e8', DrawJournal::fnv1a64('foobar'));
    }

    public function testTheSameSeedDrawsTheSameSequenceAndARoundSourceStartsAfresh(): void
    {
        $premiere = new SeededDraws(20260904);
        $seconde = new SeededDraws(20260904);

        for ($i = 0; $i < 500; $i++) {
            $this->assertSame($premiere->targetIndex(97), $seconde->targetIndex(97));
        }

        $this->assertSame($premiere->journal()->digest(), $seconde->journal()->digest());

        $rounds = $premiere->forRounds();
        $this->assertNotSame($premiere, $rounds);
        $this->assertSame((new SeededDraws(20260904))->targetIndex(97), $rounds->targetIndex(97), 'The round source does not start afresh from the seed.');
    }

    public function testEveryDrawStaysWithinItsBound(): void
    {
        foreach ([new SeededDraws(0xFFFFFFFF), new SystemDraws()] as $source) {
            for ($i = 0; $i < 500; $i++) {
                $this->assertLessThan(13, $source->targetIndex(13));
                $this->assertGreaterThanOrEqual(0, $source->targetIndex(13));
                $this->assertLessThanOrEqual(100, $source->explosionPercent());
                $this->assertGreaterThanOrEqual(0, $source->explosionPercent());
                $this->assertLessThanOrEqual(10000, $source->rapidfireCentipercent());
                $this->assertGreaterThanOrEqual(1, $source->rapidfireCentipercent());
                $this->assertLessThanOrEqual(7, $source->chanceOutOf(7));
                $this->assertGreaterThanOrEqual(1, $source->chanceOutOf(7));
            }
        }

        $this->assertNull((new SystemDraws())->journal());
    }

    public function testMeaninglessBoundsAndSeedsAreRefused(): void
    {
        foreach ([0, -1, 0x100000000] as $graine) {
            try {
                new SeededDraws($graine);
                $this->fail('Seed ' . $graine . ' was accepted.');
            } catch (InvalidArgumentException $refus) {
                $this->assertStringContainsString((string)$graine, $refus->getMessage());
            }
        }

        foreach ([new SeededDraws(3), new SystemDraws()] as $source) {
            try {
                $source->targetIndex(0);
                $this->fail('A target was drawn among no candidate.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }

            try {
                $source->chanceOutOf(0);
                $this->fail('A chance was drawn out of nothing.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Une suite brute dictee, pour forcer un rejet et epingler les tirages consommes.
     *
     * @param array<int, int> $values
     */
    private function dictated(array $values): RawDraws
    {
        return new class ($values) implements RawDraws {
            private int $next = 0;

            /**
             * @param array<int, int> $values
             */
            public function __construct(private readonly array $values)
            {
            }

            public function next(): int
            {
                return $this->values[$this->next++ % count($this->values)];
            }
        };
    }

    /**
     * Une source qui rend les valeurs dictees, pour epingler une frontiere.
     */
    private function drawing(int $explosion = 0, int $rapidfire = 1): BattleDraws
    {
        return new class ($explosion, $rapidfire) implements BattleDraws {
            public function __construct(private int $explosion, private int $rapidfire)
            {
            }

            public function targetIndex(int $count): int
            {
                return 0;
            }

            public function explosionPercent(): int
            {
                return $this->explosion;
            }

            public function rapidfireCentipercent(): int
            {
                return $this->rapidfire;
            }

            public function chanceOutOf(int $bound): int
            {
                return 1;
            }

            public function forRounds(): BattleDraws
            {
                return $this;
            }

            public function journal(): DrawJournal|null
            {
                return null;
            }
        };
    }
}
