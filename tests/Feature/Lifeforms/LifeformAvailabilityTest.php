<?php

namespace Tests\Feature\Lifeforms;

use Illuminate\Support\Facades\Date;
use OGame\Lifeforms\Bonuses\LifeformBonusResolver;
use OGame\Lifeforms\Catalogue\LifeformAvailability;
use OGame\Lifeforms\Catalogue\LifeformBonus;
use OGame\Lifeforms\Catalogue\LifeformCatalogue;
use OGame\Lifeforms\Catalogue\LifeformEffect;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\Catalogue\LifeformObject;
use OGame\Lifeforms\LifeformRefused;
use OGame\Lifeforms\Research\LifeformSlotHistory;
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
use Tests\AccountTestCase;
use Tests\Support\PinsSettings;
use Tests\Support\PlacesLifeformSlots;

/**
 * **Un objet dont l effet n est pas applique ne se vend pas et ne promet rien** (releve de Codex, journal §155.26).
 *
 * Deux effets du catalogue attendent une decision de jeu (`LifeformBonusResolver::NOT_YET_APPLIED`) : les cases de
 * planete du Bio-modificateur kaelesh et le carburant rendu au rappel du Pilote automatique a fronde mecha. Le
 * joueur pouvait construire l un et payer l autre en artefacts, pour un bonus que le jeu ne rendait pas. La regle
 * `LifeformAvailability` ferme les deux objets partout : la file, le choix d un emplacement (direct, tirage), les
 * vignettes, les panneaux et la fenetre du choix disent « indisponible » sans afficher d effet.
 */
final class LifeformAvailabilityTest extends AccountTestCase
{
    use PinsSettings;
    use PlacesLifeformSlots;

    private const int SANCTUARY = 14101;

    private const int VORTEX_CHAMBER = 14103;

    private const int HALLS_OF_REALISATION = 14104;

    private const int BIO_MODIFIER = 14109;

    private const int SLINGSHOT_AUTOPILOT = 13210;

    private const int ROCKTAL_TIER2_POSITION4 = 12210;

