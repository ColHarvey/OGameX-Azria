<?php

namespace Tests\Unit\Patrol;

use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Patrol\PatrolUpkeep;
use OGame\Services\ObjectService;
use Tests\UnitTestCase;

/**
 * Ce qu une patrouille brule a rester en place, et jusqu a quand elle tient.
 *
 * Les valeurs epinglees sont calculees une fois et ecrites en dur : les chasseurs legers boivent 20,
 * les croiseurs 300, les vaisseaux de bataille 500 et les grands transporteurs 50 — ce sont les
 * carburants de base des objets du jeu, et un temoin qui les relirait ne prouverait rien.
 */
class PatrolUpkeepTest extends UnitTestCase
{
    private function upkeep(): PatrolUpkeep
    {
        return new PatrolUpkeep($this->settingsService);
    }

    private function fleet(array $composition): UnitCollection
    {
        $units = new UnitCollection();

        foreach ($composition as $nom => $nombre) {
            $units->addUnit(ObjectService::getUnitObjectByMachineName($nom), $nombre);
        }

        return $units;
    }

    public function testTheHourlyRateIsTheHoldingFormulaOfTheGame(): void
    {
        $upkeep = $this->upkeep();

        // 50 x 20 = 1000, divise par 20.
        $this->assertSame(50.0, $upkeep->perHour($this->fleet(['light_fighter' => 50])));
        // 20 x 300 = 6000, divise par 20.
        $this->assertSame(300.0, $upkeep->perHour($this->fleet(['cruiser' => 20])));
        // 100 x 500 + 50 x 50 = 52500, divise par 20.
        $this->assertSame(2625.0, $upkeep->perHour($this->fleet(['battle_ship' => 100, 'large_cargo' => 50])));
        // Une flotte vide ne coute rien, et ne divise rien par zero.
        $this->assertSame(0.0, $upkeep->perHour(new UnitCollection()));
    }

    public function testTheRateFollowsTheServerSetting(): void
    {
        $this->settingsService->set('patrol_upkeep_divisor', 10);

        try {
            $this->assertSame(100.0, $this->upkeep()->perHour($this->fleet(['light_fighter' => 50])));
        } finally {
            $this->settingsService->set('patrol_upkeep_divisor', 20);
        }
    }

    /**
     * La facturation est au prorata de la seconde.
     *
     * **C est la moitie de la regle qui empeche la triche.** A l heure entiere, une patrouille qui
     * repart avant l heure n aurait rien paye, et un ordre toutes les cinquante-neuf minutes offrirait
     * un stationnement gratuit et perpetuel.
     */
    public function testWhatIsDueIsProratedToTheSecond(): void
    {
        $upkeep = $this->upkeep();
        $flotte = $this->fleet(['cruiser' => 20]);

        $this->assertSame(300.0, $upkeep->dueBetween($flotte, 1000, 1000 + 3600));
        $this->assertSame(150.0, $upkeep->dueBetween($flotte, 1000, 1000 + 1800));
        // Une seule seconde est due, et elle n est pas arrondie a zero.
        $this->assertEqualsWithDelta(300 / 3600, $upkeep->dueBetween($flotte, 1000, 1001), 0.000001);
        // Cinquante-neuf minutes ne sont pas gratuites.
        $this->assertEqualsWithDelta(295.0, $upkeep->dueBetween($flotte, 0, 3540), 0.000001);
    }

    public function testAnEmptyOrReversedIntervalOwesNothingAndCreatesNoCredit(): void
    {
        $upkeep = $this->upkeep();
        $flotte = $this->fleet(['cruiser' => 20]);

        $this->assertSame(0.0, $upkeep->dueBetween($flotte, 1000, 1000));
        $this->assertSame(0.0, $upkeep->dueBetween($flotte, 1000, 500));
    }

    public function testAutonomyCountsOnlyWhatIsNotTheReturnFuel(): void
    {
        $upkeep = $this->upkeep();
        $flotte = $this->fleet(['cruiser' => 20]);

        // 20 000 de reserve, 954 mis de cote pour rentrer : 19 046 a bruler a 300 l heure.
        $this->assertSame((int)floor(19046 * 3600 / 300), $upkeep->autonomySeconds($flotte, 20000.0, 954.0));
        // Deja au seuil : plus rien devant.
        $this->assertSame(0, $upkeep->autonomySeconds($flotte, 954.0, 954.0));
        // Sous le seuil : zero, jamais un nombre negatif qui reculerait l echeance.
        $this->assertSame(0, $upkeep->autonomySeconds($flotte, 100.0, 954.0));
    }

    /**
     * Aucun vaisseau capable de voler ne stationne gratuitement — et le cas zero existe quand meme.
     *
     * **Le fait mesure d abord.** Tous les vaisseaux du jeu qui volent portent un carburant de base
     * d au moins un : la sonde d espionnage aussi, malgre sa reputation. Un stationnement de sondes
     * est donc tres bon marche, jamais gratuit, et il a bien un terme. Le seul cas a taux nul est la
     * flotte vide, que le devis refuse par ailleurs ; la garde existe pour qu il n y ait jamais de
     * division par zero derriere elle, pas pour un vaisseau imaginaire.
     */
    public function testEveryFlyableFleetHasAnEndAndOnlyAnEmptyOneHasNone(): void
    {
        $upkeep = $this->upkeep();
        $sondes = $this->fleet(['espionage_probe' => 10]);

        // Dix sondes a un de carburant, divise par vingt.
        $this->assertSame(0.5, $upkeep->perHour($sondes));
        $this->assertSame((int)floor(1000 * 3600 / 0.5), $upkeep->autonomySeconds($sondes, 1000.0, 0.0));
        $this->assertNotSame(PHP_INT_MAX, $upkeep->safetyReturnAt($sondes, 1000.0, 0.0, 500));

        // La flotte vide, et elle seule, n a pas de terme.
        $vide = new UnitCollection();
        $this->assertSame(0.0, $upkeep->perHour($vide));
        $this->assertNull($upkeep->autonomySeconds($vide, 1000.0, 0.0));
        $this->assertSame(PHP_INT_MAX, $upkeep->safetyReturnAt($vide, 1000.0, 0.0, 500));
    }

    public function testTheSafetyReturnLeavesBeforeTheIndispensableFuelIsTouched(): void
    {
        $upkeep = $this->upkeep();
        $flotte = $this->fleet(['cruiser' => 20]);
        $paidAt = 1_700_000_000;

        $instant = $upkeep->safetyReturnAt($flotte, 20000.0, 954.0, $paidAt);

        // A cet instant, ce qui a ete brule laisse exactement le retour, jamais moins.
        $brule = $upkeep->dueBetween($flotte, $paidAt, $instant);
        $this->assertLessThanOrEqual(20000.0 - 954.0, $brule, 'The safety return leaves after the indispensable fuel was touched.');

        // Et une seconde plus tard, le seuil serait franchi : l instant est le dernier possible.
        $unePlusTard = $upkeep->dueBetween($flotte, $paidAt, $instant + 1);
        $this->assertGreaterThan(20000.0 - 954.0, $unePlusTard, 'The safety return leaves earlier than it needs to.');

        // Une reserve deja sous le seuil part maintenant.
        $this->assertSame($paidAt, $upkeep->safetyReturnAt($flotte, 500.0, 954.0, $paidAt));
    }
}
