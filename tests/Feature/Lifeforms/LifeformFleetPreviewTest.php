<?php

namespace Tests\Feature\Lifeforms;

use Illuminate\Support\Facades\Date;
use OGame\Factories\GameMissionFactory;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Lifeforms\Bonuses\LifeformBonusCache;
use OGame\Lifeforms\Catalogue\LifeformEffect;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\Services\LifeformInstallationService;
use OGame\Lifeforms\Services\LifeformLevels;
use OGame\Lifeforms\Species;
use OGame\Models\Lifeforms\LifeformAccount;
use OGame\Models\Lifeforms\LifeformBuildingLevel;
use OGame\Models\Lifeforms\LifeformPlanet;
use OGame\Models\Lifeforms\LifeformSlot;
use OGame\Models\Lifeforms\LifeformSlotChange;
use OGame\Models\Lifeforms\LifeformSpeciesProgress;
use OGame\Models\Lifeforms\LifeformTechnologyLevel;
use OGame\Models\Planet;
use OGame\Services\FleetMissionService;
use OGame\Services\ObjectService;
use Tests\AccountTestCase;
use Tests\Support\PinsSettings;
use Tests\Support\PlacesLifeformSlots;

/**
 * **La page Flotte annonce ce que le jeu debite et ecrit** (audit des effets, journal §157).
 *
 * L apercu du carburant (`/ajax/fleet/dispatch/check-target`, `shipsData[].fuelConsumption`) etait pre-multiplie par
 * la classe seulement : a 15 % de reduction des formes de vie, l ecran annoncait 18 % de plus que le debit. La duree
 * d une expedition etait calculee par le JavaScript sans la Propulsion telekinetique ni les Chercheurs : plus longue
 * que l echeance ecrite. La page porte desormais le multiplicateur d expedition, et le calcul de l apercu reprend la
 * regle du serveur (`FleetMissionService::durationOverDistance` : la duree entiere, constante comprise, divisee).
 */
final class LifeformFleetPreviewTest extends AccountTestCase
{
    use PinsSettings;
    use PlacesLifeformSlots;

    private const int EFFICIENCY_MODULE = 13203;