    /** L emplacement du palier 2, position 4 : celui du Pilote automatique a fronde. */
    private const int SLOT = 10;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pinSettings(['lifeforms_enabled' => 1, 'economy_speed' => 8, 'research_speed' => 1]);
        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Kaelesh, (int)Date::now()->timestamp);
        $this->planetAddResources(new Resources(10000000, 10000000, 10000000, 0));
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
     * La regle derive de la liste du resolveur : exactement les objets qui ne portent que des effets non appliques.
     */
    public function testTheRuleClosesExactlyTheObjectsWhoseOnlyEffectsAreNotApplied(): void
    {
        $fermes = [];
        foreach (LifeformCatalogue::all() as $objet) {
            if (!LifeformAvailability::isAvailable($objet)) {
                $fermes[] = $objet->id;
            }
        }
        sort($fermes);
        $this->assertSame([self::SLINGSHOT_AUTOPILOT, self::BIO_MODIFIER], $fermes, 'Le Pilote automatique a fronde et le Bio-modificateur, rien d autre.');
        foreach ($fermes as $id) {
            foreach (LifeformCatalogue::byId($id)->bonuses as $bonus) {
                $this->assertContains($bonus->code, LifeformBonusResolver::NOT_YET_APPLIED);
            }
        }
        // Un objet qui porte un effet applique a cote d un effet en attente reste disponible : on ne ferme que ce qui
        // ne rend rien. Aucun objet du catalogue n est dans ce cas ; un objet de banc le montre.
        $this->assertTrue(LifeformAvailability::isAvailable(LifeformCatalogue::byId(self::SANCTUARY)));
        $this->assertTrue(LifeformAvailability::isAvailable($this->anObjectWith([LifeformEffect::PLANET_FIELDS, LifeformEffect::LIVING_SPACE])), 'Un effet applique suffit.');
        $this->assertFalse(LifeformAvailability::isAvailable($this->anObjectWith([LifeformEffect::PLANET_FIELDS, LifeformEffect::RECALL_FUEL_REFUND])), 'Deux effets en attente : rien n est rendu.');
        $this->assertTrue(LifeformAvailability::isAvailable($this->anObjectWith([])), 'Sans effet, rien n est promis : disponible.');
    }

    public function testTheBioModifierIsNeitherBuiltNorPromisedOnTheBuildingsPage(): void
    {
        $maintenant = (int)Date::now()->timestamp;
        $file = resolve(LifeformQueueService::class);
        $planetId = $this->currentPlanetId;
        $metalAvant = $this->metal();

        // La file refuse avant toute ecriture, et le refus est celui de la regle — pas un prerequis manquant.
        try {
            $file->add($this->planetService, self::BIO_MODIFIER, $maintenant);
            $this->fail('Le Bio-modificateur ne se construit pas tant que ses cases ne sont pas appliquees.');
        } catch (LifeformRefused $refus) {
            $this->assertSame(LifeformRefused::NOT_AVAILABLE, $refus->reason);
        }
        $this->assertSame(0, LifeformQueue::query()->where('planet_id', $planetId)->count());
        $this->assertSame($metalAvant, $this->metal(), 'Aucun refus ne debite.');

        // La requete du navigateur recoit le refus en clair, dans la forme que le script du jeu lit.
        $refus = $this->post(route('lifeforms.buildings.addbuildrequest.post'), ['technologyId' => self::BIO_MODIFIER, 'mode' => 1, '_token' => csrf_token()]);
        $refus->assertJsonPath('success', false);
        $this->assertSame(__('t_lifeforms_ui.refused.not_available'), $refus->json('errors.0.message'));

        // La vignette : eteinte, marquee indisponible, sans fleche verte — et elle seule. Ses prerequis sont satisfaits
        // et les ressources y sont : seule la regle retient la fleche, sinon l essai ne distinguerait rien.
        $niveaux = resolve(LifeformLevels::class);
        foreach (LifeformCatalogue::byId(self::BIO_MODIFIER)->requirements as $exige => $niveauExige) {
            $niveaux->setLevel($planetId, LifeformKind::Building, $exige, $niveauExige);
        }
        $this->assertTrue($file->requirementsMet(LifeformCatalogue::byId(self::BIO_MODIFIER), $niveaux->buildingLevelsOf($planetId)), 'Premisse.');
        $page = (string)$this->get(route('lifeforms.buildings'))->getContent();
        $this->assertSame(1, preg_match('#<li[^>]*data-technology="' . self::BIO_MODIFIER . '"[^>]*data-status="off" data-unavailable="1"#', $page), 'La vignette du Bio-modificateur est marquee indisponible.');
        $this->assertSame(1, substr_count($page, 'data-unavailable="1"'), 'Aucune autre vignette ne l est.');
        $this->assertStringContainsString(e(__('t_lifeforms_ui.refused.not_available')), $page);
        $this->assertSame(0, preg_match('#<button class="upgrade[^"]*"[^>]*data-technology="' . self::BIO_MODIFIER . '"#', $page), 'Pas de fleche verte sur la vignette.');
        $this->assertSame(1, preg_match('#<button class="upgrade[^"]*"[^>]*data-technology="' . self::SANCTUARY . '"#', $page), 'Premisse : la fleche verte existe sur une vignette disponible.');

        // Le panneau : l indisponibilite en clair, aucun effet promis, aucun bouton.
        $panneau = (string)$this->get(route('lifeforms.buildings.ajax', ['technology' => self::BIO_MODIFIER]))->json('content.technologydetails');
        $this->assertStringContainsString('class="overmark lifeform_unavailable"', $panneau);
        $this->assertStringContainsString(e(__('t_lifeforms_ui.refused.not_available')), $panneau);
        $this->assertStringNotContainsString('lifeform_effects_table', $panneau, 'Aucun effet promis.');
        $this->assertStringNotContainsString('data-effect="planet_fields"', $panneau);
        $this->assertStringNotContainsString('<button class="upgrade"', $panneau, 'Aucun bouton d amelioration.');
        $this->assertStringContainsString('var showLifeformBonusCapReached = false;', $panneau);

        $sanctuaire = (string)$this->get(route('lifeforms.buildings.ajax', ['technology' => self::SANCTUARY]))->json('content.technologydetails');
        $this->assertStringNotContainsString('lifeform_unavailable', $sanctuaire, 'Premisse : un batiment disponible ne porte pas la mention.');
        $this->assertStringContainsString('lifeform_effects_table', $sanctuaire);
        $this->assertStringContainsString('<button class="upgrade" data-technology="' . self::SANCTUARY . '" >', $sanctuaire);
    }

    /**
     * Le choix d un emplacement : la technologie indisponible n est ni proposee, ni placee, ni payee, ni tiree.
     */
    public function testTheSlingshotAutopilotIsNeitherOfferedNorPlacedNorPaidNorDrawn(): void
    {
        $maintenant = (int)Date::now()->timestamp;
        $planetId = $this->currentPlanetId;
        $recherche = resolve(LifeformResearchService::class);
        $this->openSlotTen();
        $this->discover(Species::Mechas, $maintenant);
        $this->assertSame(1, LifeformAccount::query()->where('user_id', $this->currentUserId)->update(['artifacts' => 400]));

        $this->assertFalse(LifeformAvailability::isAvailable(LifeformCatalogue::byId(self::SLINGSHOT_AUTOPILOT)), 'Premisse.');

        // La fenetre : la fiche mecha dit « indisponible » sans formulaire ; le tirage aussi, puisque rien ne se tire.
        $fenetre = $this->get(route('lifeforms.research.slot.overlay', ['slot' => self::SLOT]));
        $fenetre->assertStatus(200);
        $html = (string)$fenetre->getContent();
        $this->assertStringContainsString('id="lifeform-slot-choice" data-slot="' . self::SLOT . '"', $html);
        $this->assertStringNotContainsString('name="choice" value="' . self::SLINGSHOT_AUTOPILOT . '"', $html, 'Aucun formulaire pour la technologie indisponible.');
        $this->assertStringNotContainsString('name="choice" value="random"', $html, 'Aucun tirage : rien a tirer.');
        $this->assertStringContainsString('name="choice" value="local"', $html, 'La technologie locale, elle, se choisit.');
        $this->assertSame(2, substr_count($html, 'class="overmark lifeform_unavailable"'), 'La fiche mecha et le tirage portent la mention.');
        $this->assertStringNotContainsString('select-button-artifacts', $html, 'Ni bouton d achat, ni bouton grise : la fiche ne se vend pas.');
        $this->assertSame(1, substr_count($html, 'lifeformnotclaim'), 'La fiche mecha est eteinte ; le tirage n est pas une fiche mais un bouton de l en-tete.');
        $this->assertStringNotContainsString('id="selectChance"', $html, 'Aucun bouton de tirage.');

        // Le service : refus direct, refus du tirage, et pas un artefact debite.
        $this->assertRefused(fn () => $recherche->choose($planetId, $this->currentUserId, self::SLOT, (string)self::SLINGSHOT_AUTOPILOT, $maintenant), LifeformRefused::NOT_AVAILABLE);
        $this->assertRefused(fn () => $recherche->choose($planetId, $this->currentUserId, self::SLOT, 'random', $maintenant), LifeformRefused::NOT_AVAILABLE);
        $this->assertSame(400, (int)LifeformAccount::query()->where('user_id', $this->currentUserId)->value('artifacts'), 'Aucun artefact paye pour un refus.');
        $this->assertNull(LifeformSlot::query()->where('planet_id', $planetId)->where('slot', self::SLOT)->whereNotNull('object_id')->first());

        // Le refus arrive au joueur par la page, en clair.
        $reponse = $this->post(route('lifeforms.research.choose'), ['slot' => self::SLOT, 'choice' => (string)self::SLINGSHOT_AUTOPILOT]);
        $reponse->assertRedirect(route('lifeforms.research'));
        $reponse->assertSessionHas('lifeforms_error', __('t_lifeforms_ui.refused.not_available'));

        // Une seconde espece decouverte dont la technologie est disponible : le tirage revient, et il ne tire qu elle.
        $this->discover(Species::Rocktal, $maintenant);
        $html = (string)$this->get(route('lifeforms.research.slot.overlay', ['slot' => self::SLOT]))->getContent();
        $this->assertStringContainsString('name="choice" value="random"', $html);
        $this->assertStringContainsString('<a class="select-button" id="selectChance"', $html, 'Le tirage est le bouton de l en-tete, comme le bundle officiel le lie.');
        $this->assertSame(3, substr_count($html, 'class="lifeform-item lifeform-choice'), 'Trois fiches — la locale, la mecha fermee, la rock tal —, et pas une pour le tirage.');
        $this->assertStringContainsString('name="choice" value="' . self::ROCKTAL_TIER2_POSITION4 . '"', $html);
        $this->assertStringNotContainsString('name="choice" value="' . self::SLINGSHOT_AUTOPILOT . '"', $html);
        $this->assertSame(1, substr_count($html, 'class="overmark lifeform_unavailable"'), 'Seule la fiche mecha reste fermee.');

        // Douze tirages, l emplacement vide entre deux (avec sa ligne d historique) : un tirage qui ne filtrerait pas
        // tomberait sur la technologie mecha une fois sur deux, et survivrait a douze tirages une fois sur 4096.
        for ($i = 0; $i < 12; $i++) {
            $tirage = $recherche->choose($planetId, $this->currentUserId, self::SLOT, 'random', $maintenant + $i);
            $this->assertSame(self::ROCKTAL_TIER2_POSITION4, $tirage->object_id, "Tirage $i : le tirage ne tire pas la technologie indisponible.");
            $tirage->delete();
            resolve(LifeformSlotHistory::class)->record($planetId, self::SLOT, null, $maintenant + $i + 1);
        }
        $this->assertSame(400, (int)LifeformAccount::query()->where('user_id', $this->currentUserId)->value('artifacts'), 'Le tirage est gratuit.');
    }

    /**
     * Une technologie placee avant la regle (une donnee de production) se montre indisponible et ne se recherche pas.
     */
    public function testATechnologyPlacedBeforeTheRuleIsShownUnavailableAndNotResearched(): void
    {
        $maintenant = (int)Date::now()->timestamp;
        $planetId = $this->currentPlanetId;
        $this->openSlotTen();
        $this->discover(Species::Mechas, $maintenant);
        resolve(LifeformLevels::class)->setLevel($planetId, LifeformKind::Building, self::VORTEX_CHAMBER, 1);
        $this->placeLifeformSlot($planetId, self::SLOT, self::SLINGSHOT_AUTOPILOT, $maintenant - 10);

        $page = (string)$this->get(route('lifeforms.research'))->getContent();
        $this->assertSame(1, preg_match('#<li[^>]*data-slot="' . self::SLOT . '"[^>]*data-technology="' . self::SLINGSHOT_AUTOPILOT . '"[^>]*data-status="off" data-unavailable="1"#', $page), 'La vignette est eteinte et marquee.');
        $this->assertSame(1, substr_count($page, 'data-unavailable="1"'));

        $panneau = (string)$this->get(route('lifeforms.research.ajax', ['technology' => self::SLINGSHOT_AUTOPILOT]))->json('content.technologydetails');
        $this->assertStringContainsString('class="overmark lifeform_unavailable"', $panneau);
        $this->assertStringNotContainsString('lifeform_effects_table', $panneau);
        $this->assertStringNotContainsString('<button class="upgrade"', $panneau);

        $this->assertRefused(fn () => resolve(LifeformQueueService::class)->add($this->planetService, self::SLINGSHOT_AUTOPILOT, $maintenant), LifeformRefused::NOT_AVAILABLE);
        $refus = $this->post(route('lifeforms.buildings.addbuildrequest.post'), ['technologyId' => self::SLINGSHOT_AUTOPILOT, 'mode' => 1, '_token' => csrf_token()]);
        $refus->assertJsonPath('success', false);
        $this->assertSame(__('t_lifeforms_ui.refused.not_available'), $refus->json('errors.0.message'));
        $this->assertSame(0, LifeformQueue::query()->where('planet_id', $planetId)->count());
    }

    /**
     * Le palier 2 s ouvre sur la population de palier 2 : les Salles de realisation la portent, et la position 4
     * exige sept millions d habitants.
     */
    private function openSlotTen(): void
    {
        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Building, self::HALLS_OF_REALISATION, 1);
        LifeformPlanet::query()->where('planet_id', $this->currentPlanetId)->update(['population' => 7000000.0]);
    }

    private function discover(Species $species, int $now): void
    {
        LifeformSpeciesProgress::query()->updateOrCreate(
            ['user_id' => $this->currentUserId, 'species' => $species->value],
            ['discovered_at' => $now - 100, 'experience' => 0]
        );
    }

    /**
     * Un batiment de banc qui porte ces effets, pour eprouver la regle hors du catalogue.
     *
     * @param array<int, string> $codes
     */
    private function anObjectWith(array $codes): LifeformObject
    {
        return new LifeformObject(11112, Species::Humans, LifeformKind::Building, 12, 'bench_building', 1, 1, 0, 0, 1.0, 1.0, 1, 1.0, [], null, null, array_map(fn (string $code): LifeformBonus => new LifeformBonus($code, null, 1.0, 1.0, null), $codes));
    }

    private function metal(): int
    {
        return (int)Planet::query()->whereKey($this->currentPlanetId)->value('metal');
    }

    private function assertRefused(callable $action, string $raison): void
    {
        try {
            $action();
            $this->fail("Un refus « $raison » etait attendu.");
        } catch (LifeformRefused $refus) {
            $this->assertSame($raison, $refus->reason, $refus->getMessage());
        }
    }
}
