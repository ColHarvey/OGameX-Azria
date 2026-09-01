<?php

namespace Tests\Feature;

use Exception;
use OGame\Factories\GameMissionFactory;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Resources;
use OGame\Services\FleetMissionService;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;

/**
 * Une flotte en voyage paie-t-elle reellement son carburant ?
 *
 * Le calcul etait teste — `testFleetDeuteriumConsumptionCalculation` verifie que la formule
 * rend le bon nombre — mais **rien ne verifiait que ce nombre quitte la planete**. Un
 * calcul juste dont le resultat n'est jamais deduit donnerait un jeu ou les flottes volent
 * gratuitement, sans qu'aucun test ne s'en apercoive.
 *
 * Ce fichier mesure les caisses avant et apres le depart, plutot que de relire le code.
 */
class FleetDeuteriumConsumptionTest extends AccountTestCase
{
    /**
     * Assert that the fuel really leaves the planet when a fleet departs.
     */
    public function testTheFuelLeavesThePlanetWhenTheFleetDeparts(): void
    {
        $this->planetAddUnit('small_cargo', 20);
        $this->planetAddResources(new Resources(100000, 100000, 100000, 0));

        $flotte = new UnitCollection();
        $flotte->addUnit(ObjectService::getShipObjectByMachineName('small_cargo'), 20);

        $seconde = $this->secondPlanetService;
        $this->assertNotNull($seconde, 'The test account has no second planet to ship to.');
        $cible = $seconde->getPlanetCoordinates();

        $fret = new Resources(5000, 3000, 1000, 0);

        $carburant = (int)resolve(FleetMissionService::class, ['player' => $this->planetService->getPlayer()])
            ->calculateConsumption($this->planetService, $flotte, $cible, 0, 10);

        $this->assertGreaterThan(0, $carburant, 'The scenario is not conclusive: this trip costs no fuel at all.');

        $avant = [
            'metal' => $this->planetService->metal()->get(),
            'crystal' => $this->planetService->crystal()->get(),
            'deuterium' => $this->planetService->deuterium()->get(),
        ];

        $mission = GameMissionFactory::getMissionById(3, [])
            ->start($this->planetService, $cible, PlanetType::Planet, $flotte, $fret, 10);

        // La planete est rechargee depuis la base : la deduction y est ecrite, et la copie
        // en memoire porterait sinon les valeurs d'avant le depart.
        $rechargee = resolve(PlanetServiceFactory::class)->make($this->planetService->getPlanetId(), true);
        $this->assertNotNull($rechargee);

        $apres = [
            'metal' => $rechargee->metal()->get(),
            'crystal' => $rechargee->crystal()->get(),
            'deuterium' => $rechargee->deuterium()->get(),
        ];

        // Le metal et le cristal ne paient que le fret : s'ils bougeaient aussi, la deduction
        // ne serait pas celle qu'on croit mesurer.
        $this->assertEqualsWithDelta(5000, $avant['metal'] - $apres['metal'], 1, 'The metal deducted is not the cargo.');
        $this->assertEqualsWithDelta(3000, $avant['crystal'] - $apres['crystal'], 1, 'The crystal deducted is not the cargo.');

        // Le deuterium paie le fret ET le carburant.
        $this->assertEqualsWithDelta(
            1000 + $carburant,
            $avant['deuterium'] - $apres['deuterium'],
            1,
            'The fleet did not pay for its fuel: the deuterium removed does not cover the cargo plus the consumption.'
        );

        // Et la consommation est bien celle enregistree sur la mission, pas un autre nombre.
        $this->assertEqualsWithDelta($carburant, $mission->deuterium_consumption, 1, 'The mission records a different consumption than the one charged.');
    }

    /**
     * Assert that a longer trip costs more fuel than a shorter one.
     *
     * Sans cela, une consommation constante — ou nulle — passerait le test precedent tant
     * qu'elle serait deduite. C'est ce qui distingue « le carburant est preleve » de
     * « le carburant est calcule ».
     */
    public function testAFurtherTargetCostsMoreFuel(): void
    {
        $this->planetAddUnit('small_cargo', 20);
        $this->planetAddResources(new Resources(100000, 100000, 100000, 0));

        $flotte = new UnitCollection();
        $flotte->addUnit(ObjectService::getShipObjectByMachineName('small_cargo'), 20);

        // Ces deux reglages retranchent les systemes vides et inactifs de la distance, avec
        // un plancher a 1 : dans un univers de test quasi desert, ils font s'effondrer toutes
        // les distances a la meme valeur. Ils sont poses explicitement — un test anterieur les
        // laisse a 1 — sinon ce test mesurerait l'etat ambiant plutot que la distance.
        $reglages = resolve(SettingsService::class);
        $reglages->set('ignore_empty_systems_on', 0);
        $reglages->set('ignore_inactive_systems_on', 0);

        $service = resolve(FleetMissionService::class, ['player' => $this->planetService->getPlayer()]);

        $proche = clone $this->planetService->getPlanetCoordinates();
        $proche->system += 1;

        $lointain = clone $this->planetService->getPlanetCoordinates();
        $lointain->system += 100;

        $this->assertGreaterThan(
            (int)$service->calculateConsumption($this->planetService, $flotte, $proche, 0, 10),
            (int)$service->calculateConsumption($this->planetService, $flotte, $lointain, 0, 10),
            'A hundred systems further costs no more fuel, so the distance is not taken into account.'
        );
    }

    /**
     * Assert that the fleet is refused when the deuterium cannot cover the fuel.
     *
     * C'est l'autre moitie de la question : si le carburant etait deduit sans etre verifie,
     * une planete pourrait partir avec un solde negatif.
     */
    public function testAFleetWithoutEnoughDeuteriumIsRefused(): void
    {
        $this->planetAddUnit('small_cargo', 20);

        $flotte = new UnitCollection();
        $flotte->addUnit(ObjectService::getShipObjectByMachineName('small_cargo'), 20);

        $seconde = $this->secondPlanetService;
        $this->assertNotNull($seconde, 'The test account has no second planet to ship to.');
        $cible = $seconde->getPlanetCoordinates();

        // Le meme depart doit etre possible tant qu'il reste du deuterium, sinon le refus
        // d'apres ne prouverait rien : il pourrait venir de n'importe quelle autre garde.
        $this->planetAddResources(new Resources(0, 0, 100000, 0));
        GameMissionFactory::getMissionById(3, [])
            ->start($this->planetService, $cible, PlanetType::Planet, $flotte, new Resources(0, 0, 0, 0), 10);

        // Puis on vide les caisses : le voyage coute plus que zero, donc il doit etre refuse.
        $this->planetDeductResources($this->planetService->getResources());

        $this->expectException(Exception::class);

        GameMissionFactory::getMissionById(3, [])
            ->start($this->planetService, $cible, PlanetType::Planet, $flotte, new Resources(0, 0, 0, 0), 10);
    }
}
