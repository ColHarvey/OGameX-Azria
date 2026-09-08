<?php

namespace Tests\Unit\Patrol;

use InvalidArgumentException;
use OGame\Patrol\Geometry\SpatialPoint;
use OGame\Patrol\Geometry\SystemGeometry;
use Tests\UnitTestCase;

/**
 * La geometrie de reference d un systeme : ce que le serveur tient pour vrai, sans la carte.
 *
 * Les valeurs epinglees ici sont **calculees une fois et ecrites en dur** : un temoin qui
 * rederiverait la formule ne prouverait que la formule contre elle-meme.
 */
class SystemGeometryTest extends UnitTestCase
{
    private function geometry(): SystemGeometry
    {
        // Les valeurs proposees du document de parametres : grille 10, rayon 1800, exclusion 60, diviseur 3.
        return new SystemGeometry(10, 1800, 60, 3);
    }

    public function testEveryOrbitReferencePointSitsOnItsOwnOrbit(): void
    {
        $geometry = $this->geometry();

        for ($position = 1; $position <= 16; $position++) {
            $point = $geometry->bodyPoint($position);

            $this->assertEqualsWithDelta(100 * $position, $point->norm(), 1.0, "Position $position is off its orbit.");
            $this->assertSame($position, $geometry->orbitIndexOf($point), "Position $position does not map back to its own orbit.");
        }
    }

    public function testTheReferencePointsAreThoseOfTheMapBeforeItsRotation(): void
    {
        $geometry = $this->geometry();

        // Angle d or 137,508°, moins 90°, comme `anglesDeBase()` dans galaxy-tactical.js.
        $this->assertTrue($geometry->bodyPoint(1)->equals(new SpatialPoint(68, 74)));
        $this->assertTrue($geometry->bodyPoint(2)->equals(new SpatialPoint(-199, -17)));
        $this->assertTrue($geometry->bodyPoint(5)->equals(new SpatialPoint(-268, -422)));
        $this->assertTrue($geometry->bodyPoint(15)->equals(new SpatialPoint(-1488, 193)));
        $this->assertTrue($geometry->bodyPoint(16)->equals(new SpatialPoint(1031, -1223)));
    }

