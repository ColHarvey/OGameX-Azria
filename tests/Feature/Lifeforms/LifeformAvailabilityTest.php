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
use OGame\Lifeforms\Research\LifeformSlotHistory;
use OGame\Lifeforms\Research\LifeformSlotRules;
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
 * Deux objets du catalogue etaient fermes par cette regle : le Bio-modificateur kaelesh, dont les cases de planete
 * n etaient pas appliquees, et le Pilote automatique a fronde mecha, dont le carburant rendu au rappel ne l etait pas
 * davantage. **Keven a tranche les deux le 19 septembre 2026** (journal §165) : deux cases par niveau, et une part du
 * carburant que le retour ne rend pas deja. Les deux effets sont appliques, les deux objets sont ouverts, et
 * `LifeformBonusResolver::NOT_YET_APPLIED` est vide.
 *
 * Cette classe tient donc les deux moities de la regle : **plus aucun objet du catalogue n est ferme** — le joueur
 * construit le Bio-modificateur, choisit et tire le Pilote automatique a fronde —, et **la regle ferme toujours** un
 * effet neuf que personne n aurait raccorde, eprouvee contre une liste d attente posee par le temoin (la liste du jeu
 * etant vide, passer par elle ne distinguerait plus le juste du faux).
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

    /** L emplacement du palier 2, position 4 : celui du Pilote automatique a fronde. */
    private const int SLOT = 10;

    /** Un effet imaginaire : celui que personne n aurait raccorde. */
    private const string EFFET_NEUF = 'effet_jamais_raccorde';

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
     * La regle derive de la liste du resolveur : elle est vide, donc plus rien n est ferme — et un code pose dans une
     * liste d attente ferme exactement l objet qui ne porte que lui.
     */
    public function testNothingIsClosedAnyMoreAndTheRuleStillClosesAnEffectNobodyWiredUp(): void
    {
        $this->assertSame([], LifeformBonusResolver::NOT_YET_APPLIED, 'Plus aucun effet du catalogue n attend de decision (journal §165).');

        $fermes = [];
        foreach (LifeformCatalogue::all() as $objet) {
            if (!LifeformAvailability::isAvailable($objet)) {
                $fermes[] = $objet->id;
            }
        }
        $this->assertSame([], $fermes, 'Aucun objet du catalogue n est ferme : les deux derniers effets sont appliques.');

        // La regle elle-meme, contre une liste d attente posee ici. Sans cela, juste et faux coincideraient : tout
        // objet serait disponible, que la regle compare ou non.
        $this->assertFalse(LifeformAvailability::isAvailableGiven($this->anObjectWith([self::EFFET_NEUF]), [self::EFFET_NEUF]), 'Un objet qui ne porte qu un effet en attente est ferme.');
        $this->assertTrue(LifeformAvailability::isAvailableGiven($this->anObjectWith([self::EFFET_NEUF, LifeformEffect::LIVING_SPACE]), [self::EFFET_NEUF]), 'Un effet applique a cote suffit a ouvrir l objet.');
        $this->assertTrue(LifeformAvailability::isAvailableGiven($this->anObjectWith([]), [self::EFFET_NEUF]), 'Sans effet, rien n est promis : disponible.');
        $this->assertTrue(LifeformAvailability::isAvailableGiven($this->anObjectWith([LifeformEffect::LIVING_SPACE]), [self::EFFET_NEUF]), 'Un effet hors de la liste ouvre l objet.');

        // Et ce sont bien ces deux effets-la qui fermaient les deux objets : les remettre en attente les referme, ce
        // qui etablit qu ils ne portent rien d autre et que leur ouverture vient de la decision, pas d un contournement.
        $this->assertFalse(LifeformAvailability::isAvailableGiven(LifeformCatalogue::byId(self::BIO_MODIFIER), [LifeformEffect::PLANET_FIELDS]), 'Le Bio-modificateur ne porte que ses cases de planete.');
        $this->assertFalse(LifeformAvailability::isAvailableGiven(LifeformCatalogue::byId(self::SLINGSHOT_AUTOPILOT), [LifeformEffect::RECALL_FUEL_REFUND]), 'Le Pilote automatique a fronde ne porte que son carburant rendu.');
        $this->assertTrue(LifeformAvailability::isAvailableGiven(LifeformCatalogue::byId(self::SANCTUARY), [LifeformEffect::PLANET_FIELDS]), 'Premisse : un objet voisin ne depend pas de cet effet.');
    }

    /**
     * Le Bio-modificateur se construit et annonce ses cases — deux par niveau, un nombre et non un pour cent.
     */
    public function testTheBioModifierIsBuiltAndAnnouncesItsFields(): void
    {
        $maintenant = (int)Date::now()->timestamp;
        $planetId = $this->currentPlanetId;
        $niveaux = resolve(LifeformLevels::class);
        foreach (LifeformCatalogue::byId(self::BIO_MODIFIER)->requirements as $exige => $niveauExige) {
            $niveaux->setLevel($planetId, LifeformKind::Building, $exige, $niveauExige);
        }
        $metalAvant = $this->metal();

        // La vignette porte la fleche verte et aucune mention d indisponibilite — nulle part sur la page.
        $page = (string)$this->get(route('lifeforms.buildings'))->getContent();
        $this->assertSame(0, substr_count($page, 'data-unavailable="1"'), 'Plus aucune vignette n est marquee indisponible.');
        $this->assertStringNotContainsString(e(__('t_lifeforms_ui.refused.not_available')), $page);
        $this->assertSame(1, preg_match('#<button class="upgrade[^"]*"[^>]*data-technology="' . self::BIO_MODIFIER . '"#', $page), 'La fleche verte existe sur la vignette du Bio-modificateur.');

        // Le panneau promet l effet, chiffre en cases : deux par niveau, du niveau courant au suivant.
        $panneau = (string)$this->get(route('lifeforms.buildings.ajax', ['technology' => self::BIO_MODIFIER]))->json('content.technologydetails');
        $this->assertStringNotContainsString('lifeform_unavailable', $panneau, 'Le Bio-modificateur ne se dit plus indisponible.');
        $this->assertStringContainsString('lifeform_effects_table', $panneau);
        $this->assertSame(1, preg_match('#<tr data-effect="' . LifeformEffect::PLANET_FIELDS . '">.*?<td style="text-align: right;">\s*0\s*</td>\s*<td style="text-align: right;" class="undermark">\s*2\s*</td>#s', $panneau), 'La ligne des cases annonce 0 puis 2 : un nombre de cases, jamais un pour cent.');
        $this->assertStringNotContainsString('200 %', $panneau, 'Les cases ne se lisent pas en pour cent.');

        // Et il se construit : la file l accepte et la planete paie.
        resolve(LifeformQueueService::class)->add($this->planetService, self::BIO_MODIFIER, $maintenant);
        $this->assertSame(1, LifeformQueue::query()->where('planet_id', $planetId)->where('object_id', self::BIO_MODIFIER)->count(), 'Le Bio-modificateur entre dans la file.');
        $this->assertLessThan($metalAvant, $this->metal(), 'La construction est payee.');
    }

    /**
     * Le Pilote automatique a fronde se choisit, se paie et se tire — les trois chemins que la regle fermait.
     */
    public function testTheSlingshotAutopilotIsOfferedPlacedAndDrawn(): void
    {
        $maintenant = (int)Date::now()->timestamp;
        $planetId = $this->currentPlanetId;
        $recherche = resolve(LifeformResearchService::class);
        $this->openSlotTen();
        $this->discover(Species::Mechas, $maintenant);
        $this->assertSame(1, LifeformAccount::query()->where('user_id', $this->currentUserId)->update(['artifacts' => 400]));

        $this->assertTrue(LifeformAvailability::isAvailable(LifeformCatalogue::byId(self::SLINGSHOT_AUTOPILOT)), 'Premisse : l objet est ouvert.');

        // La fenetre : la fiche mecha s offre, avec son bouton d achat, et le tirage existe puisqu il a de quoi tirer.
        $html = (string)$this->get(route('lifeforms.research.slot.overlay', ['slot' => self::SLOT]))->getContent();
        $this->assertStringContainsString('id="lifeform-slot-choice" data-slot="' . self::SLOT . '"', $html);
        $this->assertStringContainsString('name="choice" value="' . self::SLINGSHOT_AUTOPILOT . '"', $html, 'La fiche mecha se choisit.');
        $this->assertStringContainsString('name="choice" value="random"', $html, 'Le tirage a de quoi tirer.');
        $this->assertStringContainsString('select-button-artifacts', $html, 'Le bouton d achat en artefacts est la.');
        $this->assertStringNotContainsString('lifeform_unavailable', $html);
        $this->assertSame(0, substr_count($html, 'lifeformnotclaim'), 'Aucune fiche eteinte.');

        // Le tirage, seule espece etrangere decouverte : il rend exactement la technologie qu il refusait.
        $tirage = $recherche->choose($planetId, $this->currentUserId, self::SLOT, 'random', $maintenant);
        $this->assertSame(self::SLINGSHOT_AUTOPILOT, $tirage->object_id, 'Le tirage rend la technologie mecha.');
        $this->assertSame('random', $tirage->chosen_via);
        $this->assertSame(400, (int)LifeformAccount::query()->where('user_id', $this->currentUserId)->value('artifacts'), 'Le tirage est gratuit.');

        // Le choix direct, paye en artefacts, sur l emplacement libere.
        $tirage->delete();
        resolve(LifeformSlotHistory::class)->record($planetId, self::SLOT, null, $maintenant + 1);
        $ligne = $recherche->choose($planetId, $this->currentUserId, self::SLOT, (string)self::SLINGSHOT_AUTOPILOT, $maintenant + 2);
        $this->assertSame(self::SLINGSHOT_AUTOPILOT, $ligne->object_id);
        $this->assertSame('artifacts', $ligne->chosen_via);
        $this->assertSame(400 - LifeformSlotRules::ARTIFACT_COST[2], (int)LifeformAccount::query()->where('user_id', $this->currentUserId)->value('artifacts'), 'Le choix direct coute ses artefacts.');
    }

    /**
     * Une technologie placee dans un emplacement se montre et se recherche : ni vignette eteinte, ni refus de la file.
     */
    public function testAPlacedTechnologyIsShownAndResearched(): void
    {
        $maintenant = (int)Date::now()->timestamp;
        $planetId = $this->currentPlanetId;
        $this->openSlotTen();
        $this->discover(Species::Mechas, $maintenant);
        resolve(LifeformLevels::class)->setLevel($planetId, LifeformKind::Building, self::VORTEX_CHAMBER, 1);
        $this->placeLifeformSlot($planetId, self::SLOT, self::SLINGSHOT_AUTOPILOT, $maintenant - 10);

        $page = (string)$this->get(route('lifeforms.research'))->getContent();
        $this->assertSame(1, preg_match('#<li[^>]*data-slot="' . self::SLOT . '"[^>]*data-technology="' . self::SLINGSHOT_AUTOPILOT . '"#', $page), 'Premisse : la vignette est celle de la technologie placee.');
        $this->assertSame(0, substr_count($page, 'data-unavailable="1"'), 'Aucune vignette eteinte par la regle.');

        $panneau = (string)$this->get(route('lifeforms.research.ajax', ['technology' => self::SLINGSHOT_AUTOPILOT]))->json('content.technologydetails');
        $this->assertStringNotContainsString('lifeform_unavailable', $panneau);
        $this->assertStringContainsString('lifeform_effects_table', $panneau, 'L effet est promis, puisqu il est rendu.');
        $this->assertSame(1, preg_match('#<tr data-effect="' . LifeformEffect::RECALL_FUEL_REFUND . '">#', $panneau), 'La ligne du carburant rendu existe.');

        resolve(LifeformQueueService::class)->add($this->planetService, self::SLINGSHOT_AUTOPILOT, $maintenant);
        $this->assertSame(1, LifeformQueue::query()->where('planet_id', $planetId)->where('object_id', self::SLINGSHOT_AUTOPILOT)->count(), 'La recherche entre dans la file.');
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
}
