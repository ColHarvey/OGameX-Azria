<?php

namespace Tests\Feature\Lifeforms;

use Illuminate\Support\Facades\Date;
use OGame\Lifeforms\Bonuses\LifeformBonusCache;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\Presentation\LifeformEspionage;
use OGame\Lifeforms\Services\LifeformInstallationService;
use OGame\Lifeforms\Services\LifeformLevels;
use OGame\Lifeforms\Species;
use OGame\Models\Lifeforms\LifeformAccount;
use OGame\Models\Lifeforms\LifeformBuildingLevel;
use OGame\Models\Lifeforms\LifeformPlanet;
use OGame\Models\Lifeforms\LifeformSpeciesProgress;
use OGame\Models\Planet;
use Tests\AccountTestCase;
use Tests\Support\PinsSettings;

/**
 * La section « formes de vie » du rapport d espionnage (tranche 7) : des faits a l ecriture, une phrase
 * traduite a la lecture, et « pas vu » distingue de « rien ».
 */
final class LifeformEspionageTest extends AccountTestCase
{
    use PinsSettings;

    private const int PLANETARY_SHIELD = 11112;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pinSettings(['lifeforms_enabled' => 1, 'economy_speed' => 1]);
        LifeformBonusCache::invalidate();
    }

    protected function tearDown(): void
    {
        $planetes = Planet::query()->where('user_id', $this->currentUserId)->pluck('id');
        LifeformBuildingLevel::query()->whereIn('planet_id', $planetes)->delete();
        LifeformPlanet::query()->whereIn('planet_id', $planetes)->delete();
        LifeformAccount::query()->where('user_id', $this->currentUserId)->delete();
        LifeformSpeciesProgress::query()->where('user_id', $this->currentUserId)->delete();
        LifeformBonusCache::invalidate();
        $this->restorePinnedSettings();
        parent::tearDown();
    }

    public function testTheProbeReportsTheSpeciesThePopulationAndTheShieldShare(): void
    {
        $espionnage = resolve(LifeformEspionage::class);

        $vide = $espionnage->factsOf($this->planetService);
        $this->assertSame(['species' => '', 'population' => 0, 'protected_percent' => 0], $vide, 'Un corps sans forme de vie : la section existe et ne nomme aucune espece.');

        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Humans, (int)Date::now()->timestamp);
        LifeformPlanet::query()->where('planet_id', $this->currentPlanetId)->update(['population' => 12345.6, 'calculated_at' => (int)Date::now()->timestamp + 10 * 86400]);
        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Building, self::PLANETARY_SHIELD, 10);
        LifeformBonusCache::invalidate();

        $faits = $espionnage->factsOf($this->planetService);
        $this->assertNotNull($faits, 'L interrupteur est ouvert : la section existe.');
        $this->assertSame('humans', $faits['species'], 'Le nom machine est stocke, jamais la phrase.');
        $this->assertSame(12345, $faits['population'], 'La population est arrondie vers le bas.');
        $this->assertSame(30, $faits['protected_percent'], 'Bouclier planetaire niveau 10 : 30 % proteges.');

        // L interrupteur ferme bloque les ordres, pas la lecture : une population qui vit se voit (journal §155.9).
        $this->pinSettings(['lifeforms_enabled' => 0]);
        LifeformBonusCache::invalidate();
        $ferme = $espionnage->factsOf($this->planetService);
        $this->assertNotNull($ferme);
        $this->assertSame('humans', $ferme['species']);
        $this->assertSame(30, $ferme['protected_percent']);
    }

    public function testTheFactsAreTranslatedOnlyWhenReadAndAbsenceIsKept(): void
    {
        $this->assertNull(LifeformEspionage::presented(null), 'La sonde n en a pas assez vu : pas de section.');
        $this->assertNull(LifeformEspionage::presented('abime'), 'Une colonne illisible ne fabrique pas une section.');

        app()->setLocale('fr');
        $lu = LifeformEspionage::presented(['species' => 'rocktal', 'population' => 12345, 'protected_percent' => 30]);
        $this->assertNotNull($lu);
        $this->assertTrue($lu['has_species']);
        $this->assertSame(__('t_lifeforms.species.rocktal'), $lu['species']);
        $this->assertStringNotContainsString('t_lifeforms', $lu['species']);
        $this->assertSame(12345, $lu['population']);
        $this->assertSame(30, $lu['protected_percent']);

        $rien = LifeformEspionage::presented(['species' => '', 'population' => 0, 'protected_percent' => 0]);
        $this->assertNotNull($rien, 'La section existe : elle dit qu il n y a rien.');
        $this->assertFalse($rien['has_species']);
        $this->assertSame('', $rien['species']);
    }
}
