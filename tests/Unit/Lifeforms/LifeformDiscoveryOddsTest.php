<?php

namespace Tests\Unit\Lifeforms;

use InvalidArgumentException;
use OGame\Lifeforms\Discovery\LifeformDiscoveryOdds;
use OGame\Lifeforms\Discovery\LifeformDiscoveryOutcome;
use OGame\Lifeforms\Discovery\LifeformDiscoveryRules;
use OGame\Lifeforms\Species;
use PHPUnit\Framework\TestCase;

/**
 * Les cotes d artefacts des vols de decouverte (journal §159) : les valeurs de depart sont exactement le
 * comportement d avant, un changement de chance ne prend et ne rend qu a « rien », le tirage suit les cotes
 * qu on lui donne, et tout ce qui est incoherent est refuse au lieu d etre ramene.
 */
final class LifeformDiscoveryOddsTest extends TestCase
{
    public function testTheDefaultsAreExactlyTheFormerBehaviour(): void
    {
        $depart = LifeformDiscoveryOdds::defaults();
        $this->assertSame(45, $depart->artifactChance);
        $this->assertSame(30, $depart->nothingChance());
        $this->assertSame([8, 25, 50], [$depart->small, $depart->medium, $depart->large]);
        $this->assertSame([90, 8, 2], [$depart->smallChance(), $depart->mediumChance, $depart->largeChance]);
        $this->assertSame(LifeformDiscoveryRules::WEIGHTS, $depart->weights(), 'Les poids de depart sont ceux du tirage d avant.');
        $this->assertSame(100, array_sum($depart->weights()));
        $this->assertSame(4.59, $depart->meanPerFlight(), '0,45 × (0,9 × 8 + 0,08 × 25 + 0,02 × 50).');
        $this->assertSame(75, LifeformDiscoveryOdds::MAX_ARTIFACT_CHANCE);

        // Les paliers de trouvaille, tirage par tirage, comme avant.
        foreach ([0 => 50, 1 => 50, 2 => 25, 9 => 25, 10 => 8, 99 => 8] as $tirage => $attendu) {
            $this->assertSame($attendu, $depart->artifactsFound($tirage), "Tirage $tirage.");
            $this->assertSame($attendu, LifeformDiscoveryRules::artifactsFound($tirage), "L ancienne entree rend la meme chose ($tirage).");
        }
    }

    public function testAChangeOfChanceOnlyMovesNothingNeverExperienceNorSpecies(): void
    {
        foreach ([0, 10, 45, 60, 75] as $chance) {
            $cotes = new LifeformDiscoveryOdds($chance, 8, 25, 50, 8, 2);
            $poids = $cotes->weights();
            $this->assertSame($chance, $poids[LifeformDiscoveryOutcome::ARTIFACTS]);
            $this->assertSame(75 - $chance, $poids[LifeformDiscoveryOutcome::NOTHING], "Rien absorbe la difference ($chance).");
            $this->assertSame(22, $poids[LifeformDiscoveryOutcome::EXPERIENCE], 'L experience ne bouge pas.');
            $this->assertSame(3, $poids[LifeformDiscoveryOutcome::SPECIES], 'L espece nouvelle ne bouge pas.');
            $this->assertSame(100, array_sum($poids));
        }
    }

    public function testTheDrawFollowsTheOddsItIsGiven(): void
    {
        $tirages = static fn (array $suite) => static function (int $borne) use (&$suite): int {
            $v = array_shift($suite);
            if ($v === null || $v >= $borne) {
                throw new InvalidArgumentException("Tirage $v hors de $borne.");
            }

            return $v;
        };
        $decouvertes = [Species::Humans];

        // Chance 60 : rien 15, artefacts de 15 a 74 ; trouvailles 10 / 40 / 100 aux parts 20 / 5 (petite 75).
        $cotes = new LifeformDiscoveryOdds(60, 10, 40, 100, 20, 5);
        $this->assertSame(LifeformDiscoveryOutcome::NOTHING, LifeformDiscoveryRules::draw($decouvertes, $tirages([14]), $cotes)->kind);
        $quinze = LifeformDiscoveryRules::draw($decouvertes, $tirages([15, 99]), $cotes);
        $this->assertSame(LifeformDiscoveryOutcome::ARTIFACTS, $quinze->kind);
        $this->assertSame(10, $quinze->artifacts);
        $this->assertSame(100, LifeformDiscoveryRules::draw($decouvertes, $tirages([74, 4]), $cotes)->artifacts, 'Grande : tirages 0 a 4.');
        $this->assertSame(40, LifeformDiscoveryRules::draw($decouvertes, $tirages([74, 5]), $cotes)->artifacts, 'Moyenne : tirages 5 a 24.');
        $this->assertSame(40, LifeformDiscoveryRules::draw($decouvertes, $tirages([74, 24]), $cotes)->artifacts);
        $this->assertSame(10, LifeformDiscoveryRules::draw($decouvertes, $tirages([74, 25]), $cotes)->artifacts);
        $xp = LifeformDiscoveryRules::draw($decouvertes, $tirages([75, 0, 0]), $cotes);
        $this->assertSame(LifeformDiscoveryOutcome::EXPERIENCE, $xp->kind, 'L experience commence toujours a 75 : la chance ne l a pas deplacee.');
        $this->assertSame(LifeformDiscoveryOutcome::SPECIES, LifeformDiscoveryRules::draw($decouvertes, $tirages([97, 0]), $cotes)->kind);

        // Chance 0 : aucun artefact, jamais ; rien de 0 a 74.
        $sans = new LifeformDiscoveryOdds(0, 8, 25, 50, 8, 2);
        $this->assertSame(LifeformDiscoveryOutcome::NOTHING, LifeformDiscoveryRules::draw($decouvertes, $tirages([74]), $sans)->kind);
        $this->assertSame(LifeformDiscoveryOutcome::EXPERIENCE, LifeformDiscoveryRules::draw($decouvertes, $tirages([75, 0, 0]), $sans)->kind);

        // Sans cotes : celles de depart — le meme tirage rend la meme issue qu avant.
        $this->assertSame(25, LifeformDiscoveryRules::draw($decouvertes, $tirages([74, 9]))->artifacts);
        $this->assertSame(25, LifeformDiscoveryRules::draw($decouvertes, $tirages([74, 9]), LifeformDiscoveryOdds::defaults())->artifacts);
    }