    private const int TELEKINETIC_DRIVE = 14210;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pinSettings(['lifeforms_enabled' => 1, 'economy_speed' => 1, 'fleet_speed' => 1, 'fleet_speed_peaceful' => 1]);
        LifeformBonusCache::invalidate();
    }

    protected function tearDown(): void
    {
        $planetes = Planet::query()->where('user_id', $this->currentUserId)->pluck('id');
        LifeformSlot::query()->whereIn('planet_id', $planetes)->delete();
        LifeformSlotChange::query()->whereIn('planet_id', $planetes)->delete();
        LifeformTechnologyLevel::query()->whereIn('planet_id', $planetes)->delete();
        LifeformBuildingLevel::query()->whereIn('planet_id', $planetes)->delete();
        LifeformPlanet::query()->whereIn('planet_id', $planetes)->delete();
        LifeformAccount::query()->where('user_id', $this->currentUserId)->delete();
        LifeformSpeciesProgress::query()->where('user_id', $this->currentUserId)->delete();
        LifeformBonusCache::invalidate();
        $this->restorePinnedSettings();
        parent::tearDown();
    }

    public function testTheFuelPreviewCarriesTheLifeformReduction(): void
    {
        $this->planetAddUnit('light_fighter', 100);
        $sans = $this->post('/ajax/fleet/dispatch/check-target', ['galaxy' => 1, 'system' => 1, 'position' => 5, 'type' => 1])->assertStatus(200)->json('shipsData.204.fuelConsumption');
        $this->assertSame(20, (int)$sans, 'Premisse : le chasseur leger consomme 20 sans bonus.');

        // Module d efficacite niveau 500 : 0,03 % par niveau, 15 %.
        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Mechas, (int)Date::now()->timestamp);
        $this->technology(self::EFFICIENCY_MODULE, 500);
        $joueur = $this->planetService->getPlayer();
        $this->assertNotNull($joueur);
        $this->assertEqualsWithDelta(0.15, $joueur->lifeformBonuses()->reduction(LifeformEffect::FUEL_CONSUMPTION_REDUCTION), 1e-9, 'Premisse : 15 %.');

        $avec = $this->post('/ajax/fleet/dispatch/check-target', ['galaxy' => 1, 'system' => 1, 'position' => 5, 'type' => 1])->assertStatus(200)->json('shipsData.204.fuelConsumption');
        $this->assertEqualsWithDelta(17.0, (float)$avec, 1e-9, 'L apercu porte la reduction : 20 × (1 − 0,15).');

        // Et l apercu rejoint le debit : la meme formule que le JavaScript, sur cent chasseurs a 20 000 de distance.
        $flotte = new UnitCollection();
        $flotte->addUnit(ObjectService::getShipObjectByMachineName('light_fighter'), 100);
        $debit = resolve(FleetMissionService::class)->consumptionOverDistance($joueur, $flotte, 20000, 0, 10);
        $apercu = $this->apercuJavaScript(17.0, 100, 20000, 12500);
        $this->assertGreaterThan(100, $debit);
        $this->assertEqualsWithDelta($debit, $apercu, 1.0, 'Cent chasseurs : l apercu et le debit disent la meme chose, a l arrondi pres (le serveur arrondit la somme puis applique la reduction ; l apercu applique la reduction par vaisseau).');
        $this->assertGreaterThan($debit * 1.15, $this->apercuJavaScript(20.0, 100, 20000, 12500), 'Sans la reduction dans l apercu, l ecran annoncait 18 % de plus que le debit.');
    }

    public function testTheExpeditionDurationPreviewCarriesTheTelekineticDrive(): void
    {
        $page = (string)$this->get('/fleet')->assertStatus(200)->getContent();
        $this->assertSame(1, preg_match('/var FLIGHT_SPEED_BONUS_EXPEDITION = ([0-9.]+);/', $page, $m), 'La page Flotte porte le multiplicateur d expedition.');
        $this->assertSame(1.0, (float)($m[1] ?? ''), 'Sans forme de vie ni classe d alliance : 1.');

        // Propulsion telekinetique niveau 100 : 0,1 % par niveau, +10 % de vitesse d expedition.
        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Kaelesh, (int)Date::now()->timestamp);
        $this->technology(self::TELEKINETIC_DRIVE, 100);
        $page = (string)$this->get('/fleet')->assertStatus(200)->getContent();
        $this->assertSame(1, preg_match('/var FLIGHT_SPEED_BONUS_EXPEDITION = ([0-9.]+);/', $page, $m));
        $this->assertEqualsWithDelta(1.10, (float)($m[1] ?? ''), 1e-9, 'Le multiplicateur que le serveur applique a une expedition.');
        $this->assertStringContainsString('FleetDispatcher.prototype.getDuration = function', $page, 'L apercu de duree reprend la regle du serveur pour une expedition.');
        $this->assertStringContainsString('/ faktor / bonus', $page, 'La duree entiere, constante comprise, divisee par le bonus — comme durationOverDistance().');

        // La regle que l apercu recopie est bien celle du serveur : cent chasseurs a 20 000 de distance, a 100 %.
        $joueur = $this->planetService->getPlayer();
        $this->assertNotNull($joueur);
        $flotte = new UnitCollection();
        $flotte->addUnit(ObjectService::getShipObjectByMachineName('light_fighter'), 100);
        $service = resolve(FleetMissionService::class);
        $expedition = GameMissionFactory::getMissionById(15, []);
        $ecrite = $service->durationOverDistance($joueur, $flotte, 20000, $expedition, 10, 1.10);
        $this->assertSame((int)max(round((35000 / 10 * sqrt(20000 * 10 / 12500) + 10) / 1 / 1.10), 1), $ecrite, 'La formule de l apercu, evaluee ici, rend l echeance que le serveur ecrit.');
        $this->assertLessThan($service->durationOverDistance($joueur, $flotte, 20000, $expedition, 10, 1.0), $ecrite);
    }

    /**
     * `FleetHelper.calcConsumption` (e7c74974620fa35b197315ebdbb8c2.js), pour une flotte d un seul type a 100 %.
     */
    private function apercuJavaScript(float $fuelConsumption, int $number, int $distance, int $speed): float
    {
        $duration = max(round((35000 / 10 * sqrt($distance * 10 / $speed) + 10) / 1), 1);
        $speedValue = max(0.5, $duration * 1 - 10);
        $shipSpeedValue = 35000 / $speedValue * sqrt($distance * 10 / $speed);

        return round(max($fuelConsumption * $number * $distance / 35000 * ($shipSpeedValue / 10 + 1) * ($shipSpeedValue / 10 + 1), 1));
    }

    private function technology(int $objectId, int $level): void
    {
        // Dans l emplacement de son indice, palier ouvert par les capacites de l espece ; une population qui ouvre
        // les dix-huit emplacements (448 M au dernier), posee — ces essais ne mesurent pas la demographie.
        $espece = resolve(LifeformInstallationService::class)->speciesOf($this->currentUserId);
        $this->assertNotNull($espece);
        LifeformPlanet::query()->where('planet_id', $this->currentPlanetId)->update(['population' => 500000000.0]);
        $this->placeLifeformTechnology($this->currentPlanetId, $espece, $objectId, (int)Date::now()->timestamp);
        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Technology, $objectId, $level);
        LifeformBonusCache::invalidate();
    }
}
