<?php

namespace Tests\Feature\Lifeforms;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Lang;
use OGame\Enums\HighscoreTypeEnum;
use OGame\Facades\AppUtil;
use OGame\Factories\PlayerServiceFactory;
use OGame\Lifeforms\Catalogue\LifeformCatalogue;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\Services\LifeformLevels;
use OGame\Lifeforms\Species;
use OGame\Models\Highscore;
use OGame\Services\HighscoreService;
use Tests\AccountTestCase;

/**
 * **Le classement n a plus trois onglets, il a un detail** (decision de Keven, 20 septembre 2026).
 *
 * Les trois boutons violets `Lifeform`, `Lifeform Economy` et `Lifeform Technology` quittent la barre de
 * categories : elle reste celle du jeu officiel. Ce qui reste, et qui ne bouge pas :
 *
 * - les points des formes de vie entrent dans le score **General**, comptes exactement une fois ;
 * - les colonnes, les rangs et les calculs restent en place — c est l interface qui se simplifie, pas la mesure ;
 * - l Economie et la Recherche classiques ne changent pas, et cette repartition reste une question a part.
 *
 * A la place, le score General porte un detail consultable : « Dont formes de vie : X points », ventile en
 * batiments et technologies. **Il se lit sur la meme photographie que le total affiche** — les trois nombres
 * viennent du meme enregistrement de `highscores` —, donc le detail ne peut pas contredire le total.
 */
final class LifeformHighscoreDetailTest extends AccountTestCase
{
    /**
     * Une photographie de classement pour le compte du banc, avec ses rangs, telle que la tache planifiee l ecrit.
     *
     * `GenerateHighscores` fait exactement cela : `Highscore::updateOrCreate(..., getPlayerScores(...))`. On passe
     * par la meme porte pour que le banc ne mesure pas un chemin que le jeu n emprunte pas.
     */
    private function photographier(): Highscore
    {
        $joueur = resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);
        $scores = resolve(HighscoreService::class)->getPlayerScores($joueur);

        $ligne = Highscore::updateOrCreate(['player_id' => $this->currentUserId], $scores);
        // `validRanks()` ecarte toute ligne sans les quatre rangs classiques : sans eux, ma ligne n existerait
        // simplement pas dans la liste, et l essai passerait en ne regardant rien.
        $ligne->forceFill([
            'general_rank' => 1,
            'economy_rank' => 1,
            'military_rank' => 1,
            'research_rank' => 1,
        ])->save();

        // La liste est mise en cache cinq minutes : sans cet oubli, on relirait la liste d avant.
        Cache::flush();

