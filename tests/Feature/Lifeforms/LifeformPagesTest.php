<?php

namespace Tests\Feature\Lifeforms;

use Illuminate\Support\Facades\Date;
use OGame\Lifeforms\Services\LifeformInstallationService;
use OGame\Lifeforms\Species;
use OGame\Models\Lifeforms\LifeformAccount;
use OGame\Models\Lifeforms\LifeformBuildingLevel;
use OGame\Models\Lifeforms\LifeformPlanet;
use OGame\Models\Lifeforms\LifeformQueue;
use OGame\Models\Lifeforms\LifeformSpeciesProgress;
use OGame\Models\Lifeforms\LifeformWelcome;
use OGame\Models\Planet;
use OGame\Models\Resources;
use Tests\AccountTestCase;
use Tests\Support\PinsSettings;

/**
 * Les ecrans des formes de vie (tranche 2) : l entree du menu, l invitation, le choix de l espece, la
 * page des batiments, le panneau de detail, la demande et l annulation, le bandeau.
 */
final class LifeformPagesTest extends AccountTestCase
{
    use PinsSettings;

    protected function tearDown(): void
    {
        $planetes = Planet::query()->where('user_id', $this->currentUserId)->pluck('id');
        LifeformQueue::query()->whereIn('planet_id', $planetes)->delete();
        LifeformBuildingLevel::query()->whereIn('planet_id', $planetes)->delete();
        LifeformPlanet::query()->whereIn('planet_id', $planetes)->delete();
        LifeformAccount::query()->where('user_id', $this->currentUserId)->delete();
        LifeformSpeciesProgress::query()->where('user_id', $this->currentUserId)->delete();
        LifeformWelcome::query()->where('user_id', $this->currentUserId)->delete();
        $this->restorePinnedSettings();
        parent::tearDown();
    }

    public function testTheMenuEntryAndThePagesAreHiddenWhileTheSwitchIsClosed(): void
    {
        $this->pinSettings(['lifeforms_enabled' => 0]);

        $vueGenerale = $this->get(route('overview.index'));
        $vueGenerale->assertStatus(200);
        $vueGenerale->assertDontSee('id="menu-lifeforms"', false);
        $vueGenerale->assertDontSee('id="lifeform-welcome"', false);
        $vueGenerale->assertDontSee('id="population_box"', false);

        $this->get(route('lifeforms.index'))->assertStatus(404);
        $this->get(route('lifeforms.buildings'))->assertStatus(404);
        $this->get(route('lifeforms.buildings.ajax', ['technology' => 11101]))->assertStatus(404);
        $reponse = $this->post(route('lifeforms.buildings.addbuildrequest.post'), ['technologyId' => 11101, '_token' => csrf_token()]);
        $reponse->assertStatus(200);
        $reponse->assertJsonPath('success', false);
    }

    public function testTheMenuTheInvitationAndTheFourSpeciesAppearWhenTheSwitchIsOpen(): void
    {
        $this->pinSettings(['lifeforms_enabled' => 1]);

        $vueGenerale = $this->get(route('overview.index'));
        $vueGenerale->assertStatus(200);
        $vueGenerale->assertSee('id="menu-lifeforms"', false);
        $vueGenerale->assertSee('id="lifeform-welcome"', false);
        $vueGenerale->assertSee(route('lifeforms.welcome.later'), false);
        $vueGenerale->assertDontSee('id="population_box"', false);

        $page = $this->get(route('lifeforms.index'));
        $page->assertStatus(200);
        foreach ([1, 2, 3, 4] as $valeur) {
            $page->assertSee('name="species" value="' . $valeur . '"', false);
        }
        $page->assertSee('lifeform-species-mechas', false);
        $page->assertSee('lifeformTech11101', false);
        $page->assertSee('lifeformTech14218', false);
    }

    public function testLaterHidesTheInvitationWithoutChoosingAnything(): void
    {
        $this->pinSettings(['lifeforms_enabled' => 1]);

        $this->post(route('lifeforms.welcome.later'))->assertRedirect(route('overview.index'));

        $this->get(route('overview.index'))->assertDontSee('id="lifeform-welcome"', false);
        $this->assertNull(resolve(LifeformInstallationService::class)->accountOf($this->currentUserId));
        $this->assertSame(1, LifeformWelcome::query()->where('user_id', $this->currentUserId)->count());
    }

