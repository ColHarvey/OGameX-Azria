<?php

namespace Tests\Unit\Lifeforms;

use InvalidArgumentException;
use OGame\Lifeforms\Discovery\LifeformDiscoveryOutcome;
use OGame\Lifeforms\Discovery\LifeformDiscoveryRules;
use OGame\Lifeforms\Species;
use OGame\Models\Planet\Coordinate;
use PHPUnit\Framework\TestCase;

/**
 * Les regles des vols de decouverte : distance du jeu, duree Azria, tirage scelle et stockage de l issue.
 */
final class LifeformDiscoveryRulesTest extends TestCase
{
    public function testTheDistanceIsTheGameDistance(): void
    {
        $depart = new Coordinate(1, 100, 5);
        $this->assertSame(1000, LifeformDiscoveryRules::distance($depart, new Coordinate(1, 100, 5)));
        $this->assertSame(1050, LifeformDiscoveryRules::distance($depart, new Coordinate(1, 100, 15)));
        $this->assertSame(2795, LifeformDiscoveryRules::distance($depart, new Coordinate(1, 101, 1)));
        $this->assertSame(2700 + 95 * 399, LifeformDiscoveryRules::distance($depart, new Coordinate(1, 499, 1)));
        $this->assertSame(20000, LifeformDiscoveryRules::distance($depart, new Coordinate(2, 1, 1)));
        $this->assertSame(60000, LifeformDiscoveryRules::distance($depart, new Coordinate(4, 100, 5)));
    }

    public function testTheDurationGrowsWithDistanceAndShrinksWithEnvoysAndSpeed(): void
    {
        $this->assertSame(3960, LifeformDiscoveryRules::duration(1000, 0.0, 1.0), '3 600 × (1 + 0,1).');
        $this->assertSame(10800, LifeformDiscoveryRules::duration(20000, 0.0, 1.0), '3 600 × 3.');
        $this->assertSame(3564, LifeformDiscoveryRules::duration(1000, 0.10, 1.0), 'Emissaires a 10 %.');
        $this->assertSame(1980, LifeformDiscoveryRules::duration(1000, 0.0, 2.0), 'Coefficient 2.');
        $this->assertSame(60, LifeformDiscoveryRules::duration(0, 0.99, 100.0), 'Jamais sous la minute.');
        $this->assertSame(LifeformDiscoveryRules::duration(1000, 0.99, 1.0), LifeformDiscoveryRules::duration(1000, 5.0, 1.0), 'La reduction est bornee a 99 %.');

        $this->expectException(InvalidArgumentException::class);
        LifeformDiscoveryRules::duration(1000, 0.0, 0.0);
    }

