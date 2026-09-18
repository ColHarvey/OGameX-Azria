<?php

namespace Tests\Feature\Lifeforms;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Date;
use OGame\Factories\PlayerServiceFactory;
use OGame\Lifeforms\Presentation\LifeformBanner;
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
        // Sans espece, le bouton principal du menu mene au choix : il n y a rien a batir.
        $this->assertSame(route('lifeforms.index'), self::menuButtonTargetOf((string)$vueGenerale->getContent()));

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

        // **Le parcours officiel une fois l espece choisie** : bouton principal → batiments, petite icone →
        // recherches, portrait du bandeau → page des especes (releve de Codex, journal §155.24).
        $html = (string)$vueGenerale->getContent();
        $this->assertSame(route('lifeforms.buildings'), self::menuButtonTargetOf($html));
        $this->assertSame(1, preg_match('#<span class="menu_icon">\s*<a href="([^"]+)"#', substr($html, (int)strpos($html, 'id="menu-lifeforms"')), $icone));
        $this->assertSame(route('lifeforms.research'), $icone[1] ?? null);
        $this->assertSame(1, preg_match('#<div id="lifeform" class="fleft">\s*<a href="([^"]+)"#', $html, $portrait));
        $this->assertSame(route('lifeforms.index'), $portrait[1] ?? null);
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
        // Les deux boites de file, cote a cote sous la grille, comme la capture officielle des batiments.
        $page->assertSee('id="productionboxlfresearchcomponent"', false);
        $page->assertSee('<div id="productionboxBottom">', false);

        // **La page vit dans le conteneur que la feuille taille pour elle** (`#lfbuildings` : grille en
        // `space-between`, remplisseur de 422 px), pas dans celui de la page des ressources (journal §155.23).
        $page->assertSee('id="lfbuildingscomponent"', false);
        $page->assertSee('<div id="lfbuildings"', false);
        $page->assertDontSee('id="supplies"', false);
        $page->assertDontSee('id="suppliescomponent"', false);
        // Aucun paragraphe de chiffres entre le bandeau et la grille : la barre du haut les porte.
        $page->assertDontSee('lifeform-figures', false);
        // **Les deux variables que le bundle lit sans garde** au clic de la fleche verte d une vignette
        // (`if (planetMoveInProgress)`, puis `lastBuildingSlot.shouldWarnForTechnologyId(...)`) : absentes, le
        // clic levait une ReferenceError et rien ne partait en construction.
        $page->assertSee('var planetMoveInProgress = false;', false);
        $page->assertSee('var lastBuildingSlot = {', false);
        $page->assertSee('"shouldWarnForTechnologyId": function (technologyId) { return false; }', false);
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

        // **La bande de description est un FRERE de `.content`**, enfant direct de `#technologydetails` : la
        // feuille la pose a 203 px du haut sur 95 px (`#technologydetails>.description`), et `.content`
        // (200 px, `overflow:hidden`) coupait les effets quand ils vivaient dedans (journal §155.23).
        $this->assertSame('technologydetails', $this->parentIdOfTheDescriptionBand($html));
        $this->assertStringContainsString('<div class="txt_box">', $html);
        // Et la variable que le bundle lit au clic du bouton du panneau : sans elle, ReferenceError (demo pilotee).
        $this->assertStringContainsString('var showLifeformBonusCapReached = false;', $html);

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

        // **Le script de la file doit etre du JavaScript, pas du HTML** : `{{ json_encode() }}` dans un `<script>`
        // rendait `errorBoxDecision(&quot;Prudence&quot;, ...)` — le navigateur ne decode pas les entites d un script,
        // le bloc entier cassait, et ni le compte a rebours ni l annulation ne vivaient (releve de Codex, journal
        // §155.24). Le temoin vise la forme : aucune entite HTML dans aucun bloc de script de la page.
        self::assertScriptsCarryNoHtmlEntity((string)$page->getContent());
        $page->assertSee('errorBoxDecision("' . __('t_ingame.shared.caution') . '", "" + question + "", "' . __('t_ingame.shared.yes') . '", "' . __('t_ingame.shared.no') . '", function () {', false);

        // **Et l annulation depuis une fiche passe sa question en JSON** : une apostrophe du texte (« l'amélioration »,
        // en francais — la langue vient de la session, comme le joueur la choisit) fermait la chaine JavaScript ecrite
        // entre apostrophes dans l attribut `onclick`.
        $fiche = (string)$this->withSession(['locale' => 'fr'])->get(route('lifeforms.buildings.ajax', ['technology' => 11101]))->json('content.technologydetails');
        $this->assertSame(1, preg_match('/onclick="cancelbuilding\(11101,' . $element->id . ',(&quot;.*?&quot;)\); return false;"/', $fiche, $appel), 'La fiche porte l appel d annulation avec sa question.');
        $question = json_decode(html_entity_decode($appel[1], ENT_QUOTES | ENT_HTML5), true);
        $this->assertIsString($question, 'La question est une chaine JSON valide une fois l attribut decode.');
        $this->assertStringContainsString("'", $question, 'Premisse : la question porte une apostrophe, sinon l essai ne distingue rien.');
        $this->assertSame(__('t_ingame.ajax_object.cancel_expansion_confirm', ['name' => __('t_lifeforms.residential_sector.title', [], 'fr'), 'level' => (int)$element->target_level], 'fr'), $question);

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
        // **Le cadre officiel d une fiche** : la classe d etat sur la fiche, le wrapper et la barre basse dedans —
        // c est cette structure a trois pieces que le sprite (`e3e67150...png`) habille, et une capture officielle
        // (Interface_graphique_exemple/Nuova-immagine-bitmap-3.png) montre exactement ce rendu, encoche comprise
        // (journal §155.25). Le capot seul, sans wrapper ni barre, paraissait une languette orpheline (§155.23).
        $sansEspece->assertSee('class="lifeform-item lifeform-species lifeform-species-humans lifeformcanclaim" data-species="1" data-state="can-choose"', false);
        $this->assertSame(4, substr_count((string)$sansEspece->getContent(), '<div class="lifeform-item-wrapper"'), 'Quatre fiches, quatre wrappers.');
        $this->assertSame(4, substr_count((string)$sansEspece->getContent(), '<div class="lifeform-item-bottom"></div>'), 'Quatre fiches, quatre barres basses.');
        // L en-tete est l illustration officielle de la page des especes, a sa hauteur de 250 px.
        $sansEspece->assertSee('img/icons/6dafcd306b27d77508ef115722b6b0.jpg); height: 250px;', false);
        $sansEspece->assertSee('class="select-button"', false);
        $sansEspece->assertDontSee('class="content-box-s"', false);

        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Rocktal, (int)Date::now()->timestamp);

        $avecEspece = $this->get(route('lifeforms.index'));
        $avecEspece->assertStatus(200);
        $avecEspece->assertSee('lifeform-species-rocktal lifeformclaimed" data-species="2" data-state="chosen"', false);
        $avecEspece->assertSee('lifeform-species-humans lifeformnotclaim" data-species="1" data-state="other"', false);
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

    /**
     * **Les pages prennent les habillages que la feuille du jeu donne — et ceux qu elle donne seulement a la bonne
     * forme de code** (controle a l ecran, journal §155.23). Quatre ecarts mesures, quatre temoins :
     *
     * - une barre de titre de section n existe que sur `.lfsettingsContent > h3` ; posee sur le cadre, elle ne
     *   dessinait rien et les quatre sections des decouvertes restaient du texte bleu nu ;
     * - la page des bonus n est pas `#lfsettings` : ses barres sont les `bonus-item-heading` du composant ;
     * - **l anneau d experience se declare en style, jamais en attribut** : la feuille impose
     *   `.xpbar .progress-ring__circle{stroke-dasharray:10 20}`, qui bat tout attribut SVG — l anneau etait
     *   pointille et ne montrait aucune progression, quel que soit le compte ;
     * - les listes d une espece prennent les barres repliables du jeu (`.lifeformTechnology > h1` et
     *   `.technologyRow`), plus deux `<details>` nus.
     */
    public function testTheLifeformPagesTakeTheGraphicsTheStylesheetGives(): void
    {
        $this->pinSettings(['lifeforms_enabled' => 1]);
        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Rocktal, (int)Date::now()->timestamp);

        $especes = $this->get(route('lifeforms.index'));
        $especes->assertStatus(200);
        $especes->assertSee('<div class="lifeformTechnology">', false);
        $especes->assertSee('<div class="technologyRow"', false);
        $especes->assertSee('class="technologyName"', false);
        $especes->assertDontSee('<details>', false);
        $especes->assertSee('style="stroke-dasharray:', false);
        $especes->assertDontSee('stroke-dasharray="', false);

        $decouvertes = $this->get(route('lifeforms.discoveries'));
        $decouvertes->assertStatus(200);
        // Le titre est l enfant direct de la boite interieure : c est la seule forme que la feuille habille. Les
        // quatre sections, chacune avec sa boite ET son titre juste dedans (blancs et fins de ligne ignores).
        $html = (string)$decouvertes->getContent();
        $this->assertSame(4, substr_count($html, 'class="lfsettingsContent"'), 'Les quatre sections des decouvertes doivent chacune porter la boite qui donne la barre de titre.');
        $this->assertSame(4, preg_match_all('#<div class="lfsettingsContent">\s*<h3>#', $html), 'Chaque titre doit etre l enfant direct de la boite interieure.');

        $bonus = $this->get(route('lifeforms.bonuses'));
        $bonus->assertStatus(200);
        $bonus->assertSee('<bonus-item-heading class="active">', false);
        $bonus->assertSee('style="stroke-dasharray:', false);
        $bonus->assertDontSee('stroke-dasharray="', false);
        $bonus->assertSee('class="lifeform-item-icon lifeform2"', false);

        // Le portrait du bandeau vit de la feuille seule : un raccourci `background:` en ligne remettait la
        // position du sprite a 0 0 et la pastille etait vide a cote des officiers.
        $vueGenerale = $this->get(route('overview.index'));
        $vueGenerale->assertSee('<div class="lifeform-item-icon lifeform2"></div>', false);

        // Chaque page porte des scripts en ligne (file, variables du bouton vert, repli des listes) : aucun ne porte
        // d entite HTML — voir testTheDetailPanel... pour le defaut que cette forme a produit.
        foreach ([$especes, $decouvertes, $bonus, $vueGenerale] as $reponse) {
            self::assertScriptsCarryNoHtmlEntity((string)$reponse->getContent());
        }
    }

    /**
     * **Le bandeau des ressources porte la population et la nourriture des que la page les montre.**
     *
     * `ResourceTicker.reload()` (le bundle du jeu) lit `data.resources.population.tooltip` des que la page porte
     * `#population_box`. La charge de `/ajax/resourcebox` n en portait rien : la resynchronisation levait une
     * TypeError toutes les trente secondes, le bandeau restait fige et le compteur ne repartait pas.
     */
    public function testTheResourceBarCarriesPopulationAndFoodOnceASpeciesIsChosen(): void
    {
        $this->pinSettings(['lifeforms_enabled' => 1]);

        $sansEspece = $this->getJson('/ajax/resourcebox')->assertStatus(200)->json();
        $this->assertIsArray($sansEspece['resources']);
        $this->assertArrayNotHasKey('population', $sansEspece['resources'], 'Sans espece, la page ne montre aucune tuile : la charge n en invente pas.');

        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Rocktal, (int)Date::now()->timestamp);

        $this->get(route('overview.index'))->assertSee('id="population_box"', false)->assertSee('id="food_box"', false);

        // La population de depart egale l espace vital de depart (150) : montant et plafond coincideraient, et un
        // essai ne verrait pas l un pris pour l autre. On la pose sous le plafond, apres le passage de la page qui
        // avance l etat — la route du bandeau, elle, ne fait que lire.
        // De la nourriture en reserve, sinon la croissance vaut zero (pas de ferme, bilan negatif) et une pente
        // donnee par heure au lieu de par seconde passerait inapercue.
        LifeformPlanet::query()->where('planet_id', $this->planetService->getPlanetId())->update(['population' => 120, 'food' => 1000]);

        $page = $this->get(route('overview.index'));
        $charge = $this->getJson('/ajax/resourcebox')->assertStatus(200)->json();
        $this->assertIsArray($charge['resources']);
        foreach (['population', 'food'] as $nom) {
            $this->assertArrayHasKey($nom, $charge['resources'], $nom . ' manque a la charge que le compteur du jeu lit.');
            $this->assertSame(['amount', 'storage', 'baseProduction', 'production', 'tooltip', 'classesListItem'], array_keys($charge['resources'][$nom]), $nom);
            $this->assertNotSame('', $charge['resources'][$nom]['tooltip'], $nom . ' : le compteur remplace l infobulle du rendu par celle-ci.');
        }

        // Champ par champ contre les chiffres de la planete : un montant, un plafond et une pente justes, pas
        // seulement des clefs presentes. Montant et plafond different a coup sur (population de depart sous
        // l espace vital), la pente de la population est la croissance, celle de la nourriture le bilan.
        $player = resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);
        $chiffres = resolve(LifeformBanner::class)->figuresOf($player, $player->planets->current());
        $this->assertIsArray($chiffres);
        $population = $charge['resources']['population'];
        $this->assertSame((int)floor($chiffres['population']), $population['amount']);
        $this->assertSame($chiffres['living_space'], $population['storage']);
        $this->assertNotSame($population['amount'], $population['storage'], 'Montant et plafond doivent se distinguer, sinon l essai ne voit pas une inversion.');
        $this->assertGreaterThan(0.0, $chiffres['growth_hour'], 'Premisse : une croissance non nulle, sinon la pente ne distingue rien.');
        $this->assertEqualsWithDelta($chiffres['growth_hour'] / 3600, $population['production'], 1e-9);
        $nourriture = $charge['resources']['food'];
        $this->assertSame((int)floor($chiffres['food']), $nourriture['amount']);
        $this->assertSame((int)floor($chiffres['food_storage']), $nourriture['storage']);
        $this->assertEqualsWithDelta($chiffres['food_balance_hour'] / 3600, $nourriture['production'], 1e-9);

        // L infobulle garde toutes les lignes que le gabarit ecrivait lui-meme avant d etre compose ici.
        foreach (['available', 'tier2', 'tier3', 'living_space', 'satisfied', 'hungry', 'growth', 'sheltered'] as $ligne) {
            $this->assertStringContainsString('<th>' . e(__('t_lifeforms_ui.banner.' . $ligne)) . '</th>', $population['tooltip'], 'population : ligne ' . $ligne);
        }
        foreach (['available', 'storage', 'production', 'consumption', 'consumed_in'] as $ligne) {
            $this->assertStringContainsString('<th>' . e(__('t_lifeforms_ui.banner.' . $ligne)) . '</th>', $nourriture['tooltip'], 'nourriture : ligne ' . $ligne);
        }

        // Le rendu et la synchronisation disent la meme infobulle : sinon le bandeau saute a la premiere veille.
        $page->assertSee('title="' . e($charge['resources']['population']['tooltip']) . '"', false);

        // La tuile suit la regle que `ResourceTicker` repose a chaque battement : sous le plafond, aucune marque.
        $page->assertSee('id="resources_population" data-raw="120" class="">120<', false);

        // Et elle abrege comme les autres tuiles (« 1.235Mn », meme formateur) : le compteur la reecrit ainsi des le premier
        // battement, un nombre entier au rendu la faisait changer de forme sous les yeux du joueur.
        LifeformPlanet::query()->where('planet_id', $this->planetService->getPlanetId())->update(['population' => 1234567]);
        $this->get(route('overview.index'))->assertSee('id="resources_population" data-raw="1234567" class="overmark">1.235Mn<', false);

        // Un compte deja engage garde son espece quand l interrupteur se referme : l interrupteur ferme les
        // entrees, il n efface pas l acquis — le bandeau et sa resynchronisation gardent leurs deux tuiles.
        $this->pinSettings(['lifeforms_enabled' => 0]);
        $this->get(route('overview.index'))->assertSee('id="population_box"', false);
        $ferme = $this->getJson('/ajax/resourcebox')->assertStatus(200)->json();
        $this->assertIsArray($ferme['resources']);
        $this->assertArrayHasKey('population', $ferme['resources'], 'Interrupteur ferme, compte engage : la charge garde la population.');
        $this->assertArrayHasKey('food', $ferme['resources']);
    }

    /** La cible du bouton principal « Formes de vie » du menu de gauche. */
    private static function menuButtonTargetOf(string $html): string
    {
        $menu = substr($html, (int)strpos($html, 'id="menu-lifeforms"'));
        self::assertSame(1, preg_match('#<a class="menubutton[^"]*"\s+href="([^"]+)"#', $menu, $m), 'Le bouton principal du menu est absent.');

        return $m[1] ?? '';
    }

    /**
     * **Aucune entite HTML dans un bloc de script.** Le navigateur ne decode pas `&quot;` ni `&#039;` dans un
     * `<script>` : une seule suffit a casser tout le bloc, en silence pour un essai qui lit le HTML. La forme de code
     * fautive est `{{ json_encode(...) }}` ou `{{ $texte }}` dans un script ; la juste est `@json(...)`.
     */
    public static function assertScriptsCarryNoHtmlEntity(string $html): void
    {
        self::assertGreaterThan(0, preg_match_all('#<script\b[^>]*>(.*?)</script>#s', $html, $scripts), 'La page porte des scripts.');
        foreach ($scripts[1] as $i => $script) {
            // Les guillemets et apostrophes echappes : ce que `{{ }}` produit et qui ferme une chaine JavaScript. Un
            // `&amp;` dans une chaine HTML portee par un script (vue generale) est, lui, legitime.
            foreach (['&quot;', '&#039;', '&#39;'] as $entite) {
                self::assertStringNotContainsString($entite, $script, "Le bloc de script nº$i porte l entite $entite : du HTML dans du JavaScript.");
            }
        }
    }

    /**
     * L identifiant du parent de la bande `.description` d un panneau de detail, lu dans le DOM — pas dans une
     * suite d octets : c est la place dans l arbre que la feuille de style habille.
     */
    public static function parentIdOfTheDescriptionBand(string $fragment): string
    {
        $dom = new DOMDocument();
        $ok = $dom->loadHTML('<?xml encoding="utf-8" ?><div id="racine">' . $fragment . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
        self::assertTrue($ok, 'Le fragment ne se lit pas comme du HTML.');
        $bandes = (new DOMXPath($dom))->query('//div[@id="technologydetails"]//div[contains(concat(" ", normalize-space(@class), " "), " description ")]');
        self::assertNotFalse($bandes);
        self::assertSame(1, $bandes->length, 'Le panneau porte exactement une bande de description.');
        $bande = $bandes->item(0);
        self::assertInstanceOf(DOMElement::class, $bande);
        $parent = $bande->parentNode;
        self::assertInstanceOf(DOMElement::class, $parent);

        return $parent->getAttribute('id');
    }
}
