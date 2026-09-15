<?php

namespace Tests\Feature;

use OGame\Factories\PlayerServiceFactory;
use Tests\AccountTestCase;

/**
 * Ce que la page de classement fait au chargement, tel que ses gabarits l ecrivent.
 *
 * ## Le defaut du 15 septembre 2026
 *
 * Le script herite `initHighscore()` pose ses ecouteurs de clic sur les boutons de type et de categorie sans retirer
 * les precedents, et chaque fragment charge le rappelait : apres k chargements, un clic declenchait k + 1 chargements,
 * chacun appelant deux fois `initHighscoreContent()` (le script du fragment, puis le rappel du chargeur), et chaque
 * appel animait le defilement vers ma ligne pendant une seconde. Le joueur voyait la page « revenir toujours au meme
 * endroit » pendant plusieurs secondes. Les gabarits posent desormais un seul jeu d ecouteurs, une seule
 * initialisation par fragment, et ne recentrent qu au premier chargement ou sur « Ma position ».
 *
 * Ces temoins lisent la **forme** des gabarits : le comportement lui-meme vit dans le navigateur. Ils disent ce que
 * la page envoie, pas ce que le script en fait.
 */
final class HighscorePageBehaviourTest extends AccountTestCase
{
    public function testTheFragmentInitialisesOnceAndFocusesOnTheFirstLoadOnly(): void
    {
        $page = (string)$this->get('/highscore')->assertStatus(200)->getContent();

        $this->assertStringContainsString('var userWantsFocus = true;', $page, 'La page n ouvre pas sur ma ligne.');
        $this->assertStringContainsString('if (!window.highscoreInitialised) {', $page, 'Le fragment repose ses ecouteurs a chaque chargement.');
        $this->assertStringContainsString('window.highscoreContentInitialised = false;', $page, 'Le fragment ne se declare pas neuf.');

        $this->assertSame(1, preg_match('/initHighscoreContent\(\);\s*userWantsFocus = false;/', $page), 'Le recentrage n est pas eteint juste apres la premiere initialisation : chaque chargement ramenerait la page a ma ligne.');
        $this->assertSame(1, substr_count($page, 'initHighscore();'), 'Le fragment appelle initHighscore() ailleurs que sous sa garde.');
    }

    public function testTheSearchedPlayerIsTheOneTheRankingFocuses(): void
    {
        $autre = $this->getSecondPlayerId();

        $cherche = (string)$this->get('/highscore?searchRelId=' . $autre)->assertStatus(200)->getContent();
        $this->assertStringContainsString('var searchPosition = ' . $autre . ';', $cherche, 'La page recentre sur moi au lieu du joueur cherche.');
        $this->assertStringContainsString('var searchRelId = ' . $autre . ';', $cherche, 'Changer de type perdrait le joueur cherche.');

        $moi = (string)$this->get('/highscore')->assertStatus(200)->getContent();
        $this->assertStringContainsString('var searchPosition = ' . $this->currentUserId . ';', $moi, 'Sans recherche, la page ne recentre pas sur moi.');
    }

    public function testTheHeadlineNamesTheRankingShown(): void
    {
        foreach ([0 => 't_ingame.highscore.points', 1 => 't_ingame.highscore.economy', 2 => 't_ingame.highscore.research', 3 => 't_ingame.highscore.military'] as $type => $clef) {
            $fragment = (string)$this->post(route('highscore.ajax', ['category' => 1, 'type' => $type]))->assertStatus(200)->getContent();
            $titre = $this->headlineOf($fragment);
            $this->assertSame(__($clef), $titre, "Le titre du classement des joueurs de type $type ne nomme pas ce classement.");
        }
    }

    public function testTheLastPageLinkNeverPointsPastTheLastPage(): void
    {
        $joueur = resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);
        $rendu = fn (int $page, int $joueurs): string => view('ingame.highscore.players_points', [
            'highscorePlayers' => [],
            'highscorePlayerAmount' => $joueurs,
            'highscoreCurrentPlayerRank' => 1,
            'highscoreCurrentPlayerPage' => 1,
            'highscoreCurrentPage' => $page,
            'highscoreCurrentType' => 0,
            'player' => $joueur,
            'highscoreAdminVisible' => true,
            'currentPlayerIsAdmin' => false,
            'militaryTallyNote' => null,
            'highscoreFocusPlayerId' => $this->currentUserId,
        ])->render();

        // Trois cents joueurs : trois pages, pas quatre.
        $deuxieme = $rendu(2, 300);
        $this->assertStringContainsString('page=3', $deuxieme, 'La derniere page n est pas offerte depuis la deuxieme.');
        $this->assertStringNotContainsString('page=4', $deuxieme, 'Le lien de derniere page vise une page vide.');
        // Deux barres de pages, une en haut et une en bas : deux liens, jamais plus.
        $this->assertSame(2, substr_count($deuxieme, '>»<'), 'Le lien « » » manque ou se repete au-dela des deux barres.');

        $troisieme = $rendu(3, 300);
        $this->assertStringNotContainsString('>»<', $troisieme, 'La derniere page offre encore un lien vers une page suivante.');

        // Deux cent cinquante joueurs : trois pages ; depuis la deuxieme, la derniere doit etre offerte.
        $this->assertStringContainsString('page=3', $rendu(2, 250), 'La derniere page n est pas offerte quand le compte n est pas rond.');
    }

    private function headlineOf(string $fragment): string
    {
        $this->assertSame(1, preg_match('/<div class="fleft" id="highscoreHeadline">(.*?)<\/div>/s', $fragment, $m), 'Le titre du classement manque.');

        return trim((string)($m[1] ?? ''));
    }
}
