<?php

namespace Tests\Feature\Lifeforms;

use Illuminate\Support\Facades\Date;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\Demography\DemographicClock;
use OGame\Lifeforms\Demography\DemographicState;
use OGame\Lifeforms\Demography\PlanetLifeformProfile;
use OGame\Lifeforms\Rules\LifeformRuleRevisions;
use OGame\Lifeforms\Services\LifeformInstallationService;
use OGame\Lifeforms\Services\LifeformLevels;
use OGame\Lifeforms\Services\LifeformQueueService;
use OGame\Lifeforms\Species;
use OGame\Models\Lifeforms\LifeformAccount;
use OGame\Models\Lifeforms\LifeformBuildingLevel;
use OGame\Models\Lifeforms\LifeformPlanet;
use OGame\Models\Lifeforms\LifeformQueue;
use OGame\Models\Lifeforms\LifeformRuleRevision;
use OGame\Models\Lifeforms\LifeformSpeciesProgress;
use OGame\Models\Planet;
use OGame\Models\Resources;
use Tests\AccountTestCase;
use Tests\Support\PinsSettings;

/**
 * La mise a jour d une planete peuplee, par `PlanetService::update()` : l horloge coupe aux
 * revisions de vitesse et aux echeances des travaux, et ne fait jamais deux fois la meme chose.
 */
final class LifeformPlanetUpdaterTest extends AccountTestCase
{
    use PinsSettings;

    private const int RESIDENTIAL = 11101;

    private const int FARM = 11102;

    private int $revisionsAvant = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->revisionsAvant = (int)(LifeformRuleRevision::query()->max('id') ?? 0);
        $this->pinSettings(['lifeforms_enabled' => 1, 'economy_speed' => 8, 'research_speed' => 1]);
        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Humans, (int)Date::now()->timestamp);
        $this->planetAddResources(new Resources(100000, 100000, 100000, 0));
    }

    protected function tearDown(): void
    {
        $planetes = Planet::query()->where('user_id', $this->currentUserId)->pluck('id');
        LifeformQueue::query()->whereIn('planet_id', $planetes)->delete();
        LifeformBuildingLevel::query()->whereIn('planet_id', $planetes)->delete();
        LifeformPlanet::query()->whereIn('planet_id', $planetes)->delete();
        LifeformAccount::query()->where('user_id', $this->currentUserId)->delete();
        LifeformSpeciesProgress::query()->where('user_id', $this->currentUserId)->delete();
        LifeformRuleRevision::query()->where('id', '>', $this->revisionsAvant)->delete();
        $this->restorePinnedSettings();
        parent::tearDown();
    }

    public function testGrowthIsIntegratedPieceByPieceAroundASpeedRevision(): void
    {
        $debut = (int)Date::now()->timestamp;
        $niveaux = resolve(LifeformLevels::class);
        $niveaux->setLevel($this->planetService->getPlanetId(), LifeformKind::Building, self::RESIDENTIAL, 5);
        $niveaux->setLevel($this->planetService->getPlanetId(), LifeformKind::Building, self::FARM, 5);
        $revisions = resolve(LifeformRuleRevisions::class);
        $revisions->recordIfChanged($debut, null, 'banc');

        // Une heure plus tard, l economie passe de x8 a x2 ; deux heures plus tard, la page se charge.
        $this->travelTo(Date::createFromTimestamp($debut + 3600));
        $this->pinSettings(['economy_speed' => 2]);
        $this->assertNotNull($revisions->recordIfChanged($debut + 3600, null, 'banc'));
        $this->travelTo(Date::createFromTimestamp($debut + 7200));

        $this->planetService->update();

        $horloge = new DemographicClock();
        $depart = new DemographicState(210.0, 0.0, $debut);
        $levels = [self::RESIDENTIAL => 5, self::FARM => 5];
        $attendu = $horloge->advance($depart, PlanetLifeformProfile::fromLevels(Species::Humans, $levels, 8.0), $debut + 3600);
        $attendu = $horloge->advance($attendu, PlanetLifeformProfile::fromLevels(Species::Humans, $levels, 2.0), $debut + 7200);
        $tout = $horloge->advance($depart, PlanetLifeformProfile::fromLevels(Species::Humans, $levels, 2.0), $debut + 7200);

        $etat = LifeformPlanet::query()->where('planet_id', $this->planetService->getPlanetId())->firstOrFail();
        $this->assertEqualsWithDelta($attendu->population, $etat->population, 1e-6, 'Une heure a x8 puis une heure a x2.');
        $this->assertEqualsWithDelta($attendu->food, $etat->food, 1e-6);
        $this->assertSame($debut + 7200, $etat->calculated_at);
        $this->assertNotEqualsWithDelta($tout->population, $etat->population, 1e-3, 'Appliquer x2 a toute l absence aurait donne autre chose.');

        // Recharger la page ne refait rien.
        $this->planetService->update();
        $this->assertEqualsWithDelta($attendu->population, LifeformPlanet::query()->where('planet_id', $this->planetService->getPlanetId())->value('population'), 1e-6);
    }

    public function testADeliveredFarmChangesTheRatesFromItsDeadlineOn(): void
    {
        $debut = (int)Date::now()->timestamp;
        // Un logement de niveau 2 offre 922 places : sans ferme, les 210 de base ne croissent pas.
        resolve(LifeformLevels::class)->setLevel($this->planetService->getPlanetId(), LifeformKind::Building, self::RESIDENTIAL, 2);
        $element = resolve(LifeformQueueService::class)->add($this->planetService, self::FARM, $debut);
        $this->assertSame($debut + 6, $element->time_end, 'A x8 : 40 × 1,25 ÷ 8 = 6,25 → 6 s.');

        $this->travelTo(Date::createFromTimestamp($debut + 3606));
        $this->planetService->update();

        $horloge = new DemographicClock();
        $sansFerme = $horloge->advance(new DemographicState(210.0, 0.0, $debut), PlanetLifeformProfile::fromLevels(Species::Humans, [self::RESIDENTIAL => 2], 8.0), $debut + 6);
        $this->assertSame(210.0, $sansFerme->population, 'Sans ferme, rien ne croit.');
        $attendu = $horloge->advance($sansFerme, PlanetLifeformProfile::fromLevels(Species::Humans, [self::RESIDENTIAL => 2, self::FARM => 1], 8.0), $debut + 3606);

        $etat = LifeformPlanet::query()->where('planet_id', $this->planetService->getPlanetId())->firstOrFail();
        $this->assertSame('done', $element->refresh()->status);
        $this->assertGreaterThan(210.0, $etat->population, 'La ferme livree a nourri la croissance.');
        $this->assertEqualsWithDelta($attendu->population, $etat->population, 1e-6, 'Six secondes sans ferme, puis une heure avec.');
        $this->assertEqualsWithDelta($attendu->food, $etat->food, 1e-6);
    }

    public function testAnUnpopulatedPlanetIsLeftAlone(): void
    {
        LifeformPlanet::query()->where('planet_id', $this->planetService->getPlanetId())->delete();
        $this->travelTo(Date::now()->addHours(3));
        $this->planetService->update();
        $this->assertSame(0, LifeformPlanet::query()->where('planet_id', $this->planetService->getPlanetId())->count());
    }
}