        return $ligne->refresh();
    }

    /**
     * Ma ligne dans la liste rendue par le service, cherchee par identifiant — jamais prise en tete.
     *
     * @return array<string, mixed>
     */
    private function maLigneDansLaListe(): array
    {
        $service = resolve(HighscoreService::class);
        $service->setHighscoreType(HighscoreTypeEnum::general->value);

        $pages = (int)ceil(max(1, $service->getHighscorePlayerAmount()) / 100);
        for ($page = 1; $page <= $pages; $page++) {
            foreach ($service->getHighscorePlayers(100, $page) as $ligne) {
                if (($ligne['id'] ?? null) === $this->currentUserId) {
                    return $ligne;
                }
            }
        }

        $this->fail('Ma ligne est absente du classement general : l essai ne peut rien prouver.');
    }

    private function poserDesFormesDeVie(): void
    {
        $niveaux = resolve(LifeformLevels::class);
        $corps = $this->planetService->getPlanetId();

        // Le bouclier planetaire (500 000 de base) et une technologie humaine : des couts assez grands pour que
        // plusieurs niveaux fassent plus d un point, sinon « avec » et « sans » se confondraient.
        $niveaux->setLevel($corps, LifeformKind::Building, LifeformCatalogue::byId(11112)->id, 4);
        $niveaux->setLevel($corps, LifeformKind::Technology, LifeformCatalogue::technologiesOf(Species::Humans)[0]->id, 6);
    }

    public function testTheThreeLifeformCategoryButtonsAreGoneFromTheRankingBar(): void
    {
        $page = (string)$this->get(route('highscore.index'))->assertStatus(200)->getContent();

        // La forme de code, pas le mot : `rel="8"` avec ses guillemets ne peut etre qu un bouton de categorie.
        foreach (['rel="8"', 'rel="9"', 'rel="10"'] as $marque) {
            $this->assertStringNotContainsString($marque, $page, "La barre de categories offre encore $marque.");
        }
        foreach (['id="lifeform"', 'id="lifeform_economy"', 'id="lifeform_technology"'] as $marque) {
            $this->assertStringNotContainsString($marque, $page, "Le bouton $marque est encore dans la page.");
        }

        // Premisse : les six categories officielles, elles, sont toujours la — sinon ce temoin passerait sur une
        // page vide.
        $this->assertStringContainsString('rel="0"', $page, 'Le bouton General a disparu : la page n est pas celle qu on croit.');
        $this->assertStringContainsString('rel="3"', $page, 'Le bouton Militaire a disparu.');
    }

    public function testTheGeneralScoreCarriesTheLifeformBreakdownTakenFromTheSameRecord(): void
    {
        $this->poserDesFormesDeVie();
        $ligneEnBase = $this->photographier();

        $this->assertGreaterThan(0, (int)$ligneEnBase->lifeform, 'Premisse : le compte doit avoir des points de formes de vie.');

        $ligne = $this->maLigneDansLaListe();

        $this->assertSame((int)$ligneEnBase->lifeform, $ligne['lifeform_points'], 'Le total des formes de vie ne vient pas de la photographie.');
        $this->assertSame((int)$ligneEnBase->lifeform_economy, $ligne['lifeform_economy_points'], 'La part batiments ne vient pas de la photographie.');
        $this->assertSame((int)$ligneEnBase->lifeform_technology, $ligne['lifeform_technology_points'], 'La part technologies ne vient pas de la photographie.');

        // **Ventile veut dire que les deux parts font le total**, et non deux nombres poses a cote.
        $this->assertSame(
            $ligne['lifeform_points'],
            $ligne['lifeform_economy_points'] + $ligne['lifeform_technology_points'],
            'La ventilation ne reconstitue pas le total : le detail contredirait le score.'
        );

        // **Ce qui n est PAS ecrit ici, et pourquoi.** J avais pose « general = economie + recherche + militaire
        // + formes de vie » comme temoin. C est faux : une defense vaut 100 % dans l Economie ET 100 % dans le
        // Militaire, donc 200 % dans la somme contre 100 % au general. L essai passait parce que ce compte-ci ne
        // porte aucune defense — le juste et le faux coincidaient. Le vrai invariant, mesure avec transporteurs,
        // satellites, foreuses, vaisseaux militaires et defenses, vit dans `HighscoreCategoryOverlapTest` :
        // le general compte chaque investissement une fois, et l ajout des formes de vie ne touche a aucun
        // total classique.
    }

    public function testTheGeneralRankingShowsTheBreakdownAndTheOtherRankingsDoNot(): void
    {
        $this->poserDesFormesDeVie();
        $this->photographier();
        $ligne = $this->maLigneDansLaListe();

        $attendu = __('t_ingame.highscore.lifeform_share', [
            'points' => \OGame\Facades\AppUtil::formatNumber($ligne['lifeform_points']),
        ]);

        $general = (string)$this->post(route('highscore.ajax', ['category' => 1, 'type' => 0]))->assertStatus(200)->getContent();
        $this->assertStringContainsString(e($attendu), $general, 'Le score general ne porte pas le detail des formes de vie.');
        $this->assertStringContainsString(e(__('t_ingame.highscore.lifeform_economy')), $general, 'La part batiments n est pas nommee.');
        $this->assertStringContainsString(e(__('t_ingame.highscore.lifeform_technology')), $general, 'La part technologies n est pas nommee.');

        // Les autres classements gardent leur cellule telle quelle : le detail appartient au General seul.
        foreach ([1, 2, 3] as $type) {
            $autre = (string)$this->post(route('highscore.ajax', ['category' => 1, 'type' => $type]))->assertStatus(200)->getContent();
            $this->assertStringNotContainsString(e($attendu), $autre, "Le classement de type $type porte un detail qui n y a rien a faire.");
        }
    }

    /**
     * **Le detail ne depend pas du survol** (exigence de Keven, 20 septembre 2026).
     *
     * L infobulle du jeu ne s ouvre qu au `mouseenter` : ni le clavier ni le tactile n y ont acces, et
     * `js_hideTipOnMobile` — que j y avais mis — **vide le titre** sur telephone. Deux garanties donc :
     * le declencheur est atteignable au clavier, et le texte vit **dans la page**, relie par
     * `aria-describedby`, lisible sans aucune bibliotheque.
     */
    public function testTheBreakdownIsReachableWithoutHovering(): void
    {
        $this->poserDesFormesDeVie();
        $this->photographier();
        $ligne = $this->maLigneDansLaListe();

        $general = (string)$this->post(route('highscore.ajax', ['category' => 1, 'type' => 0]))->assertStatus(200)->getContent();
        $cellule = $this->celluleDuScoreDe($general, $this->currentUserId);

        // 1. Le declencheur est atteignable au clavier et s ouvre autrement qu au survol.
        $this->assertStringContainsString('tabindex="0"', $cellule, 'Le score n est pas atteignable au clavier.');
        $this->assertStringContainsString('tooltipFocusable', $cellule, 'L infobulle ne s ouvre qu au survol.');

        // 2. Et il n est PAS marque comme a cacher sur mobile — ce marquage vide le titre.
        $this->assertStringNotContainsString('js_hideTipOnMobile', $cellule, 'Le detail disparait sur telephone.');

        // 3. Le texte vit dans la page, relie au score, et porte les trois nombres.
        $identifiant = 'lifeformShare-' . $this->currentUserId;
        $this->assertStringContainsString('aria-describedby="' . $identifiant . '"', $cellule, 'Le score ne designe aucun texte.');
        $this->assertSame(1, substr_count($general, 'id="' . $identifiant . '"'), 'Le texte designe manque, ou se repete.');

        $decrit = $this->contenuDeLElement($general, $identifiant);
        foreach ([
            AppUtil::formatNumber($ligne['lifeform_points']),
            AppUtil::formatNumber($ligne['lifeform_economy_points']),
            AppUtil::formatNumber($ligne['lifeform_technology_points']),
        ] as $nombre) {
            $this->assertStringContainsString($nombre, $decrit, 'Le texte lu sans survol ne porte pas ' . $nombre . '.');
        }

        // 4. Il est **hors de l ecran**, pas `display: none` : un element masque ainsi n est pas restitue.
        $this->assertStringContainsString('class="ui-helper-hidden-accessible"', $general);
        $this->assertStringNotContainsString('id="' . $identifiant . '" style="display', $general, 'Le texte est masque au lieu d etre deporte.');
    }

    /**
     * **Le declencheur du focus vit dans la definition qui gagne**, et pas dans celle qui est masquee.
     *
     * Piege paye : `resources/js/ingame/tooltips.js` definit `initTooltips` et `getTooltipOptions`, mais le gros
     * fichier herite `e7c74974620fa35b197315ebdbb8c2.js` les **redefinit** et vient apres lui dans le bundle. La
     * derniere definition gagne : une premiere version du correctif, posee dans `tooltips.js`, n avait aucun
     * effet sur la page servie — mesure au navigateur, le focus n ouvrait rien.
     *
     * Ce temoin lit le bundle **reellement servi** et exige que la delegation du focus soit **apres** la
     * derniere definition d `initTooltips`. Un correctif repose dans le fichier masque le ferait tomber.
     */
    public function testTheFocusTriggerLivesInTheDefinitionThatWins(): void
    {
        $manifeste = json_decode((string)file_get_contents(public_path('build/manifest.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($manifeste);
        $chemin = public_path('build/' . $manifeste['resources/js/ingame.js']['file']);
        $this->assertFileExists($chemin, 'Le bundle nomme par le manifeste n existe pas.');
        $bundle = (string)file_get_contents($chemin);

        // Premisse de la lecon : il y a bien PLUSIEURS definitions, donc une qui masque l autre.
        $definitions = [];
        $position = 0;
        while (($position = strpos($bundle, 'function initTooltips(', $position)) !== false) {
            $definitions[] = $position;
            $position++;
        }
        $this->assertGreaterThan(1, count($definitions), 'Une seule definition : la lecon de ce temoin ne s applique plus, le relire.');

        // **La forme qui LIE, pas le mot.** `focusin.tooltipFocus` apparait aussi dans l `undelegate` qui
        // precede : le chercher seul laissait passer une delegation cassee — mutation survivante, corrigee.
        // On exige l appel complet, avec son selecteur.
        $liaison = ".delegate('.tooltipFocusable', 'focusin.tooltipFocus";
        $delegation = strpos($bundle, $liaison);
        $this->assertNotFalse($delegation, 'Le bundle servi n ouvre l infobulle qu au survol.');
        $this->assertGreaterThan(
            (int)end($definitions),
            $delegation,
            'La delegation du focus est posee AVANT la derniere definition d initTooltips : elle est masquee, '
            . 'et la page servie ne la verra jamais.'
        );

        // Elle est bornee a la classe — aucune autre infobulle du jeu ne change de comportement — et posee
        // une seule fois : `initTooltips()` est rappele apres chaque fragment ajax.
        $this->assertSame(1, substr_count($bundle, $liaison), 'La delegation manque, ou se repete.');
    }

    /**
     * La cellule de score de MA ligne, jamais celle de la premiere ligne venue.
     */
    private function celluleDuScoreDe(string $html, int $joueur): string
    {
        $document = new DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>');
        libxml_clear_errors();
        $chemin = new DOMXPath($document);

        $cellules = $chemin->query('//tr[@id="position' . $joueur . '"]//td[@class="score"]');
        $this->assertNotFalse($cellules);
        $this->assertSame(1, $cellules->length, 'Ma ligne, et sa cellule de score, doivent exister une fois.');
        $cellule = $cellules->item(0);
        $this->assertInstanceOf(DOMElement::class, $cellule);

        return (string)$document->saveHTML($cellule);
    }

    private function contenuDeLElement(string $html, string $identifiant): string
    {
        $document = new DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>');
        libxml_clear_errors();
        $element = $document->getElementById($identifiant);
        $this->assertInstanceOf(DOMElement::class, $element, 'Aucun element ne porte cet identifiant.');

        return (string)$element->textContent;
    }

    public function testAPlayerWithoutLifeformsGetsNoBreakdownAtAll(): void
    {
        $ligneEnBase = $this->photographier();
        $this->assertSame(0, (int)$ligneEnBase->lifeform, 'Premisse : ce compte n a aucune forme de vie.');

        $general = (string)$this->post(route('highscore.ajax', ['category' => 1, 'type' => 0]))->assertStatus(200)->getContent();

        $vide = __('t_ingame.highscore.lifeform_share', ['points' => \OGame\Facades\AppUtil::formatNumber(0)]);
        $this->assertStringNotContainsString(e($vide), $general, 'Une infobulle a zero s affiche sur des lignes qui n ont rien a dire.');
    }

    public function testThePhraseExistsInEveryLanguage(): void
    {
        foreach (['fr', 'en', 'it', 'nl', 'zh-TW'] as $langue) {
            // **Le repli est silencieux** : une clef absente d une langue rend l anglais, jamais la clef brute.
            // `Lang::has(..., false)` interdit ce repli et voit donc le manque.
            $this->assertTrue(
                Lang::has('t_ingame.highscore.lifeform_share', $langue, false),
                "La phrase du detail manque en $langue : le joueur lirait l anglais sans que rien ne le signale."
            );
        }
    }
}
