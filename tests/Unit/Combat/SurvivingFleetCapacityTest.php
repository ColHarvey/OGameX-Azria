<?php

namespace Tests\Unit\Combat;

use InvalidArgumentException;
use OGame\Combat\Allocation\SurvivingFleetCapacity;
use OGame\GameMissions\BattleEngine\Models\AttackerFleetResult;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Resources;
use PHPUnit\Framework\TestCase;

/**
 * Ce qu'une flotte survivante peut encore emporter, fige en entiers depuis l'issue du moteur.
 */
class SurvivingFleetCapacityTest extends TestCase
{
    /**
     * La cargaison a bord se somme en entiers, composante par composante.
     */
    public function testTheCargoAboardIsSummedInWholeUnits(): void
    {
        $resultat = new AttackerFleetResult(101, 7, new UnitCollection());
        $resultat->survivingCargoCapacity = 10_000;
        $resultat->survivingCargo = new Resources(1_500, 400, 100, 0);

        $capacite = SurvivingFleetCapacity::fromFleetResult($resultat);

        $this->assertSame(101, $capacite->fleetMissionId);
        $this->assertSame(10_000, $capacite->survivingCapacity);
        $this->assertSame(2_000, $capacite->alreadyAboard);
        $this->assertSame(8_000, $capacite->remaining());
    }

    /**
     * La place restante ne descend jamais sous zero.
     *
     * Une cargaison qui depasserait la capacite survivante — soutes pleines, vaisseaux perdus —
     * laisse zero place, pas une place negative qui ferait retirer du butin.
     */
    public function testRemainingRoomNeverGoesBelowZero(): void
    {
        $capacite = SurvivingFleetCapacity::of(101, 1_000, 1_500);

        $this->assertSame(0, $capacite->remaining());
    }

    /**
     * Une flotte sans identifiant persiste ne peut recevoir aucune part.
     */
    public function testAFleetWithoutAPersistedIdentifierIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SurvivingFleetCapacity::of(0, 1_000, 0);
    }

    /**
     * Une capacite negative n'a pas de sens.
     */
    public function testANegativeCapacityIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SurvivingFleetCapacity::of(101, -1, 0);
    }
}
