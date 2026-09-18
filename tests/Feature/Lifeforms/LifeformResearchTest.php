<?php

namespace Tests\Feature\Lifeforms;

use Illuminate\Support\Facades\Date;
use OGame\Lifeforms\Bonuses\LifeformBonusResolver;
use OGame\Lifeforms\Catalogue\LifeformCatalogue;
use OGame\Lifeforms\Catalogue\LifeformEffect;
use OGame\Lifeforms\Catalogue\LifeformFormulas;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\LifeformRefused;
use OGame\Lifeforms\Research\LifeformExperience;
use OGame\Lifeforms\Research\LifeformSlotHistory;
use OGame\Lifeforms\Rules\LifeformRuleRevisions;
use OGame\Lifeforms\Services\LifeformInstallationService;
use OGame\Lifeforms\Services\LifeformLevels;
use OGame\Lifeforms\Services\LifeformQueueService;
use OGame\Lifeforms\Services\LifeformQuote;
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
use Tests\AccountTestCase;
use Tests\Support\PinsSettings;
use Tests\Support\PlacesLifeformSlots;

/**
 * Les emplacements de recherche (tranche 3) : ouverture par la population, choix, recherche par la
 * file, remise a zero et restauration, et la page qui les montre.
 */
final class LifeformResearchTest extends AccountTestCase
{
    use PinsSettings;
    use PlacesLifeformSlots;

    private const int RESEARCH_CENTRE = 11103;

    private const int ENVOYS = 11201;

    private const int EXTRACTORS = 11202;

    private const int VOLCANIC_BATTERIES = 12201;

    private const int METROPOLIS = 11111;

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
        LifeformSlotChange::query()->whereIn('planet_id', $planetes)->delete();
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
        // Les deux boites de file, cote a cote, comme la capture officielle des recherches.
        $page->assertSee('id="productionboxlfbuildingcomponent"', false);
        $page->assertSee('<div id="productionboxBottom"', false);

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

        // **La fiche d une recherche en cours porte son annulation en JSON** : une apostrophe du texte francais
        // (« l'amélioration ») fermait la chaine JavaScript ecrite entre apostrophes (releve de Codex, §155.24).
        $fiche = (string)$this->withSession(['locale' => 'fr'])->get(route('lifeforms.research.ajax', ['technology' => self::ENVOYS]))->json('content.technologydetails');
        $this->assertSame(1, preg_match('/onclick="cancelbuilding\(' . self::ENVOYS . ',' . $element->id . ',(&quot;.*?&quot;)\); return false;"/', $fiche, $appel), 'La fiche porte l appel d annulation avec sa question.');
        $question = json_decode(html_entity_decode($appel[1], ENT_QUOTES | ENT_HTML5), true);
        $this->assertIsString($question);
        $this->assertStringContainsString("'", $question, 'Premisse : la question porte une apostrophe.');
        $this->assertSame(__('t_ingame.ajax_object.cancel_expansion_confirm', ['name' => __('t_lifeforms.intergalactic_envoys.title', [], 'fr'), 'level' => 1], 'fr'), $question);

        // Pendant la recherche, la remise a zero du palier est refusee.
        $this->assertRefused(fn () => resolve(LifeformResearchService::class)->resetTier($planetId, 1, $maintenant), LifeformRefused::RESEARCH_IN_PROGRESS);

