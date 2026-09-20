<?php

namespace Tests\Feature\Lifeforms;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Lang;
use OGame\Enums\HighscoreTypeEnum;
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

        // Et ce total est bien DANS le general, compte une fois : le general vaut la somme des categories.
        $this->assertSame(
            (int)$ligneEnBase->general,
            (int)$ligneEnBase->economy + (int)$ligneEnBase->research + (int)$ligneEnBase->military + (int)$ligneEnBase->lifeform,
            'Le general n est plus la somme des categories, formes de vie comprises.'
        );
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
