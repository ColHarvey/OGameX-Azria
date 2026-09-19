<?php

namespace Tests\Feature\Lifeforms;

use Illuminate\Support\Facades\Date;
use OGame\Lifeforms\Catalogue\LifeformCatalogue;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\Demography\PlanetLifeformProfile;
use OGame\Lifeforms\LifeformRefused;
use OGame\Lifeforms\Rules\LifeformRuleRevisions;
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
use OGame\Models\Lifeforms\LifeformSlotChange;
use OGame\Models\Lifeforms\LifeformSpeciesProgress;
use OGame\Models\Lifeforms\LifeformTechnologyLevel;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Queues\QueueCapacity;
use Tests\AccountTestCase;
use Tests\Support\PinsSettings;

/**
 * **Vignette, fiche et service disent la meme chose** (audit de l affichage, journal §155.27).
 *
 * Les prerequis se jugent avec la file, comme le service les juge ; les raisons de refus suivent un seul ordre ;
 * la page des recherches dit « file pleine » ; une technologie placee dans un emplacement referme reste visible ;
 * un emplacement libre que la recherche refuserait dit pourquoi ; le bouton d une fiche dit « Dans la file » quand un
 * travail court ; le sous-titre de chaque palier explique son nombre.
 */
final class LifeformTileStatesTest extends AccountTestCase
{
    use PinsSettings;

    private const int MEDITATION_ENCLAVE = 12101;

    private const int CRYSTAL_FARM = 12102;

    private const int RUNE_TECHNOLOGIUM = 12103;

    private const int RUNE_FORGE = 12104;