    public function testChoosingASpeciesFromThePageIsFinalAndShowsTheBanner(): void
    {
        $this->pinSettings(['lifeforms_enabled' => 1]);

        $this->post(route('lifeforms.select'), ['species' => 3])->assertRedirect(route('lifeforms.index'));
        $this->assertSame(Species::Mechas, resolve(LifeformInstallationService::class)->speciesOf($this->currentUserId));

        $page = $this->get(route('lifeforms.index'));
        $page->assertStatus(200);
        $page->assertDontSee('name="species"', false);
        $page->assertSee(route('lifeforms.buildings'), false);

        // Le second choix est refuse, et la page le dit.
        $refus = $this->post(route('lifeforms.select'), ['species' => 1]);
        $refus->assertRedirect(route('lifeforms.index'));
        $refus->assertSessionHas('lifeforms_error');
        $this->assertSame(Species::Mechas, resolve(LifeformInstallationService::class)->speciesOf($this->currentUserId));

        // Le bandeau porte la population de base des Mechas et le portrait, et l invitation a disparu.
        $vueGenerale = $this->get(route('overview.index'));
        $vueGenerale->assertSee('id="population_box"', false);
        $vueGenerale->assertSee('id="food_box"', false);
        $vueGenerale->assertSee('id="resources_population" data-raw="500"', false);
        $vueGenerale->assertSee('lifeform-item-icon lifeform3', false);
        $vueGenerale->assertDontSee('id="lifeform-welcome"', false);
        $vueGenerale->assertSee('id="productionboxlfbuildingcomponent"', false);
        $vueGenerale->assertSee('id="productionboxlfresearchcomponent"', false);
    }

    public function testTheBuildingsPageListsTheTwelveBuildingsOfTheSpecies(): void
    {
        $this->pinSettings(['lifeforms_enabled' => 1]);
        $this->get(route('lifeforms.buildings'))->assertRedirect(route('lifeforms.index'));

        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Rocktal, (int)Date::now()->timestamp);