    public function testAnUnknownOrbitIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->geometry()->bodyPoint(17);
    }

    public function testTheInternalDistanceGrowsWithTheRealDistance(): void
    {
        $geometry = $this->geometry();
        $origin = new SpatialPoint(0, 500);

        // Les trois trajets des simulations de la revue 121 : proche, moyen, traversee.
        $this->assertSame(34, $geometry->gameDistanceWithinSystem($origin, new SpatialPoint(100, 500)));
        $this->assertSame(334, $geometry->gameDistanceWithinSystem($origin, new SpatialPoint(1000, 500)));
        $this->assertSame(1000, $geometry->gameDistanceWithinSystem($origin, new SpatialPoint(3000, 500)));

        // La diagonale compte sa vraie longueur, et la distance est symetrique.
        $this->assertSame(48, $geometry->gameDistanceWithinSystem($origin, new SpatialPoint(100, 600)));
        $this->assertSame(48, $geometry->gameDistanceWithinSystem(new SpatialPoint(100, 600), $origin));

        // Deux points identiques valent le minimum du jeu, jamais zero.
        $this->assertSame(5, $geometry->gameDistanceWithinSystem($origin, $origin));
        $this->assertSame(5, $geometry->gameDistanceWithinSystem($origin, new SpatialPoint(10, 500)));
    }

    public function testAClickIsRoundedToTheGrid(): void
    {
        $geometry = $this->geometry();

        $this->assertTrue($geometry->snap(123.4, -87.6)->equals(new SpatialPoint(120, -90)));
        $this->assertTrue($geometry->snap(125.0, 15.0)->equals(new SpatialPoint(130, 20)));
        $this->assertTrue($geometry->isOnGrid(new SpatialPoint(120, -90)));
        $this->assertFalse($geometry->isOnGrid(new SpatialPoint(125, -90)));
    }

    public function testAPointIsRefusedOffTheGridInTheStarOrOutsideTheSystem(): void
    {
        $geometry = $this->geometry();

        $this->assertSame('point_off_grid', $geometry->refusalOf(new SpatialPoint(5, 5)));
        $this->assertSame('point_in_star', $geometry->refusalOf(new SpatialPoint(0, 10)));
        $this->assertSame('point_in_star', $geometry->refusalOf(new SpatialPoint(40, 40)));
        $this->assertSame('point_outside_system', $geometry->refusalOf(new SpatialPoint(1800, 100)));
        $this->assertNull($geometry->refusalOf(new SpatialPoint(0, 1800)));
        $this->assertNull($geometry->refusalOf(new SpatialPoint(60, 0)));
        $this->assertTrue($geometry->isValid(new SpatialPoint(-1480, 190)));
    }

    public function testThePositionAlongALegIsLinearAndOnTheGrid(): void
    {
        $geometry = $this->geometry();
        $from = new SpatialPoint(0, 0);
        $to = new SpatialPoint(300, 0);

        $this->assertTrue($geometry->along($from, $to, 0.0)->equals($from));
        $this->assertTrue($geometry->along($from, $to, 1.0)->equals($to));
        $this->assertTrue($geometry->along($from, $to, 0.5)->equals(new SpatialPoint(150, 0)));
        // 44 % de 300 = 132, arrondi a la grille : 130.
        $this->assertTrue($geometry->along($from, $to, 0.44)->equals(new SpatialPoint(130, 0)));
        // Une fraction hors de [0 ; 1] est ramenee aux bouts.
        $this->assertTrue($geometry->along($from, $to, 1.7)->equals($to));
        $this->assertTrue($geometry->along($from, $to, -0.2)->equals($from));
    }

    public function testTheOrbitIndexIsBoundedToTheSixteenPositions(): void
    {
        $geometry = $this->geometry();

        $this->assertSame(1, $geometry->orbitIndexOf(new SpatialPoint(0, 0)));
        $this->assertSame(1, $geometry->orbitIndexOf(new SpatialPoint(60, 0)));
        $this->assertSame(16, $geometry->orbitIndexOf(new SpatialPoint(0, 1800)));
        $this->assertSame(10, $geometry->orbitIndexOf(new SpatialPoint(0, 1040)));
    }

    /**
     * Le point ou une patrouille se pose pres d une orbite est toujours valide.
     *
     * **Les deux moities du contrat doivent se rejoindre.** `bodyPoint()` rend l adresse exacte d un
     * corps, a son angle de base : aucune des seize ne tombe sur la grille des points libres, et
     * c est voulu. Sans `stationingPointNear()`, un ordre parfaitement legitime — « pose-toi pres de
     * cette planete » — aurait derive l adresse du corps puis vu la validation du serveur la refuser
     * en « hors grille ».
     */
    public function testTheStationingPointNearABodyIsAlwaysValid(): void
    {
        $geometry = $this->geometry();

        for ($position = 1; $position <= 16; $position++) {
            $adresse = $geometry->bodyPoint($position);
            $stationnement = $geometry->stationingPointNear($position);

            $this->assertNull(
                $geometry->refusalOf($stationnement),
                "The stationing point near orbit {$position} is refused by the server itself."
            );
            $this->assertSame(
                $position,
                $geometry->orbitIndexOf($stationnement),
                "Rounding moved the stationing point near orbit {$position} onto another orbit."
            );
            // Il reste le voisinage du corps : jamais plus d une demi-maille sur chaque axe.
            $this->assertLessThanOrEqual(
                $geometry->gridUnits(),
                $geometry->euclid($adresse, $stationnement),
                "The stationing point near orbit {$position} drifted away from the body."
            );
        }

        // Et l adresse du corps, elle, ne bouge pas : c est ce que la carte dessine.
        $this->assertTrue($geometry->bodyPoint(2)->equals(new SpatialPoint(-199, -17)));
        $this->assertTrue($geometry->stationingPointNear(2)->equals(new SpatialPoint(-200, -20)));
    }

    public function testTheGeometryRefusesDegenerateBounds(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SystemGeometry(0, 1800, 60, 3);
    }

    /**
     * Une geometrie ou aucun point n est valide se refuse a la construction.
     *
     * L exclusion et le rayon viennent de deux reglages independants de l administration. Une
     * exclusion plus large que le systeme ne laisse aucun anneau : chaque point serait refuse, et le
     * motif porterait sur le point au lieu de nommer la cause.
     */
    public function testAGeometryThatLeavesNoValidRingIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SystemGeometry(10, 1800, 1800, 3);
    }

    public function testTheGeometryReadsItsBoundsFromTheSettings(): void
    {
        $this->settingsService->set('patrol_grid_units', 25);
        $this->settingsService->set('patrol_internal_distance_divisor', 5);

        try {
            $geometry = SystemGeometry::fromSettings($this->settingsService);

            $this->assertSame(25, $geometry->gridUnits());
            $this->assertSame(5, $geometry->distanceDivisor());
            $this->assertSame(1800, $geometry->systemRadiusUnits());
            $this->assertSame(60, $geometry->starExclusionUnits());
            $this->assertSame(200, $geometry->gameDistanceWithinSystem(new SpatialPoint(0, 500), new SpatialPoint(1000, 500)));
        } finally {
            $this->settingsService->set('patrol_grid_units', 10);
            $this->settingsService->set('patrol_internal_distance_divisor', 3);
        }
    }
}
