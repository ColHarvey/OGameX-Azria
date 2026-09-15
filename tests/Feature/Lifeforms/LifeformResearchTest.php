<?php

namespace Tests\Feature\Lifeforms;

use Illuminate\Support\Facades\Date;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\LifeformRefused;
use OGame\Lifeforms\Services\LifeformInstallationService;
use OGame\Lifeforms\Services\LifeformLevels;
use OGame\Lifeforms\Services\LifeformQueueService;
use OGame\Lifeforms\Services\LifeformResearchService;
use OGame\Lifeforms\Species;
use OGame\Models\Lifeforms\LifeformAccount;
use OGame\Models\Lifeforms\LifeformBuildingLevel;
use OGame\Models\Lifeforms\LifeformPlanet;
use OGame\Models\Lifeforms\LifeformQueue;
use OGame\Models\Lifeforms\LifeformSlot;
use OGame\Models\Lifeforms\LifeformSpeciesProgress;
use OGame\Models\Lifeforms\LifeformTechnologyLevel;
use OGame\Models\Planet;
use OGame\Models\Resources;
use Tests\AccountTestCase;
use Tests\Support\PinsSettings;

/**
 * Les emplacements de recherche (tranche 3) : ouverture par la population, choix, recherche par la
 * file, remise a zero et restauration, et la page qui les montre.
 */
final class LifeformResearchTest extends AccountTestCase
{
    use PinsSettings;

    private const int RESEARCH_CENTRE = 11103;

    private const int ENVOYS = 11201;