        $this->travelTo(Date::createFromTimestamp($maintenant + 151));
        $this->planetService->update();
        $this->assertSame(1, $niveaux->levelOf($planetId, LifeformKind::Technology, self::ENVOYS));
        $this->assertSame('done', $element->refresh()->status);
    }

    /**
     * **Une technologie d une autre espece, prise contre des artefacts, se recherche** (relance de Codex,
     * journal §155.18).
     *
     * Le choix d un emplacement autorise les technologies des especes decouvertes, contre des artefacts ;
     * la file refusait ensuite tout objet d une autre espece que la planete. Le joueur payait ses
     * artefacts et ne pouvait pas lancer sa recherche. Et trois lectures prenaient l espece de l objet
     * pour celle de la planete : le centre de recherche exige, les reductions du devis, et le
     * multiplicateur d experience affiche — qui est celui de l espece de LA TECHNOLOGIE, comme le
     * resolveur l applique.
     */
    public function testAForeignTechnologyTakenWithArtifactsIsResearchedWithThePlanetsCentre(): void
    {
        $planetId = $this->currentPlanetId;
        $maintenant = (int)Date::now()->timestamp;
        $niveaux = resolve(LifeformLevels::class);
        $recherche = resolve(LifeformResearchService::class);
        $vitesses = resolve(LifeformRuleRevisions::class)->live();

        $niveaux->setLevel($planetId, LifeformKind::Building, self::RESEARCH_CENTRE, 1);
        // Une population que la planete porte vraiment : posee sans logement ni ferme, elle s effondrerait a la
        // premiere mise a jour et fermerait l emplacement avant la livraison.
        $this->assertGreaterThanOrEqual(250000.0, $this->sustainLifeformPopulation($planetId, Species::Humans, 250000.0, $maintenant));
        // Les Rock tal sont decouverts, avec l experience du niveau 40 (+4 %) ; les Humains n en ont aucune.
        $this->assertSame(40, LifeformExperience::levelOf(738000));
        LifeformSpeciesProgress::query()->updateOrCreate(
            ['user_id' => $this->currentUserId, 'species' => Species::Rocktal->value],
            ['discovered_at' => $maintenant - 100, 'experience' => 738000]
        );
        $this->assertSame(1, LifeformAccount::query()->where('user_id', $this->currentUserId)->update(['artifacts' => 200]));

        $choix = $recherche->choose($planetId, $this->currentUserId, 1, (string)self::VOLCANIC_BATTERIES, $maintenant);
        $this->assertSame('artifacts', $choix->chosen_via);
        $this->assertSame(0, (int)LifeformAccount::query()->where('user_id', $this->currentUserId)->value('artifacts'), 'Les 200 artefacts du palier 1 sont payes.');

        // Sans le centre, la technologie etrangere est refusee — le logement et la ferme ne l ouvrent pas.
        $niveaux->setLevel($planetId, LifeformKind::Building, self::RESEARCH_CENTRE, 0);
        $this->assertRefused(fn () => resolve(LifeformQueueService::class)->add($this->planetService, self::VOLCANIC_BATTERIES, $maintenant), LifeformRefused::REQUIREMENTS_UNMET);
        $niveaux->setLevel($planetId, LifeformKind::Building, self::RESEARCH_CENTRE, 1);
        // La Metropole de la planete (+0,5 % par niveau sur toutes les technologies) compte aussi pour elle.
        $niveaux->setLevel($planetId, LifeformKind::Building, self::METROPOLIS, 8);

        // Le devis lit les reductions du centre de recherche de LA PLANETE, pas de l espece de la technologie.
        $volcanique = LifeformCatalogue::byId(self::VOLCANIC_BATTERIES);
        $devis = LifeformQuote::for($volcanique, 1, $niveaux->buildingLevelsOf($planetId), 0, 0, $vitesses);
        $local = LifeformQuote::for(LifeformCatalogue::byId(self::ENVOYS), 1, $niveaux->buildingLevelsOf($planetId), 0, 0, $vitesses);
        $this->assertGreaterThan(0.0, $local->timeReduction);
        $this->assertSame($local->timeReduction, $devis->timeReduction, 'Le centre de recherche humain reduit aussi une technologie rock tal.');
        $this->assertSame($local->costReduction, $devis->costReduction);

        // La page de detail : recherchable, et le multiplicateur d experience est celui des Rock tal.
        $detail = $this->get(route('lifeforms.research.ajax', ['technology' => self::VOLCANIC_BATTERIES]));
        $detail->assertStatus(200);
        $html = (string)$detail->json('content.technologydetails');
        $this->assertStringNotContainsString(__('t_lifeforms_ui.research.centre_needed'), $html, 'Le centre de la planete est la : aucune raison de refus.');
        $this->assertStringContainsString('<button class="upgrade" data-technology="' . self::VOLCANIC_BATTERIES . '" >', $html, 'Le bouton est actif.');
        $this->assertStringContainsString('>+0.27 %<', $html, 'Batteries volcaniques niveau 1 : 0,25 % × (1 + 4 % d experience rock tal) × (1 + 4 % de Metropole niveau 8) = 0,2704 %.');
        $this->assertStringNotContainsString('>+0.26 %<', $html, 'Sans la Metropole de la planete, on lirait 0,26 %.');
        $this->assertStringNotContainsString('>+0.25 %<', $html, 'Le multiplicateur n est pas celui de l espece de la planete, qui n a aucune experience.');

        // La file : acceptee, demarree, au prix et a la duree reduits par le centre de la planete.
        $demande = $this->post(route('lifeforms.buildings.addbuildrequest.post'), ['technologyId' => self::VOLCANIC_BATTERIES, 'mode' => 1, '_token' => csrf_token()]);
        $demande->assertJsonPath('status', 'success');
        $element = LifeformQueue::query()->where('planet_id', $planetId)->where('object_id', self::VOLCANIC_BATTERIES)->firstOrFail();
        $this->assertSame('running', $element->status);
        $this->assertSame($maintenant + $devis->duration, (int)$element->time_end);
        $this->assertSame((int)$devis->price->metal->get(), (int)$element->metal);

        // Livree, elle compte pour le compte, multipliee par l experience rock tal.
        $this->travelTo(Date::createFromTimestamp((int)$element->time_end + 1));
        $this->planetService->update();
        $this->assertSame(1, $niveaux->levelOf($planetId, LifeformKind::Technology, self::VOLCANIC_BATTERIES));
        $attendu = LifeformFormulas::technologyBonusPercent($volcanique->bonuses[0], 1, 1.04 * 1.04 - 1) / 100;
        $this->assertEqualsWithDelta($attendu, resolve(LifeformBonusResolver::class)->forPlayer($this->currentUserId)->fraction(LifeformEffect::ENERGY_PRODUCTION), 1e-9);
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

    /**
     * **L occupation d un emplacement se relit a n importe quel instant** (revue de Codex, journal §155.10).
     *
     * Le gel d un combat en depend : un travailleur traite une arrivee bien apres l avoir datee, et ce que
     * le joueur change entre les deux ne doit ni armer ni desarmer cette flotte. Les colonnes de
     * `lifeform_slots` ne suffisaient pas — apres une remise a zero suivie d un nouveau choix, l instant du
     * choix de l ancienne technologie est perdu. C est le dernier cas ci-dessous, et il tombe en une minute
     * de jeu reel.
     */
    public function testTheSlotOccupancyIsReadableAtAnyInstant(): void
    {
        $planetId = $this->currentPlanetId;
        $service = resolve(LifeformResearchService::class);
        $historique = resolve(LifeformSlotHistory::class);
        $debut = (int)Date::now()->timestamp;
        $this->populate(400000.0);

        $this->assertSame([], $historique->occupantsAt($planetId, $debut), 'Rien n a jamais ete pose : rien a lire.');

        $service->choose($planetId, $this->currentUserId, 1, 'local', $debut);
        $this->assertSame([], $historique->occupantsAt($planetId, $debut - 1), 'Avant le choix, l emplacement etait vide.');
        $this->assertSame([1 => self::ENVOYS], $historique->occupantsAt($planetId, $debut), 'A la seconde du choix, il porte la technologie.');
        $this->assertSame([1 => self::ENVOYS], $historique->occupantsAt($planetId, $debut + 10000));

        // **La remise a zero ne remonte pas le temps** : avant elle, l emplacement portait encore.
        $remise = $debut + 100;
        $service->resetTier($planetId, 1, $remise);
        $this->assertSame([1 => self::ENVOYS], $historique->occupantsAt($planetId, $remise - 1), 'La remise a zero a efface le passe.');
        $this->assertSame([], $historique->occupantsAt($planetId, $remise), 'A la seconde de la remise a zero, l emplacement est vide.');

        // **La restauration non plus** : entre les deux, l emplacement etait bien vide.
        $restauration = $remise + 60;
        $service->restoreTier($planetId, 1, $restauration);
        $this->assertSame([1 => self::ENVOYS], $historique->occupantsAt($planetId, $remise - 1));
        $this->assertSame([], $historique->occupantsAt($planetId, $restauration - 1), 'La restauration a rempli une periode ou l emplacement etait vide.');
        $this->assertSame([1 => self::ENVOYS], $historique->occupantsAt($planetId, $restauration));

        // **Le cas que les colonnes ne savaient pas dire** : remise a zero puis choix d une autre technologie.
        // `selected_at` decrit alors la nouvelle, et l instant du choix de l ancienne n existe plus nulle part.
        $secondeRemise = $remise + 86401;
        $this->travelTo(Date::createFromTimestamp($secondeRemise));
        $service->resetTier($planetId, 1, $secondeRemise);
        $rechoix = $secondeRemise + 30;
        $service->choose($planetId, $this->currentUserId, 2, 'local', $rechoix);

        $this->assertSame([1 => self::ENVOYS], $historique->occupantsAt($planetId, $restauration), 'Le rechoix a efface ce que l emplacement 1 portait avant.');
        $this->assertSame([], $historique->occupantsAt($planetId, $secondeRemise));
        $this->assertSame([2 => self::EXTRACTORS], $historique->occupantsAt($planetId, $rechoix));
        $this->assertSame((int)LifeformSlot::query()->where('planet_id', $planetId)->where('slot', 2)->value('selected_at'), $rechoix, 'Premisse : la colonne ne decrit plus que la nouvelle.');

        // Rejouer exactement la meme decision au meme instant n ajoute pas de ligne.
        $lignes = LifeformSlotChange::query()->where('planet_id', $planetId)->count();
        $historique->record($planetId, 2, self::EXTRACTORS, $rechoix);
        $this->assertSame($lignes, LifeformSlotChange::query()->where('planet_id', $planetId)->count());

        // **Deux decisions differentes a la meme seconde** : la seconde gagne, et la premiere ne l efface pas.
        // Vider un palier puis rechoisir dans la foulee tient dans une seconde ; garder la premiere ligne
        // laisserait l emplacement vide pour toujours.
        $meme = $secondeRemise + 86401;
        $this->travelTo(Date::createFromTimestamp($meme));
        $service->resetTier($planetId, 1, $meme);
        $service->choose($planetId, $this->currentUserId, 1, 'local', $meme);
        $this->assertSame([2 => self::EXTRACTORS], $historique->occupantsAt($planetId, $meme - 1), 'Une seconde avant, le palier etait encore celui d avant.');
        $this->assertSame([1 => self::ENVOYS], $historique->occupantsAt($planetId, $meme), 'Un choix fait a la seconde de la remise a zero est perdu.');
    }

    /**
     * **La page des recherches prend les icones, les boutons et les variables que la feuille et le bundle du jeu
     * attendent** (controle a l ecran, journal §155.23) :
     *
     * - un emplacement libre porte `research-allowed` (centre ouvert) ou `research-disallowed` (centre ferme,
     *   vacances), jamais le cadenas `research-locked` reserve aux emplacements que la population ferme, et jamais
     *   une image de cadenas en ligne ;
     * - les remises a zero sont le gros bouton vert du jeu (`a.build-it`), gris quand rien n est a remettre
     *   (`a.build-it_disabled`), pas un `button.btn_blue` ;
     * - les deux variables que le bundle lit sans garde au clic de la fleche verte sont declarees ;
     * - le panneau d une technologie porte sa bande de description en enfant direct, la note d empire dedans, et
     *   un titre d une seule ligne ; le choix d un emplacement prend la couche `lfresearchlayer` et ses boutons
     *   `a.select-button`, et degage les 30 px du titre absolu.
     */
    public function testTheResearchPageTakesTheIconsAndButtonsTheStylesheetGives(): void
    {
        $niveaux = resolve(LifeformLevels::class);
        $this->populate(250000.0);

        $sansCentre = $this->get(route('lifeforms.research'));
        $sansCentre->assertStatus(200);
        $html = (string)$sansCentre->getContent();
        $this->assertSame(1, substr_count($html, 'research-disallowed'), 'Centre ferme : l emplacement ouvert et vide le dit.');
        $this->assertSame(0, substr_count($html, 'research-allowed'));
        $this->assertSame(17, substr_count($html, 'research-locked'), 'Les dix-sept autres restent fermes par la population.');
        $this->assertStringNotContainsString('5395f4191666be14fe8d735eea568a.png', $html, 'Le cadenas vient de la feuille, jamais d un style en ligne.');
        $sansCentre->assertSee('var planetMoveInProgress = false;', false);
        LifeformPagesTest::assertScriptsCarryNoHtmlEntity($html);
        $sansCentre->assertSee('var lastBuildingSlot = {', false);
        $sansCentre->assertDontSee('class="btn_blue"', false);
        $sansCentre->assertSee('<a id="resetTier1" class="build-it_disabled tooltip"', false);

        $niveaux->setLevel($this->currentPlanetId, LifeformKind::Building, self::RESEARCH_CENTRE, 1);
        $avecCentre = $this->get(route('lifeforms.research'));
        $html = (string)$avecCentre->getContent();
        $this->assertSame(1, substr_count($html, 'research-allowed'), 'Centre ouvert : l emplacement libre invite a choisir.');
        $this->assertSame(0, substr_count($html, 'research-disallowed'));

        // Le choix d un emplacement : la couche officielle et ses boutons verts.
        $choix = (string)$this->get(route('lifeforms.research.ajax', ['technology' => 9001]))->json('content.technologydetails');
        $this->assertStringContainsString('class="lifeform-slot-choice lfresearchlayer"', $choix);
        $this->assertStringContainsString('<a class="select-button"', $choix);
        $this->assertStringNotContainsString('btn_blue', $choix);
        $this->assertStringContainsString('padding: 30px 8px 8px 8px;', $choix, 'Le contenu degage les 26 px du titre absolu.');

        // Une technologie placee : la remise a zero devient possible, en vert.
        resolve(LifeformResearchService::class)->choose($this->currentPlanetId, $this->currentUserId, 1, 'local', (int)Date::now()->timestamp);
        $this->get(route('lifeforms.research'))->assertSee('<a class="build-it" id="resetTier1"', false);

        // Son panneau : la bande de description en enfant direct, la note d empire dedans, un titre sur une ligne.
        $panneau = (string)$this->get(route('lifeforms.research.ajax', ['technology' => self::ENVOYS]))->json('content.technologydetails');
        $this->assertSame('technologydetails', LifeformPagesTest::parentIdOfTheDescriptionBand($panneau));
        $this->assertStringContainsString(e(__('t_lifeforms_ui.research.global_note')), $panneau);
        $this->assertStringContainsString('var showLifeformBonusCapReached = false;', $panneau, 'Le bouton du panneau lit cette variable ; sans elle, ReferenceError et rien ne part.');
        $this->assertGreaterThan(strpos($panneau, '<div class="description">'), strpos($panneau, e(__('t_lifeforms_ui.research.global_note'))), 'La note vit dans la bande de description.');
        $this->assertSame(1, preg_match('#<h3>' . preg_quote((string)__('t_lifeforms.intergalactic_envoys.title'), '#') . '</h3>#', $panneau), 'Le titre tient sur une ligne, sans l espece.');
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
