<?php

namespace Tests\Feature\Lifeforms;

use Illuminate\Support\Facades\Date;
use OGame\Factories\PlanetServiceFactory;
use OGame\Lifeforms\LifeformRefused;
use OGame\Lifeforms\Services\LifeformInstallationService;
use OGame\Lifeforms\Species;
use OGame\Models\Lifeforms\LifeformAccount;
use OGame\Models\Lifeforms\LifeformPlanet;
use OGame\Models\Lifeforms\LifeformSpeciesProgress;
use OGame\Models\Planet;
use Tests\AccountTestCase;
use Tests\Support\PinsSettings;

/**
 * Le choix de l espece : une fois par compte, toutes les planetes, aucun cadeau.
 */
final class LifeformInstallationTest extends AccountTestCase
{
    use PinsSettings;

    protected function tearDown(): void
    {
        LifeformPlanet::query()->whereIn('planet_id', Planet::query()->where('user_id', $this->currentUserId)->pluck('id'))->delete();
        LifeformAccount::query()->where('user_id', $this->currentUserId)->delete();
        LifeformSpeciesProgress::query()->where('user_id', $this->currentUserId)->delete();
        $this->restorePinnedSettings();
        parent::tearDown();
    }

    public function testChoosingASpeciesPopulatesEveryPlanetOfTheAccountAtTheBasePopulation(): void
    {
        $this->pinSettings(['lifeforms_enabled' => 1]);
        $maintenant = (int)Date::now()->timestamp;

        $compte = resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Mechas, $maintenant);

        $this->assertSame(Species::Mechas->value, $compte->species);
        $this->assertSame($maintenant, $compte->chosen_at);
        $this->assertSame(0, $compte->artifacts);

        $planetes = Planet::query()->where('user_id', $this->currentUserId)->where('planet_type', 1)->pluck('id')->all();
        $this->assertCount(2, $planetes, 'Le compte du banc a deux planetes.');
        foreach ($planetes as $planetId) {
            $this->assertSame(1, LifeformPlanet::query()->where('planet_id', $planetId)->count(), "Une ligne, exactement, pour la planete $planetId.");
            $etat = LifeformPlanet::query()->where('planet_id', $planetId)->first();
            $this->assertNotNull($etat);
            $this->assertSame(500.0, $etat->population, 'Les Mechas partent de 500.');
            $this->assertSame(0.0, $etat->food);
            $this->assertSame($maintenant, $etat->installed_at);
            $this->assertSame($maintenant, $etat->calculated_at);
            $this->assertSame(Species::Mechas->value, $etat->species);
        }

        $this->assertSame(1, LifeformSpeciesProgress::query()->where('user_id', $this->currentUserId)->count());
        $progres = LifeformSpeciesProgress::query()->where('user_id', $this->currentUserId)->first();
        $this->assertNotNull($progres);
        $this->assertSame(Species::Mechas->value, $progres->species);
        $this->assertSame(0, $progres->experience);
        $this->assertSame(Species::Mechas, resolve(LifeformInstallationService::class)->speciesOf($this->currentUserId));
    }

    public function testASecondChoiceIsRefusedAndChangesNothing(): void
    {
        $this->pinSettings(['lifeforms_enabled' => 1]);
        $service = resolve(LifeformInstallationService::class);
        $service->chooseSpecies($this->currentUserId, Species::Humans, (int)Date::now()->timestamp);

        try {
            $service->chooseSpecies($this->currentUserId, Species::Rocktal, (int)Date::now()->timestamp + 10);
            $this->fail('Le second choix aurait du etre refuse.');
        } catch (LifeformRefused $refus) {
            $this->assertSame(LifeformRefused::ALREADY_CHOSEN, $refus->reason);
        }

        $this->assertSame(Species::Humans, $service->speciesOf($this->currentUserId));
        $this->assertSame(1, LifeformAccount::query()->where('user_id', $this->currentUserId)->count());
        $this->assertSame(1, LifeformSpeciesProgress::query()->where('user_id', $this->currentUserId)->count());
        $this->assertSame(2, LifeformPlanet::query()->whereIn('planet_id', Planet::query()->where('user_id', $this->currentUserId)->pluck('id'))->where('species', Species::Humans->value)->count());
    }

    public function testTheChoiceIsRefusedWhileTheSwitchIsClosedAndNothingIsWritten(): void
    {
        $this->pinSettings(['lifeforms_enabled' => 0]);

        try {
            resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Kaelesh, (int)Date::now()->timestamp);
            $this->fail('Le choix aurait du etre refuse.');
        } catch (LifeformRefused $refus) {
            $this->assertSame(LifeformRefused::CLOSED, $refus->reason);
        }

        $this->assertNull(resolve(LifeformInstallationService::class)->accountOf($this->currentUserId));
        $this->assertSame(0, LifeformPlanet::query()->whereIn('planet_id', Planet::query()->where('user_id', $this->currentUserId)->pluck('id'))->count());
    }

    public function testANewColonyIsPopulatedOnlyWhenTheAccountHasASpeciesAndNeverAMoon(): void
    {
        $this->pinSettings(['lifeforms_enabled' => 1]);
        $joueur = $this->planetService->getPlayer();
        $this->assertNotNull($joueur);
        $fabrique = resolve(PlanetServiceFactory::class);

        // Sans espece : une colonie neuve reste vide.
        $avant = $fabrique->createPlanetAtPosition($joueur, $this->getNearbyEmptyCoordinate(), 'Sans espece');
        $this->assertSame(0, LifeformPlanet::query()->where('planet_id', $avant->getPlanetId())->count());

        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Kaelesh, (int)Date::now()->timestamp);
        $this->assertSame(1, LifeformPlanet::query()->where('planet_id', $avant->getPlanetId())->count(), 'Le choix peuple aussi la colonie deja la.');

        // Avec espece : la colonie suivante est peuplee a la creation, a la population de base.
        $apres = $fabrique->createPlanetAtPosition($joueur, $this->getNearbyEmptyCoordinate(), 'Avec espece');
        $etat = LifeformPlanet::query()->where('planet_id', $apres->getPlanetId())->first();
        $this->assertNotNull($etat);
        $this->assertSame(250.0, $etat->population, 'Les Kaelesh partent de 250.');
        $this->assertSame(Species::Kaelesh->value, $etat->species);

        // Une lune n est jamais peuplee.
        $lune = Planet::factory()->create(['user_id' => $this->currentUserId, 'planet_type' => 3, 'galaxy' => $apres->getPlanetCoordinates()->galaxy, 'system' => $apres->getPlanetCoordinates()->system, 'planet' => $apres->getPlanetCoordinates()->position]);
        resolve(LifeformInstallationService::class)->installOnNewPlanet($lune, (int)Date::now()->timestamp);
        $this->assertSame(0, LifeformPlanet::query()->where('planet_id', $lune->id)->count());
    }
}