    private const int EXTRACTORS = 11202;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pinSettings(['lifeforms_enabled' => 1, 'economy_speed' => 8, 'research_speed' => 1]);
        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Humans, (int)Date::now()->timestamp);
        $this->planetAddResources(new Resources(1000000, 1000000, 1000000, 0));
    }

    protected function tearDown(): void
    {
        $planetes = Planet::query()->where('user_id', $this->currentUserId)->pluck('id');
        LifeformQueue::query()->whereIn('planet_id', $planetes)->delete();
        LifeformSlot::query()->whereIn('planet_id', $planetes)->delete();
        LifeformTechnologyLevel::query()->whereIn('planet_id', $planetes)->delete();
        LifeformBuildingLevel::query()->whereIn('planet_id', $planetes)->delete();
        LifeformPlanet::query()->whereIn('planet_id', $planetes)->delete();
        LifeformAccount::query()->where('user_id', $this->currentUserId)->delete();
        LifeformSpeciesProgress::query()->where('user_id', $this->currentUserId)->delete();
        $this->restorePinnedSettings();
        parent::tearDown();
    }

    public function testThePageShowsThreeTiersAndEighteenSlotsLockedByPopulation(): void
    {
        $page = $this->get(route('lifeforms.research'));
        $page->assertStatus(200);
        foreach ([1, 2, 3] as $tier) {
            $page->assertSee('class="tier' . $tier . 'Container"', false);
        }
        for ($slot = 1; $slot <= 18; $slot++) {
            $page->assertSee('data-slot="' . $slot . '"', false);
        }
        $this->assertSame(18, substr_count((string)$page->getContent(), 'research-locked'), 'Sans population, les dix-huit emplacements sont fermes.');
        $page->assertSee(route('lifeforms.research.ajax'), false);
        $page->assertSee('id="productionboxlfresearchcomponent"', false);

        $this->pinSettings(['lifeforms_enabled' => 0]);
        $this->get(route('lifeforms.research'))->assertStatus(404);
    }

    public function testASlotOpensWithThePopulationAndTakesTheLocalTechnologyOnce(): void
    {
        $service = resolve(LifeformResearchService::class);
        $planetId = $this->currentPlanetId;
        $maintenant = (int)Date::now()->timestamp;

        // Fermé : 210 habitants, le premier emplacement en exige 200 000.
        $this->assertRefused(fn () => $service->choose($planetId, $this->currentUserId, 1, 'local', $maintenant), LifeformRefused::SLOT_LOCKED);

        $this->populate(250000.0);
        $page = $this->get(route('lifeforms.research'));
        // L emplacement 1 est ouvert et vide : il porte l identifiant de choix 9001.
        $page->assertSee('data-technology="9001"', false);
        $this->assertSame(17, substr_count((string)$page->getContent(), 'research-locked'));

        $choix = $this->get(route('lifeforms.research.ajax', ['technology' => 9001]));
        $choix->assertStatus(200);
        $this->assertStringContainsString('name="choice" value="local"', (string)$choix->json('content.technologydetails'));

        $this->post(route('lifeforms.research.choose'), ['slot' => 1, 'choice' => 'local'])->assertRedirect(route('lifeforms.research'));
        $emplacement = LifeformSlot::query()->where('planet_id', $planetId)->where('slot', 1)->first();
        $this->assertNotNull($emplacement);
        $this->assertSame(self::ENVOYS, $emplacement->object_id, 'La technologie locale de la position 1 : les Emissaires.');
        $this->assertSame('local', $emplacement->chosen_via);

        $this->assertRefused(fn () => $service->choose($planetId, $this->currentUserId, 1, 'local', $maintenant), LifeformRefused::SLOT_TAKEN);
        $this->assertRefused(fn () => $service->choose($planetId, $this->currentUserId, 2, 'local', $maintenant), LifeformRefused::SLOT_LOCKED, 'Le deuxieme emplacement exige 360 000.');
        $this->assertRefused(fn () => $service->choose($planetId, $this->currentUserId, 1, 'random', $maintenant), LifeformRefused::SLOT_TAKEN);

        $this->populate(400000.0);
        $this->assertRefused(fn () => $service->choose($planetId, $this->currentUserId, 2, 'random', $maintenant), LifeformRefused::NO_DISCOVERED_SPECIES, 'Aucune autre espece decouverte : pas de tirage.');
        $this->assertRefused(fn () => $service->choose($planetId, $this->currentUserId, 2, '12202', $maintenant), LifeformRefused::NO_DISCOVERED_SPECIES);
        $this->assertRefused(fn () => $service->choose($planetId, $this->currentUserId, 2, '11203', $maintenant), LifeformRefused::WRONG_SLOT, 'La position 3 ne va pas dans l emplacement 2.');

        $page = $this->get(route('lifeforms.research'));
        $page->assertSee('data-technology="' . self::ENVOYS . '"', false);
        $page->assertSee('data-technology="9002"', false);
    }

    public function testATechnologyIsResearchedThroughTheQueueOnlyFromAnOpenSlotWithTheCentre(): void
    {
        $planetId = $this->currentPlanetId;
        $maintenant = (int)Date::now()->timestamp;
        $file = resolve(LifeformQueueService::class);
        $niveaux = resolve(LifeformLevels::class);

        // Sans emplacement : refuse, meme avec le centre.
        $niveaux->setLevel($planetId, LifeformKind::Building, self::RESEARCH_CENTRE, 1);
        $this->assertRefused(fn () => $file->add($this->planetService, self::ENVOYS, $maintenant), LifeformRefused::SLOT_LOCKED);

        $this->populate(250000.0);
        resolve(LifeformResearchService::class)->choose($planetId, $this->currentUserId, 1, 'local', $maintenant);

        // Sans centre : refuse.
        $niveaux->setLevel($planetId, LifeformKind::Building, self::RESEARCH_CENTRE, 0);
        $this->assertRefused(fn () => $file->add($this->planetService, self::ENVOYS, $maintenant), LifeformRefused::REQUIREMENTS_UNMET);
        $niveaux->setLevel($planetId, LifeformKind::Building, self::RESEARCH_CENTRE, 1);

        $detail = $this->get(route('lifeforms.research.ajax', ['technology' => self::ENVOYS]));
        $detail->assertStatus(200);
        $html = (string)$detail->json('content.technologydetails');
        $this->assertStringContainsString('data-technology-id="' . self::ENVOYS . '"', $html);
        $this->assertStringContainsString('data-value="4987"', $html, 'Emissaires niveau 1 : 5 000 de metal, moins les 0,25 % du centre de recherche niveau 1.');
        $this->assertStringContainsString('data-effect="discovery_duration_reduction"', $html);
        $this->assertStringContainsString('<button class="upgrade" data-technology="' . self::ENVOYS . '" >', $html);
        $this->get(route('lifeforms.research.ajax', ['technology' => self::EXTRACTORS]))->assertStatus(404);

        $demande = $this->post(route('lifeforms.buildings.addbuildrequest.post'), ['technologyId' => self::ENVOYS, 'mode' => 1, '_token' => csrf_token()]);
        $demande->assertJsonPath('status', 'success');
        $element = LifeformQueue::query()->where('planet_id', $planetId)->where('object_id', self::ENVOYS)->firstOrFail();
        $this->assertSame('running', $element->status);
        $this->assertSame('technology', $element->kind);
        $this->assertSame($maintenant + 147, $element->time_end, 'Niveau 1 a x8 : 1000 × 1,2 ÷ 8 = 150 s, moins les 2 % du centre de recherche niveau 1.');
        $this->assertSame(4987, $element->metal, 'Le prix reduit est celui qui a ete paye.');

        // Pendant la recherche, la remise a zero du palier est refusee.
        $this->assertRefused(fn () => resolve(LifeformResearchService::class)->resetTier($planetId, 1, $maintenant), LifeformRefused::RESEARCH_IN_PROGRESS);

        $this->travelTo(Date::createFromTimestamp($maintenant + 151));
        $this->planetService->update();
        $this->assertSame(1, $niveaux->levelOf($planetId, LifeformKind::Technology, self::ENVOYS));
        $this->assertSame('done', $element->refresh()->status);
    }

    public function testResettingATierKeepsTheLevelsAndCanBeRestoredForAnHourThenNotBeforeADay(): void
    {
        $planetId = $this->currentPlanetId;
        $service = resolve(LifeformResearchService::class);
        $niveaux = resolve(LifeformLevels::class);
        $debut = (int)Date::now()->timestamp;

        $this->populate(400000.0);
        $service->choose($planetId, $this->currentUserId, 1, 'local', $debut);
        $service->choose($planetId, $this->currentUserId, 2, 'local', $debut);
        $niveaux->setLevel($planetId, LifeformKind::Technology, self::ENVOYS, 3);

        $this->assertRefused(fn () => $service->resetTier($planetId, 2, $debut), LifeformRefused::NOTHING_TO_RESET);
        $this->assertRefused(fn () => $service->restoreTier($planetId, 1, $debut), LifeformRefused::RESTORE_EXPIRED, 'Rien n a ete remis a zero.');

        $this->post(route('lifeforms.research.reset'), ['tier' => 1])->assertRedirect(route('lifeforms.research'));
        $emplacements = LifeformSlot::query()->where('planet_id', $planetId)->whereIn('slot', [1, 2])->orderBy('slot')->get();
        $this->assertSame([null, null], $emplacements->pluck('object_id')->all());
        $this->assertSame([self::ENVOYS, self::EXTRACTORS], $emplacements->pluck('previous_object_id')->all());
        $this->assertSame(3, $niveaux->levelOf($planetId, LifeformKind::Technology, self::ENVOYS), 'Le niveau survit a la remise a zero.');

        $page = $this->get(route('lifeforms.research'));
        $page->assertSee('id="restoreTier1"', false);

        $this->travelTo(Date::createFromTimestamp($debut + 1800));
        $this->post(route('lifeforms.research.restore'), ['tier' => 1])->assertRedirect(route('lifeforms.research'));
        $this->assertSame([self::ENVOYS, self::EXTRACTORS], LifeformSlot::query()->where('planet_id', $planetId)->whereIn('slot', [1, 2])->orderBy('slot')->pluck('object_id')->all());

        $this->assertRefused(fn () => $service->resetTier($planetId, 1, $debut + 1800), LifeformRefused::RESET_TOO_SOON, 'Une remise a zero par jour.');

        $this->travelTo(Date::createFromTimestamp($debut + 86401));
        $service->resetTier($planetId, 1, $debut + 86401);
        $this->travelTo(Date::createFromTimestamp($debut + 86401 + 3601));
        $this->assertRefused(fn () => $service->restoreTier($planetId, 1, $debut + 86401 + 3601), LifeformRefused::RESTORE_EXPIRED);
    }

    private function populate(float $population): void
    {
        LifeformPlanet::query()->where('planet_id', $this->currentPlanetId)->update(['population' => $population]);
    }

    private function assertRefused(callable $action, string $raison, string $message = ''): void
    {
        try {
            $action();
            $this->fail("Un refus « $raison » etait attendu. $message");
        } catch (LifeformRefused $refus) {
            $this->assertSame($raison, $refus->reason, $message !== '' ? $message : $refus->getMessage());
        }
    }
}
