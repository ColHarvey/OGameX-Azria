<?php

namespace Tests\Feature\Lifeforms;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMessages\EspionageReport as EspionageReportMessage;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Lifeforms\Bonuses\LifeformBonusCache;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\Services\LifeformInstallationService;
use OGame\Lifeforms\Services\LifeformLevels;
use OGame\Lifeforms\Species;
use OGame\Models\EspionageReport;
use OGame\Models\Message;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;
use Tests\Support\PlacesLifeformSlots;

/**
 * Une vraie sonde rapporte la section « formes de vie » (tranche 7) : la mission l ecrit, le rapport la
 * relit, et la phrase est traduite a la lecture.
 */
final class LifeformEspionageDispatchTest extends FleetDispatchTestCase
{
    use PlacesLifeformSlots;

    protected int $missionType = 6;

    protected string $missionName = 'Espionage';

    private const int PLANETARY_SHIELD = 11112;

    protected function basicSetup(): void
    {
        $this->planetAddUnit('espionage_probe', 12);
        $this->planetAddResources(new Resources(0, 0, 100000, 0));
        $reglages = resolve(SettingsService::class);
        $reglages->set('economy_speed', 8);
        $reglages->set('fleet_speed_war', 1);
        $reglages->set('fleet_speed_holding', 1);
        $reglages->set('fleet_speed_peaceful', 1);
        $reglages->set('lifeforms_enabled', '1');
    }

    protected function messageCheckMissionArrival(): void
    {
    }

    protected function messageCheckMissionReturn(): void
    {
    }

    protected function tearDown(): void
    {
        resolve(SettingsService::class)->set('lifeforms_enabled', '0');
        $this->forgetHeldLifeformPlanets();
        LifeformBonusCache::invalidate();
        parent::tearDown();
    }

    public function testAProbeBringsBackTheSpeciesThePopulationAndTheShieldShare(): void
    {
        $this->basicSetup();

        // Douze sondes : au-dela du seuil des batiments, qui est celui de la section (regle Azria).
        $sondes = new UnitCollection();
        $sondes->addUnit(ObjectService::getUnitObjectByMachineName('espionage_probe'), 12);
        $cible = $this->sendMissionToOtherPlayerCleanPlanet($sondes, new Resources(0, 0, 0, 0));

        $proprietaire = (int)DB::table('planets')->where('id', $cible->getPlanetId())->value('user_id');
        $installation = resolve(LifeformInstallationService::class);
        $espece = $installation->speciesOf($proprietaire);
        if ($espece === null) {
            $installation->chooseSpecies($proprietaire, Species::Humans, (int)Date::now()->timestamp);
            $espece = Species::Humans;
        }
        $installation->installOnExistingPlanet($cible->getPlanetId(), (int)Date::now()->timestamp);
        $this->holdLifeformPopulationForLiveRead($cible->getPlanetId(), 54321.9, (int)Date::now()->timestamp);
        if ($espece === Species::Humans) {
            resolve(LifeformLevels::class)->setLevel($cible->getPlanetId(), LifeformKind::Building, self::PLANETARY_SHIELD, 10);
        }
        LifeformBonusCache::invalidate();

        $this->travelTo($cible->getUpdatedAt()->copy()->addHours(10));
        $this->get('/overview')->assertStatus(200);

        $rapport = EspionageReport::query()->where('planet_user_id', $proprietaire)->orderByDesc('id')->first();
        $this->assertNotNull($rapport, 'La sonde n a produit aucun rapport.');
        $this->assertIsArray($rapport->lifeform, 'Douze sondes voient les batiments : la section des formes de vie est la.');
        $this->assertSame($espece->machineName(), $rapport->lifeform['species'], 'Le rapport garde le nom machine, jamais la phrase.');
        $this->assertSame(54321, $rapport->lifeform['population']);
        $this->assertSame($espece === Species::Humans ? 30 : 0, $rapport->lifeform['protected_percent']);

        // Le message le rend, traduit, sans laisser fuir une clef.
        app()->setLocale('fr');
        $message = Message::query()->where('user_id', $this->currentUserId)->where('key', 'espionage_report')->orderByDesc('id')->first();
        $this->assertNotNull($message, 'Le joueur n a pas recu son rapport.');
        $rendu = (new EspionageReportMessage($message, resolve(PlanetServiceFactory::class), resolve(PlayerServiceFactory::class)))->getBodyFull();
        $this->assertStringContainsString(__('t_ingame.messages.spy_lifeform'), $rendu);
        $this->assertStringContainsString(__('t_lifeforms.species.' . $espece->machineName()), $rendu);
        $this->assertStringNotContainsString('t_ingame.messages.spy_lifeform', $rendu);
    }
}
