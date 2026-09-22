<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Lifeforms\Services\LifeformInstallationService;
use OGame\Lifeforms\Species;
use OGame\Models\Lifeforms\LifeformAccount;
use OGame\Models\Lifeforms\LifeformBuildingLevel;
use OGame\Models\Lifeforms\LifeformPlanet;
use OGame\Models\Lifeforms\LifeformQueue;
use OGame\Models\Lifeforms\LifeformSlot;
use OGame\Models\Lifeforms\LifeformSlotChange;
use OGame\Models\Lifeforms\LifeformSpeciesProgress;
use OGame\Models\Lifeforms\LifeformTechnologyLevel;
use OGame\Models\Planet;
use Tests\AccountTestCase;
use Tests\Support\PinsSettings;

/**
 * La population et la nourriture du bandeau : projetees jusqu'a maintenant, jamais persistees, jamais comptees deux fois.
 *
 * ## Ce qui etait faux
 *
 * `/ajax/resourcebox` rendait la colonne `lifeform_planets` telle quelle : les deux tuiles affichaient l'etat du
 * dernier chargement de page, et une resynchronisation toutes les trente secondes reecrivait la meme valeur
 * (journal §185.4, mesure : gain 0 en 20 s la ou la charge impliquait 11 808).
 *
 * ## Ce que ces temoins etablissent
 *
 * 1. Entre deux lectures, la population et la nourriture **avancent** — et la ligne en base **ne bouge pas** :
 *    ni `population`, ni `food`, ni `calculated_at`. La route reste une lecture.
 * 2. Plusieurs lectures puis une navigation : l'etat que la navigation **persiste** est exactement celui que la
 *    derniere lecture avait **projete** au meme instant. Si les lectures avaient avance l'horloge, la navigation
 *    aurait rejoue moins ; si elles avaient persiste, elle aurait rejoue deux fois. L'egalite refute les deux.
 * 3. Au plafond d'espace vital, le taux publie vaut zero : le navigateur n'a rien a animer, et ne l'invente pas.
 */
class ResourceBarLifeformsTest extends AccountTestCase
{
    use PinsSettings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pinSettings(['lifeforms_enabled' => 1, 'economy_speed' => 8]);
        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Humans, (int)Date::now()->timestamp);

        // **Un essai etablit ce qu'il exige** : une population sous l'espace vital (le choix d'espece la pose AU
        // plafond de base, 210 pour 210, donc rien ne croitrait), de la nourriture en reserve, et une horloge a
        // maintenant — pour que la croissance soit possible pendant la fenetre mesuree.
        LifeformPlanet::query()->where('planet_id', $this->planetService->getPlanetId())->update([
            'population' => 100,
            'food' => 100000,
            'calculated_at' => (int)Date::now()->timestamp,
        ]);
    }

    protected function tearDown(): void
    {
        $planetes = Planet::query()->where('user_id', $this->currentUserId)->pluck('id');
        LifeformQueue::query()->whereIn('planet_id', $planetes)->delete();
        LifeformSlot::query()->whereIn('planet_id', $planetes)->delete();
        LifeformSlotChange::query()->whereIn('planet_id', $planetes)->delete();
        LifeformTechnologyLevel::query()->whereIn('planet_id', $planetes)->delete();
        LifeformBuildingLevel::query()->whereIn('planet_id', $planetes)->delete();
        LifeformPlanet::query()->whereIn('planet_id', $planetes)->delete();
        LifeformAccount::query()->where('user_id', $this->currentUserId)->delete();
        LifeformSpeciesProgress::query()->where('user_id', $this->currentUserId)->delete();
        $this->restorePinnedSettings();
        parent::tearDown();
    }

    public function testPopulationAndFoodAdvanceBetweenTwoReadsWhileTheStoredRowStaysUntouched(): void
    {
        $ligneAvant = $this->storedRow();
        $avant = $this->getJson('/ajax/resourcebox')->assertStatus(200)->json();

        $this->assertArrayHasKey('population', $avant['resources'], 'The account has a species: the bar should carry population.');
        $this->assertGreaterThan(0, $avant['facts']['population']['per_second'], 'Nothing would grow: the bench needs a growing planet to prove anything.');

        $this->travel(30)->minutes();
        $apres = $this->getJson('/ajax/resourcebox')->assertStatus(200)->json();

        $this->assertGreaterThan($avant['resources']['population']['amount'], $apres['resources']['population']['amount'], 'The population did not advance between two reads: the bar shows the state of the last page load.');
        $this->assertNotSame($avant['resources']['food']['amount'], $apres['resources']['food']['amount'], 'The food did not move between two reads.');

        $this->assertSame($ligneAvant, $this->storedRow(), 'A read wrote the stored demographic state: the route is no longer a read, and the next page load would count the period twice.');
    }

    public function testSeveralReadsThenANavigationCountThePeriodOnce(): void
    {
        $this->travel(10)->minutes();
        $this->getJson('/ajax/resourcebox')->assertStatus(200);
        $this->travel(10)->minutes();
        $projete = $this->getJson('/ajax/resourcebox')->assertStatus(200)->json();

        $this->assertSame((int)Date::now()->subMinutes(20)->timestamp, $this->storedRow()['calculated_at'], 'The reads moved the stored clock.');

        // La navigation passe par `globalgame`, qui avance et PERSISTE l'horloge jusqu'a maintenant.
        $this->get('/overview')->assertStatus(200);

        $ligne = $this->storedRow();
        $this->assertSame((int)Date::now()->timestamp, $ligne['calculated_at'], 'The page load did not persist the clock at now.');
        $this->assertEqualsWithDelta(
            (float)$projete['resources']['population']['amount'],
            (float)$ligne['population'],
            1.0,
            'What the page load persisted differs from what the read projected at the same instant: the two do not share one calculation, or a period was counted twice or dropped.'
        );
        $this->assertEqualsWithDelta((float)$projete['resources']['food']['amount'], (float)$ligne['food'], 1.0, 'The persisted food differs from the projected food at the same instant.');
    }

    public function testAtTheLivingSpaceCapThePublishedRateIsZeroSoNothingIsAnimated(): void
    {
        $charge = $this->getJson('/ajax/resourcebox')->assertStatus(200)->json();
        $plafond = (int)$charge['facts']['population']['cap'];

        LifeformPlanet::query()->where('planet_id', $this->planetService->getPlanetId())->update([
            'population' => $plafond,
            'calculated_at' => (int)Date::now()->timestamp,
        ]);

        $pleine = $this->getJson('/ajax/resourcebox')->assertStatus(200)->json();

        $this->assertSame(0.0, (float)$pleine['facts']['population']['per_second'], 'At the cap the growth rate must be zero: the browser would otherwise animate a growth the server never grants.');
        $this->assertTrue($pleine['facts']['population']['full']);
        $this->assertSame($plafond, (int)$pleine['resources']['population']['amount']);
    }

    /**
     * @return array{population: float, food: float, calculated_at: int}
     */
    private function storedRow(): array
    {
        $ligne = DB::table('lifeform_planets')->where('planet_id', $this->planetService->getPlanetId())->first();
        $this->assertNotNull($ligne);

        return ['population' => (float)$ligne->population, 'food' => (float)$ligne->food, 'calculated_at' => (int)$ligne->calculated_at];
    }
}