    public function testEverythingIncoherentIsRefusedNotTamed(): void
    {
        $fautes = [
            'chance negative' => fn () => new LifeformDiscoveryOdds(-1, 8, 25, 50, 8, 2),
            'chance au-dela de 75' => fn () => new LifeformDiscoveryOdds(76, 8, 25, 50, 8, 2),
            'petite trouvaille nulle' => fn () => new LifeformDiscoveryOdds(45, 0, 25, 50, 8, 2),
            'grande trouvaille au-dela de la reserve' => fn () => new LifeformDiscoveryOdds(45, 8, 25, 3601, 8, 2),
            'petite plus grande que la moyenne' => fn () => new LifeformDiscoveryOdds(45, 30, 25, 50, 8, 2),
            'moyenne plus grande que la grande' => fn () => new LifeformDiscoveryOdds(45, 8, 60, 50, 8, 2),
            'part moyenne negative' => fn () => new LifeformDiscoveryOdds(45, 8, 25, 50, -1, 2),
            'parts au-dela de cent' => fn () => new LifeformDiscoveryOdds(45, 8, 25, 50, 60, 41),
            'tirage hors bornes' => fn () => LifeformDiscoveryOdds::defaults()->artifactsFound(100),
            'photographie en chaines' => fn () => LifeformDiscoveryOdds::fromStorage(['artifact_chance' => '45', 'small' => 8, 'medium' => 25, 'large' => 50, 'medium_chance' => 8, 'large_chance' => 2]),
            'photographie en flottants' => fn () => LifeformDiscoveryOdds::fromStorage(['artifact_chance' => 45.0, 'small' => 8, 'medium' => 25, 'large' => 50, 'medium_chance' => 8, 'large_chance' => 2]),
            'photographie incomplete' => fn () => LifeformDiscoveryOdds::fromStorage(['artifact_chance' => 45]),
        ];
        foreach ($fautes as $nom => $faute) {
            try {
                $faute();
                $this->fail("« $nom » devait etre refuse.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        // Les bornes elles-memes passent.
        $this->assertSame(75, (new LifeformDiscoveryOdds(75, 1, 1, 3600, 0, 100))->artifactChance);
        $this->assertSame(0, (new LifeformDiscoveryOdds(75, 1, 1, 3600, 0, 100))->nothingChance());
        $this->assertSame(0, (new LifeformDiscoveryOdds(45, 8, 25, 50, 50, 50))->smallChance());
    }

    public function testTheOddsSurviveStorageFieldByField(): void
    {
        $cotes = new LifeformDiscoveryOdds(60, 10, 40, 100, 20, 5);
        $this->assertSame(['artifact_chance' => 60, 'small' => 10, 'medium' => 40, 'large' => 100, 'medium_chance' => 20, 'large_chance' => 5], $cotes->toStorage());
        $relues = LifeformDiscoveryOdds::fromStorage($cotes->toStorage());
        $this->assertTrue($cotes->equals($relues));
        $this->assertFalse($cotes->equals(new LifeformDiscoveryOdds(60, 10, 40, 100, 20, 4)));
        $this->assertFalse($cotes->equals(LifeformDiscoveryOdds::defaults()));
        $this->assertSame(12.3, $relues->meanPerFlight(), '0,6 × (0,75 × 10 + 0,2 × 40 + 0,05 × 100), arrondi au centieme.');
    }
}