    private const int VOLCANIC_BATTERIES = 12201;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pinSettings(['lifeforms_enabled' => 1, 'economy_speed' => 8, 'research_speed' => 1]);
        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Rocktal, (int)Date::now()->timestamp);
        $this->planetAddResources(new Resources(100000000, 100000000, 100000000, 0));
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

    /**
     * La Forge des runes exige l Enclave au niveau 41 : construite au 40 avec le 41 en file, la vignette et la fiche
     * l offrent, comme le service l accepte — et sans l element en file, elles la refusent.
     */
    public function testThePrerequisitesAreJudgedWithTheQueueLikeTheServiceDoes(): void
    {
        $maintenant = (int)Date::now()->timestamp;
        $planetId = $this->currentPlanetId;
        $file = resolve(LifeformQueueService::class);
        $this->assertSame(41, LifeformCatalogue::byId(self::RUNE_FORGE)->requirements[self::MEDITATION_ENCLAVE], 'Premisse : la Forge exige l Enclave 41.');
        resolve(LifeformLevels::class)->setLevel($planetId, LifeformKind::Building, self::MEDITATION_ENCLAVE, 40);
        // La population que la Forge exige est la : seul le prerequis retient la vignette, sinon l essai ne distingue rien.
        LifeformPlanet::query()->where('planet_id', $planetId)->update(['population' => 50000000.0]);

        $page = (string)$this->get(route('lifeforms.buildings'))->getContent();
        $this->assertSame(1, preg_match('#<li[^>]*data-technology="' . self::RUNE_FORGE . '"[^>]*data-status="off"[^>]*title="[^"]*' . preg_quote(e(__('t_ingame.buildings.requirements_not_met')), '#') . '"#', $page), 'Sans l Enclave 41 : prerequis manquants.');
        $fiche = (string)$this->get(route('lifeforms.buildings.ajax', ['technology' => self::RUNE_FORGE]))->json('content.technologydetails');
        $this->assertSame(1, preg_match('#<button class="upgrade" data-technology="' . self::RUNE_FORGE . '"\s+disabled\s*>#', $fiche), 'Le bouton de la fiche est desactive.');
        $this->assertStringContainsString('<span class="overmark">' . e(__('t_lifeforms.meditation_enclave.title')) . ' 41</span>', $fiche, 'Le prerequis manquant est en rouge.');

        // L Enclave 41 en file : la vignette s allume, la fiche aussi, et la file accepte.
        $file->add($this->planetService, self::MEDITATION_ENCLAVE, $maintenant);
        $this->assertSame(41, $file->buildingLevelsWithQueue($planetId)[self::MEDITATION_ENCLAVE], 'La lecture avec la file compte le niveau cible.');
        $page = (string)$this->get(route('lifeforms.buildings'))->getContent();
        $this->assertSame(1, preg_match('#<li[^>]*data-technology="' . self::RUNE_FORGE . '"[^>]*data-status="on"#', $page), 'Avec l Enclave 41 en file, la Forge s offre.');
        $this->assertSame(1, preg_match('#<button class="upgrade[^"]*"[^>]*data-technology="' . self::RUNE_FORGE . '"#', $page), 'Et sa fleche verte existe.');
        $fiche = (string)$this->get(route('lifeforms.buildings.ajax', ['technology' => self::RUNE_FORGE]))->json('content.technologydetails');
        $this->assertStringContainsString('<button class="upgrade" data-technology="' . self::RUNE_FORGE . '" >', $fiche, 'Le bouton de la fiche est actif.');
        $this->assertStringContainsString('<span class="undermark">' . e(__('t_lifeforms.meditation_enclave.title')) . ' 41</span>', $fiche, 'Le prerequis est en vert.');
        $this->assertStringContainsString(e(__('t_ingame.ajax_object.in_queue')), $fiche, 'Un travail court : le bouton dit « Dans la file ».');
        $element = $file->add($this->planetService, self::RUNE_FORGE, $maintenant);
        $this->assertSame('waiting', $element->status, 'Le service l accepte, derriere l Enclave.');
    }

    /**
     * Ressources manquantes ET file pleine : vignette et fiche donnent la meme raison, celle que le service refuse en
     * premier (la file), sur les deux pages.
     */
    public function testATileAndItsPanelGiveTheSameReasonInTheServicesOrder(): void
    {
        $maintenant = (int)Date::now()->timestamp;
        $planetId = $this->currentPlanetId;
        $file = resolve(LifeformQueueService::class);
        $niveaux = resolve(LifeformLevels::class);

        // Batiments : la Ferme court, cinq Enclaves attendent, et il ne reste rien a payer.
        for ($i = 0; $i < 1 + QueueCapacity::WAITING_BASE; $i++) {
            $file->add($this->planetService, self::MEDITATION_ENCLAVE, $maintenant);
        }
        Planet::query()->whereKey($planetId)->update(['metal' => 0, 'crystal' => 0, 'deuterium' => 0]);
        $this->planetService->reloadPlanet();
        $page = (string)$this->get(route('lifeforms.buildings'))->getContent();
        $this->assertSame(1, preg_match('#<li[^>]*data-technology="' . self::CRYSTAL_FARM . '"[^>]*data-status="disabled"[^>]*title="[^"]*' . preg_quote(e(__('t_ingame.buildings.queue_full', ['nombre' => QueueCapacity::WAITING_BASE])), '#') . '"#', $page), 'La vignette dit : file pleine.');
        $fiche = (string)$this->get(route('lifeforms.buildings.ajax', ['technology' => self::CRYSTAL_FARM]))->json('content.technologydetails');
        $this->assertStringContainsString('title="' . e(__('t_ingame.buildings.queue_full', ['nombre' => QueueCapacity::WAITING_BASE])) . '"', $fiche, 'La fiche aussi.');
        $this->assertStringNotContainsString('title="' . e(__('t_ingame.buildings.not_enough_resources')) . '"', $fiche);
        try {
            $file->add($this->planetService, self::CRYSTAL_FARM, $maintenant);
            $this->fail('La file est pleine.');
        } catch (LifeformRefused $refus) {
            $this->assertSame(LifeformRefused::QUEUE_FULL, $refus->reason, 'Le service refuse pour la meme raison.');
        }

        // Recherches : une technologie placee, le centre ouvert, la file de recherche pleine.
        LifeformQueue::query()->where('planet_id', $planetId)->delete();
        $this->planetAddResources(new Resources(100000000, 100000000, 100000000, 0));
        $niveaux->setLevel($planetId, LifeformKind::Building, self::RUNE_TECHNOLOGIUM, 1);
        LifeformPlanet::query()->where('planet_id', $planetId)->update(['population' => 250000.0]);
        resolve(LifeformResearchService::class)->choose($planetId, $this->currentUserId, 1, 'local', $maintenant);
        for ($i = 0; $i < 1 + QueueCapacity::WAITING_BASE; $i++) {
            $file->add($this->planetService, self::VOLCANIC_BATTERIES, $maintenant);
        }
        $page = (string)$this->get(route('lifeforms.research'))->getContent();
        $this->assertSame(1, preg_match('#<li[^>]*data-slot="1"[^>]*data-technology="' . self::VOLCANIC_BATTERIES . '"[^>]*data-status="active"#', $page), 'Premisse : la premiere est en cours.');
        // Un second emplacement ouvert et pris par une autre technologie dirait « file pleine » ; ici c est la fiche qui le dit.
        $fiche = (string)$this->get(route('lifeforms.research.ajax', ['technology' => self::VOLCANIC_BATTERIES]))->json('content.technologydetails');
        $this->assertStringContainsString(e(__('t_ingame.ajax_object.in_queue')), $fiche, 'Une recherche court : le bouton dit « Dans la file ».');
        $this->assertStringContainsString('title="' . e(__('t_ingame.buildings.queue_full', ['nombre' => QueueCapacity::WAITING_BASE])) . '"', $fiche, 'La fiche dit : file pleine.');
    }

    /**
     * La file de recherche pleine se dit sur la vignette d une technologie placee, et non « pas assez de ressources ».
     */
    public function testAFullResearchQueueIsSaidOnTheTile(): void
    {
        $maintenant = (int)Date::now()->timestamp;
        $planetId = $this->currentPlanetId;
        $file = resolve(LifeformQueueService::class);
        resolve(LifeformLevels::class)->setLevel($planetId, LifeformKind::Building, self::RUNE_TECHNOLOGIUM, 1);
        LifeformPlanet::query()->where('planet_id', $planetId)->update(['population' => 400000.0]);
        $recherche = resolve(LifeformResearchService::class);
        $recherche->choose($planetId, $this->currentUserId, 1, 'local', $maintenant);
        $recherche->choose($planetId, $this->currentUserId, 2, 'local', $maintenant);
        for ($i = 0; $i < 1 + QueueCapacity::WAITING_BASE; $i++) {
            $file->add($this->planetService, self::VOLCANIC_BATTERIES, $maintenant);
        }
        $page = (string)$this->get(route('lifeforms.research'))->getContent();
        $this->assertSame(1, preg_match('#<li[^>]*data-slot="2"[^>]*data-technology="12202"[^>]*data-status="disabled" title="[^"]*' . preg_quote(e(__('t_ingame.buildings.queue_full', ['nombre' => QueueCapacity::WAITING_BASE])), '#') . '"#', $page), 'L emplacement 2 : file pleine, pas « ressources ».');
        $this->assertSame(0, preg_match('#data-slot="2"[^>]*' . preg_quote(e(__('t_ingame.buildings.not_enough_resources')), '#') . '#', $page));
    }

    /**
     * Une technologie placee dont l emplacement s est referme (population retombee) reste visible, eteinte et dite ;
     * la file la refuse encore ; un cadenas la faisait disparaitre.
     */
    public function testATechnologyWhoseSlotClosedAgainStaysVisibleAndExplained(): void
    {
        $maintenant = (int)Date::now()->timestamp;
        $planetId = $this->currentPlanetId;
        resolve(LifeformLevels::class)->setLevel($planetId, LifeformKind::Building, self::RUNE_TECHNOLOGIUM, 1);
        LifeformPlanet::query()->where('planet_id', $planetId)->update(['population' => 250000.0]);
        resolve(LifeformResearchService::class)->choose($planetId, $this->currentUserId, 1, 'local', $maintenant);
        LifeformPlanet::query()->where('planet_id', $planetId)->update(['population' => 1000.0]);

        $page = (string)$this->get(route('lifeforms.research'))->getContent();
        $this->assertSame(1, preg_match('#<li[^>]*data-slot="1"[^>]*data-technology="' . self::VOLCANIC_BATTERIES . '"[^>]*data-status="off" data-relocked="1" title="[^"]*' . preg_quote(e(__('t_lifeforms_ui.research.requires', ['population' => '200,000', 'tier' => 1])), '#') . '"#', $page), 'La technologie reste visible, eteinte, avec le seuil.');
        $this->assertSame(0, preg_match('#<li[^>]*data-slot="1"[^>]*class="[^"]*research-locked#', $page));
        $this->assertSame(17, substr_count($page, 'research-locked'), 'Les dix-sept autres emplacements sont verrouilles.');
        try {
            resolve(LifeformQueueService::class)->add($this->planetService, self::VOLCANIC_BATTERIES, $maintenant);
            $this->fail('L emplacement est referme.');
        } catch (LifeformRefused $refus) {
            $this->assertSame(LifeformRefused::SLOT_LOCKED, $refus->reason);
        }
    }

    /**
     * Un emplacement libre sans centre de recherche : l icone hachuree ET l infobulle qui dit pourquoi ; avec le centre,
     * le cadenas noir sur un fond qui le rend lisible.
     */
    public function testAFreeSlotSaysWhyItIsHatchedAndTheBlackLockReads(): void
    {
        $planetId = $this->currentPlanetId;
        LifeformPlanet::query()->where('planet_id', $planetId)->update(['population' => 250000.0]);

        $sansCentre = (string)$this->get(route('lifeforms.research'))->getContent();
        $this->assertSame(1, preg_match('#<li[^>]*lifeform-slot-empty" data-slot="1"[^>]*title="' . preg_quote(e(__('t_lifeforms_ui.research.slot_empty')), '#') . '<br/>' . preg_quote(e(__('t_lifeforms_ui.research.centre_needed')), '#') . '">#', $sansCentre), 'L infobulle dit pourquoi l emplacement est hachure.');
        $this->assertStringContainsString('research-disallowed" style="display: block;"', $sansCentre, 'L icone hachuree, sans fond ajoute.');

        resolve(LifeformLevels::class)->setLevel($planetId, LifeformKind::Building, self::RUNE_TECHNOLOGIUM, 1);
        $avecCentre = (string)$this->get(route('lifeforms.research'))->getContent();
        $this->assertSame(1, preg_match('#<li[^>]*lifeform-slot-empty" data-slot="1"[^>]*title="' . preg_quote(e(__('t_lifeforms_ui.research.slot_empty')), '#') . '">#', $avecCentre), 'Rien a expliquer : l infobulle s arrete la.');
        $this->assertStringContainsString('research-allowed" style="display: block; background-color: #29313d;"', $avecCentre, 'Le cadenas noir (image officielle) sur le ton de survol de la feuille : lisible.');
    }

    /**
     * Le sous-titre « Population : N » de chaque palier est celui de l officiel ; son infobulle dit ce que N est.
     */
    public function testEachTierSubheadingExplainsItsNumber(): void
    {
        $planetId = $this->currentPlanetId;
        $niveaux = resolve(LifeformLevels::class);
        $niveaux->setLevel($planetId, LifeformKind::Building, self::RUNE_FORGE, 1);
        $profil = PlanetLifeformProfile::fromLevels(Species::Rocktal, $niveaux->buildingLevelsOf($planetId), resolve(LifeformRuleRevisions::class)->live()->demography());
        $this->assertGreaterThan(0.0, $profil->tier2Capacity, 'Premisse : la Forge des runes forme au palier 2.');

        $page = (string)$this->get(route('lifeforms.research'))->getContent();
        $this->assertSame(3, substr_count($page, '<div class="populationSubHeading"><span class="tooltip" title="'), 'Trois paliers, trois sous-titres avec leur infobulle.');
        $this->assertStringContainsString('title="' . e(__('t_lifeforms_ui.research.population_tier1_tooltip')) . '">', $page);
        $this->assertStringContainsString('title="' . e(__('t_lifeforms_ui.research.population_tier_tooltip', ['tier' => 2, 'capacity' => number_format((int)floor($profil->tier2Capacity))])) . '">', $page, 'Le palier 2 nomme son plafond.');
        $this->assertStringContainsString('title="' . e(__('t_lifeforms_ui.research.population_tier_tooltip', ['tier' => 3, 'capacity' => '0'])) . '">', $page, 'Sans Oriktorium, le palier 3 plafonne a zero.');
    }

    public function testTheBonusesPageCarriesNoDuplicatedIdentifier(): void
    {
        $page = (string)$this->get(route('lifeforms.bonuses'))->getContent();
        $this->assertSame(1, substr_count($page, 'id="lifeforms"'), 'Le corps de page seulement.');
        $this->assertStringContainsString('<div id="lfbonuses">', $page);
    }
}
