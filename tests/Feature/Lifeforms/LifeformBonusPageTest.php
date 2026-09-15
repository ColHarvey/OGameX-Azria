<?php

namespace Tests\Feature\Lifeforms;

use Illuminate\Support\Facades\Date;
use OGame\Lifeforms\Bonuses\LifeformBonusCache;
use OGame\Lifeforms\Bonuses\LifeformBonusResolver;
use OGame\Lifeforms\Catalogue\LifeformEffect;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\Presentation\LifeformBonusPage;
use OGame\Lifeforms\Research\LifeformExperience;
use OGame\Lifeforms\Services\LifeformInstallationService;
use OGame\Lifeforms\Services\LifeformLevels;
use OGame\Lifeforms\Species;
use OGame\Models\Lifeforms\LifeformAccount;
use OGame\Models\Lifeforms\LifeformBuildingLevel;
use OGame\Models\Lifeforms\LifeformPlanet;
use OGame\Models\Lifeforms\LifeformSlot;
use OGame\Models\Lifeforms\LifeformSpeciesProgress;
use OGame\Models\Lifeforms\LifeformTechnologyLevel;
use OGame\Models\Planet;
use OGame\Services\PlayerService;
use Tests\AccountTestCase;
use Tests\Support\PinsSettings;

/**
 * La page des bonus (tranche 7) : l experience par espece au bareme officiel, puis chaque effet avec le
 * detail qui le compose, planete par planete — et **les memes nombres que ceux qui s appliquent**.
 */
final class LifeformBonusPageTest extends AccountTestCase
{
    use PinsSettings;

    private const int VOLCANIC_BATTERIES = 12201;

    private const int GEOTHERMAL_POWER_PLANTS = 12206;