    public function testTheDrawFollowsTheWeightsAndTheArtifactTiers(): void
    {
        $decouvertes = [Species::Humans];
        $tirages = static fn (array $suite) => static function (int $borne) use (&$suite): int {
            $v = array_shift($suite);
            if ($v === null || $v >= $borne) {
                throw new InvalidArgumentException("Tirage $v hors de $borne.");
            }

            return $v;
        };

        $rien = LifeformDiscoveryRules::draw($decouvertes, $tirages([29]));
        $this->assertSame(LifeformDiscoveryOutcome::NOTHING, $rien->kind);
        $this->assertSame(0, $rien->artifacts + $rien->experience);

        $huit = LifeformDiscoveryRules::draw($decouvertes, $tirages([30, 99]));
        $this->assertSame(LifeformDiscoveryOutcome::ARTIFACTS, $huit->kind);
        $this->assertSame(8, $huit->artifacts);
        $this->assertSame(25, LifeformDiscoveryRules::draw($decouvertes, $tirages([74, 9]))->artifacts);
        $this->assertSame(50, LifeformDiscoveryRules::draw($decouvertes, $tirages([74, 1]))->artifacts);

        $xp = LifeformDiscoveryRules::draw($decouvertes, $tirages([75, 0, 40]));
        $this->assertSame(LifeformDiscoveryOutcome::EXPERIENCE, $xp->kind);
        $this->assertSame(Species::Humans, $xp->species);
        $this->assertSame(80, $xp->experience, '40 + 40.');
        $this->assertSame(40, LifeformDiscoveryRules::draw($decouvertes, $tirages([96, 0, 0]))->experience);

        $nouvelle = LifeformDiscoveryRules::draw($decouvertes, $tirages([97, 2]));
        $this->assertSame(LifeformDiscoveryOutcome::SPECIES, $nouvelle->kind);
        $this->assertSame(Species::Kaelesh, $nouvelle->species, 'Les restantes sont Rocktal, Mechas, Kaelesh : l indice 2.');
        $this->assertSame(LifeformDiscoveryRules::NEW_SPECIES_EXPERIENCE, $nouvelle->experience);

        // Toutes decouvertes : le poids de l espece nouvelle va a l experience.
        $toutes = Species::cases();
        $repli = LifeformDiscoveryRules::draw($toutes, $tirages([99, 3, 10]));
        $this->assertSame(LifeformDiscoveryOutcome::EXPERIENCE, $repli->kind);
        $this->assertSame(Species::Kaelesh, $repli->species);
    }

    public function testADrawNeedsADiscoveredSpecies(): void
    {
        $this->expectException(InvalidArgumentException::class);
        LifeformDiscoveryRules::draw([]);
    }

    public function testTheOutcomeSurvivesStorageAndRefusesNonsense(): void
    {
        $issue = new LifeformDiscoveryOutcome(LifeformDiscoveryOutcome::SPECIES, Species::Mechas, 0, 100);
        $relue = LifeformDiscoveryOutcome::fromStorage($issue->toStorage());
        $this->assertSame($issue->kind, $relue->kind);
        $this->assertSame($issue->species, $relue->species);
        $this->assertSame($issue->artifacts, $relue->artifacts);
        $this->assertSame($issue->experience, $relue->experience);

        $this->assertSame(['kind' => 'artifacts', 'species' => null, 'artifacts' => 25, 'experience' => 0], (new LifeformDiscoveryOutcome(LifeformDiscoveryOutcome::ARTIFACTS, null, 25, 0))->toStorage());

        foreach ([
            fn () => new LifeformDiscoveryOutcome('jackpot', null, 0, 0),
            fn () => new LifeformDiscoveryOutcome(LifeformDiscoveryOutcome::ARTIFACTS, null, -1, 0),
            fn () => new LifeformDiscoveryOutcome(LifeformDiscoveryOutcome::EXPERIENCE, null, 0, 40),
            fn () => LifeformDiscoveryOutcome::fromStorage(['kind' => 'nothing', 'artifacts' => '8']),
        ] as $i => $faute) {
            try {
                $faute();
                $this->fail("La faute $i devait etre refusee.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTheArtifactTiersAndTheWeightsSumToOneHundred(): void
    {
        $this->assertSame(100, array_sum(LifeformDiscoveryRules::WEIGHTS));
        $this->assertSame(50, LifeformDiscoveryRules::artifactsFound(0));
        $this->assertSame(50, LifeformDiscoveryRules::artifactsFound(1));
        $this->assertSame(25, LifeformDiscoveryRules::artifactsFound(2));
        $this->assertSame(25, LifeformDiscoveryRules::artifactsFound(9));
        $this->assertSame(8, LifeformDiscoveryRules::artifactsFound(10));
        $this->assertSame(8, LifeformDiscoveryRules::artifactsFound(99));
        $this->assertSame(5000, (int)LifeformDiscoveryRules::cost()->metal->get());
        $this->assertSame(1000, (int)LifeformDiscoveryRules::cost()->crystal->get());
        $this->assertSame(500, (int)LifeformDiscoveryRules::cost()->deuterium->get());
    }
}