        $page = $this->get(route('lifeforms.buildings'));
        $page->assertStatus(200);
        for ($i = 1; $i <= 12; $i++) {
            $page->assertSee('data-technology="' . (12100 + $i) . '"', false);
        }
        $page->assertDontSee('data-technology="11101"', false);
        $page->assertSee('data-technology="12101"', false);
        $page->assertSee(route('lifeforms.buildings.ajax'), false);
        $page->assertSee(route('lifeforms.buildings.addbuildrequest.post'), false);
        $page->assertSee('id="productionboxlfbuildingcomponent"', false);
    }

    public function testTheDetailPanelTheRequestAndTheCancellationGoThroughTheQueue(): void
    {
        $this->pinSettings(['lifeforms_enabled' => 1, 'economy_speed' => 8]);
        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Humans, (int)Date::now()->timestamp);
        $this->planetAddResources(new Resources(10000, 10000, 10000, 0));
        $this->get(route('lifeforms.buildings'))->assertStatus(200);

        $detail = $this->get(route('lifeforms.buildings.ajax', ['technology' => 11101]));
        $detail->assertStatus(200);
        $detail->assertJsonPath('target', 'technologydetails');
        $html = $detail->json('content.technologydetails');
        $this->assertIsString($html);
        $this->assertStringContainsString('data-technology-id="11101"', $html);
        $this->assertStringContainsString('data-value="7"', $html, 'Le prix du niveau 1 : 7 de metal.');
        $this->assertStringContainsString('data-value="2"', $html, 'et 2 de cristal.');
        $this->assertStringContainsString('data-effect="living_space"', $html);
        $this->assertStringContainsString('<button class="upgrade" data-technology="11101" >', $html);

        $this->get(route('lifeforms.buildings.ajax', ['technology' => 99999]))->assertStatus(404);
        $this->get(route('lifeforms.buildings.ajax', ['technology' => 12101]))->assertStatus(404);

        $demande = $this->post(route('lifeforms.buildings.addbuildrequest.post'), ['technologyId' => 11101, 'mode' => 1, '_token' => csrf_token()]);
        $demande->assertStatus(200);
        $demande->assertJsonPath('status', 'success');
        $element = LifeformQueue::query()->where('planet_id', $this->currentPlanetId)->where('object_id', 11101)->first();
        $this->assertNotNull($element);
        $this->assertSame('running', $element->status);

        $page = $this->get(route('lifeforms.buildings'));
        $page->assertSee('data-technology="11101"', false);
        $page->assertSee('data-status="active"', false);
        $page->assertSee('lfBuildingCountdown', false);
        $page->assertSee(route('lifeforms.buildings.cancelbuildrequest'), false);

        $mauvaisJeton = $this->post(route('lifeforms.buildings.addbuildrequest.post'), ['technologyId' => 11101, '_token' => 'faux']);
        $mauvaisJeton->assertJsonPath('success', false);

        $refus = $this->post(route('lifeforms.buildings.addbuildrequest.post'), ['technologyId' => 12101, 'mode' => 1, '_token' => csrf_token()]);
        $refus->assertJsonPath('success', false);
        $this->assertNotEmpty($refus->json('errors.0.message'), 'Le script du jeu lit errors[0].message.');

        $annulation = $this->post(route('lifeforms.buildings.cancelbuildrequest'), ['technologyId' => 11101, 'listId' => $element->id, '_token' => csrf_token()]);
        $annulation->assertJsonPath('status', 'success');
        $this->assertSame('canceled', $element->refresh()->status);
    }

    /**
     * **Les pages des formes de vie vivent dans les conteneurs larges que la feuille de style du jeu connait**
     * (previsualisation locale, journal §155.22). Le premier gabarit posait leurs panneaux dans `.content-box-s`,
     * la boite laterale de 220 px, sous des identifiants que la feuille ignore : la page se lisait dans une colonne
     * etroite, ici comme en production. L essai vise la forme de code — la classe de la boite etroite comme
     * conteneur —, pas un mot.
     */
    public function testTheLifeformPagesUseTheWideContainersTheStylesheetKnows(): void
    {
        $this->pinSettings(['lifeforms_enabled' => 1]);

        $sansEspece = $this->get(route('lifeforms.index'));
        $sansEspece->assertStatus(200);
        $sansEspece->assertSee('id="lfsettingscomponent"', false);
        $sansEspece->assertSee('class="lfsettingsContentWrapper"', false);
        $sansEspece->assertSee('class="lifeform-item lifeform-species lifeform-species-humans lifeformcanclaim"', false);
        $sansEspece->assertSee('class="select-button"', false);
        $sansEspece->assertDontSee('class="content-box-s"', false);

        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Rocktal, (int)Date::now()->timestamp);

        $avecEspece = $this->get(route('lifeforms.index'));
        $avecEspece->assertStatus(200);
        $avecEspece->assertSee('class="lifeform-item lifeform-species lifeform-species-rocktal lifeformclaimed"', false);
        $avecEspece->assertSee('class="lifeform-item lifeform-species lifeform-species-humans lifeformnotclaim"', false);
        $avecEspece->assertDontSee('class="select-button"', false);
        $avecEspece->assertDontSee('class="content-box-s"', false);

        $decouvertes = $this->get(route('lifeforms.discoveries'));
        $decouvertes->assertStatus(200);
        $decouvertes->assertSee('id="lfsettingscomponent"', false);
        $decouvertes->assertSee('id="lifeform-discoveries-quota"', false);
        $decouvertes->assertDontSee('class="content-box-s"', false);

        $bonus = $this->get(route('lifeforms.bonuses'));
        $bonus->assertStatus(200);
        $bonus->assertSee('id="lfbonusescomponent"', false);
        $bonus->assertSee('class="mainRS" id="lifeform-experience-bonuses"', false);
        $bonus->assertSee('class="mainRS" id="lifeform-effect-bonuses"', false);
        $bonus->assertDontSee('class="content-box-s"', false);
    }
}