    private const int PSIONIC_NETWORK = 14203;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pinSettings(['lifeforms_enabled' => 1, 'economy_speed' => 1]);
        LifeformBonusCache::invalidate();
    }

    protected function tearDown(): void
    {
        $planetes = Planet::query()->where('user_id', $this->currentUserId)->pluck('id');
        LifeformSlot::query()->whereIn('planet_id', $planetes)->delete();
        LifeformTechnologyLevel::query()->whereIn('planet_id', $planetes)->delete();
        LifeformBuildingLevel::query()->whereIn('planet_id', $planetes)->delete();
        LifeformPlanet::query()->whereIn('planet_id', $planetes)->delete();
        LifeformAccount::query()->where('user_id', $this->currentUserId)->delete();
        LifeformSpeciesProgress::query()->where('user_id', $this->currentUserId)->delete();
        LifeformBonusCache::invalidate();
        $this->restorePinnedSettings();
        parent::tearDown();
    }

    public function testTheExperienceBlockFollowsTheOfficialScale(): void
    {
        $page = resolve(LifeformBonusPage::class);
        $joueur = $this->player();

        $quatre = $page->experienceOf($joueur);
        $this->assertCount(4, $quatre, 'Les quatre especes sont montrees, decouvertes ou non.');
        $this->assertSame(0, $quatre[0]['level']);
        $this->assertSame(0, $quatre[0]['progress']);
        $this->assertSame(900, $quatre[0]['needed'], 'Le premier niveau coute 900 points : page officielle « Level 0: 0/900 XP ».');
        $this->assertSame(0.0, $quatre[0]['bonus']);
        $this->assertFalse($quatre[0]['discovered']);

        // La page reelle : une espece de niveau 4 affichait « 2173/4500 XP » et « Bonus: 0.4% ».
        LifeformSpeciesProgress::query()->create(['user_id' => $this->currentUserId, 'species' => Species::Humans->value, 'experience' => 9000 + 2173, 'discovered_at' => (int)Date::now()->timestamp]);
        $relu = $page->experienceOf($joueur);
        $humains = $relu[0];
        $this->assertSame(Species::Humans, $humains['species']);
        $this->assertSame(4, $humains['level']);
        $this->assertSame(2173, $humains['progress']);
        $this->assertSame(4500, $humains['needed']);
        $this->assertEqualsWithDelta(0.4, $humains['bonus'], 1e-9);
        $this->assertTrue($humains['discovered']);
        $this->assertSame(LifeformExperience::POINTS_PER_STEP, 900);
    }

    public function testEachEffectShowsItsTotalAndTheDetailThatComposesIt(): void
    {
        $page = resolve(LifeformBonusPage::class);
        $resolveur = resolve(LifeformBonusResolver::class);
        $joueur = $this->player();

        $this->assertSame([], $page->effectsOf($joueur), 'Sans forme de vie, la page ne montre aucun effet.');

        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Rocktal, (int)Date::now()->timestamp);
        $seconde = $this->secondPlanet();
        $this->technology($this->currentPlanetId, 1, self::VOLCANIC_BATTERIES, 4);
        $this->technology($seconde, 6, self::GEOTHERMAL_POWER_PLANTS, 6);

        $effets = $page->effectsOf($joueur);
        $this->assertCount(1, $effets, 'Un seul effet porte : la production d energie.');
        $energie = reset($effets);
        $this->assertIsArray($energie);
        $this->assertSame(LifeformEffect::ENERGY_PRODUCTION, $energie['key']);
        $this->assertSame(__('t_lifeforms_ui.effects.energy_production'), $energie['label']);
        $this->assertEqualsWithDelta($resolveur->forPlayer($this->currentUserId)->fraction(LifeformEffect::ENERGY_PRODUCTION) * 100, $energie['total'], 1e-9, 'La page montre exactement ce qui s applique.');
        $this->assertEqualsWithDelta(2.5, $energie['total'], 1e-9, '(4 + 6) niveaux a 0,25 % : 2,5 %.');
        $this->assertFalse($energie['capped']);

        $this->assertCount(2, $energie['planets'], 'Les deux planetes sont detaillees.');
        $this->assertEqualsWithDelta(1.0, $energie['planets'][$this->currentPlanetId]['total'], 1e-9);
        $this->assertEqualsWithDelta(1.5, $energie['planets'][$seconde]['total'], 1e-9);
        $premiere = $energie['planets'][$this->currentPlanetId]['rows'][0];
        $this->assertSame(1, $premiere['slot'], 'L emplacement est celui ou la technologie est placee.');
        $this->assertSame(4, $premiere['level']);
        $this->assertSame(__('t_lifeforms.volcanic_batteries.title'), $premiere['title']);
        $this->assertEqualsWithDelta(1.0, $premiere['percent'], 1e-9);
        $this->assertSame(6, $energie['planets'][$seconde]['rows'][0]['slot']);
    }

    public function testACappedEffectSaysSoAndShowsWhatReallyApplies(): void
    {
        $page = resolve(LifeformBonusPage::class);
        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Kaelesh, (int)Date::now()->timestamp);
        $seconde = $this->secondPlanet();
        // Reseau psionique : 0,05 % par niveau, plafonne a 50 %. Deux fois 700 niveaux : 70 % bruts.
        $this->technology($this->currentPlanetId, 3, self::PSIONIC_NETWORK, 700);
        $this->technology($seconde, 3, self::PSIONIC_NETWORK, 700);

        $effets = $page->effectsOf($this->player());
        $this->assertCount(1, $effets);
        $psionique = reset($effets);
        $this->assertIsArray($psionique);
        $this->assertEqualsWithDelta(50.0, $psionique['total'], 1e-9, 'Le total montre le plafond, pas la somme.');
        $this->assertTrue($psionique['capped'], 'La page dit que le plafond a mordu.');
        $this->assertEqualsWithDelta(35.0, $psionique['planets'][$this->currentPlanetId]['total'], 1e-9, 'Le detail montre ce que chaque planete apporte, avant plafond.');
    }

    public function testThePageIsServedChosenAndClosedWhenTheSwitchIs(): void
    {
        $this->get(route('lifeforms.bonuses'))->assertRedirect(route('lifeforms.index'));

        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Rocktal, (int)Date::now()->timestamp);
        $this->technology($this->currentPlanetId, 1, self::VOLCANIC_BATTERIES, 4);

        $page = $this->get(route('lifeforms.bonuses'));
        $page->assertStatus(200);
        $page->assertSee('id="lifeform-experience-bonuses"', false);
        $page->assertSee('id="lifeform-effect-bonuses"', false);
        $page->assertSee(__('t_lifeforms_ui.effects.energy_production'));
        $page->assertSee(__('t_lifeforms.volcanic_batteries.title'));
        $page->assertSee('1 %');
        $this->assertStringNotContainsString('t_lifeforms_ui.', (string)$page->getContent());

        $this->pinSettings(['lifeforms_enabled' => 0]);
        LifeformBonusCache::invalidate();
        $this->get(route('lifeforms.bonuses'))->assertStatus(404);
        // La page se ferme, mais le detail reste ce qu il est : l interrupteur bloque les ordres, pas l acquis.
        $this->assertCount(1, resolve(LifeformBonusResolver::class)->contributionsOf($this->currentUserId), 'Interrupteur ferme : ce qui est acquis compte toujours.');
    }

    private function secondPlanet(): int
    {
        $planete = $this->createPlanetAtSafeCoordinate($this->currentUserId);
        resolve(LifeformInstallationService::class)->installOnExistingPlanet($planete->getPlanetId(), (int)Date::now()->timestamp);
        LifeformBonusCache::invalidate();

        return $planete->getPlanetId();
    }

    private function technology(int $planetId, int $slot, int $objectId, int $level): void
    {
        LifeformPlanet::query()->where('planet_id', $planetId)->update(['population' => 500000000.0, 'calculated_at' => (int)Date::now()->timestamp + 10 * 86400]);
        LifeformSlot::query()->updateOrCreate(['planet_id' => $planetId, 'slot' => $slot], ['object_id' => $objectId, 'chosen_via' => 'local', 'selected_at' => (int)Date::now()->timestamp]);
        resolve(LifeformLevels::class)->setLevel($planetId, LifeformKind::Technology, $objectId, $level);
    }

    private function player(): PlayerService
    {
        $joueur = $this->planetService->getPlayer();
        $this->assertNotNull($joueur);

        return $joueur;
    }
}
