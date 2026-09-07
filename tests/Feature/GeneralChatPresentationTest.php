<?php

namespace Tests\Feature;

use OGame\Chat\PresentedAuthor;
use OGame\Events\ChatMessageSent;
use OGame\Models\ChatMessage;
use OGame\Models\User;
use OGame\Services\AllianceService;
use OGame\Services\ChatService;
use Tests\AccountTestCase;

/**
 * Ce que le joueur lit dans la salle generale : un nom, et les marques du classement.
 *
 * ## Le defaut que ces temoins ferment d'avance
 *
 * Le chat general a **trois chemins vers l'ecran** : l'historique, la diffusion, et la reponse a
 * son propre envoi — l'auteur ne recoit pas sa propre diffusion, `toOthers()` l'exclut. Si les
 * trois composaient les marques chacun de leur cote, le meme joueur s'afficherait tantot avec son
 * tag et son honneur, tantot sans.
 *
 * Ce depot a deja paye ce defaut ailleurs : un nom d'unite arrivait en anglais en direct et en
 * francais au rechargement, faute d'un composeur unique. `PresentedAuthor` est ce composeur, et
 * ces temoins verifient que **les trois chemins l'emploient**.
 */
class GeneralChatPresentationTest extends AccountTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ChatMessage::query()->forceDelete();
    }

    /**
     * Les marques sont exactement celles que le classement affiche.
     */
    public function testTheBadgesAreTheOnesTheRankingShows(): void
    {
        $tag = strtoupper(substr('G' . dechex(random_int(0x100000, 0xffffff)), 0, 7));
        app(AllianceService::class)->createAlliance($this->currentUserId, $tag, 'Alliance ' . $tag);

        $joueur = User::query()->findOrFail($this->currentUserId);
        $joueur->honor_points = -42;
        $joueur->save();

        $marques = PresentedAuthor::of(User::query()->findOrFail($this->currentUserId))->forTheBrowser();

        $this->assertSame($this->currentUserId, $marques['id']);
        $this->assertSame($joueur->username, $marques['name']);
        $this->assertSame($tag, $marques['allianceTag'], 'The alliance tag is missing: the ranking shows one.');
        $this->assertIsInt($marques['allianceId']);
        $this->assertFalse($marques['isAdmin']);
        $this->assertSame(-42, $marques['honorPoints'], 'The honour total is not carried: the ranking shows it.');
    }

    /**
     * Un joueur sans alliance n'invente pas de tag.
     */
    public function testAPlayerWithNoAllianceCarriesNoTag(): void
    {
        $marques = PresentedAuthor::of(User::query()->findOrFail($this->currentUserId))->forTheBrowser();

        $this->assertNull($marques['allianceTag']);
        $this->assertNull($marques['allianceId']);
    }

    /**
     * Les trois chemins portent les memes marques, composees au meme endroit.
     *
     * **C'est le temoin qui compte.** Il ne compare pas trois chaines : il exige que les trois
     * charges utiles soient identiques champ par champ, ce qu'un composeur unique garantit et que
     * trois lectures separees perdraient a la premiere divergence.
     */
    public function testTheThreePathsCarryTheVerySameBadges(): void
    {
        $envoi = $this->post('/chat/send', ['mode' => 5, 'text' => 'Trois chemins']);
        $envoi->assertJsonPath('status', 'OK');

        $parLEnvoi = $envoi->json('author');

        $message = ChatMessage::query()->whereNull('recipient_id')->whereNull('alliance_id')->latest('id')->firstOrFail();
        $parLaDiffusion = (new ChatMessageSent($message))->broadcastWith()['author'] ?? null;

        $historique = $this->post('/chat/history', ['mode' => 6]);
        $items = (array)$historique->json('chatItems');
        $dernier = end($items);
        $parLHistorique = $dernier['author'] ?? null;

        $this->assertIsArray($parLEnvoi, 'The send response carries no author: the sender would be the only line with no badges.');
        $this->assertIsArray($parLaDiffusion, 'The broadcast carries no author: live lines would be bare.');
        $this->assertIsArray($parLHistorique, 'The history carries no author: reloading would strip every badge.');

        $this->assertSame($parLEnvoi, $parLaDiffusion, 'The send response and the broadcast disagree on the very same author.');
        $this->assertSame($parLEnvoi, $parLHistorique, 'The send response and the history disagree on the very same author.');
    }

    /**
     * La page porte la salle, et ce que son module doit lire.
     */
    public function testThePageCarriesTheRoomAndWhatItsModuleReads(): void
    {
        $reponse = $this->get('/chat');

        $reponse->assertStatus(200);
        $reponse->assertSee('id="generalChatList"', false);
        $reponse->assertSee('id="generalChatText"', false);
        $reponse->assertSee('id="generalChatSend"', false);
        $reponse->assertSee('var generalChatIgnoredIds =', false);
        $reponse->assertSee('var generalChatLoca =', false);
    }

    /**
     * Plus une phrase anglaise en dur sur cette page.
     *
     * Douze y vivaient, hors de tout fichier de langue : rien ne pouvait les signaler, et un joueur
     * francais lisait « List of your chats » sur un serveur francais.
     */
    public function testThePageSpeaksThroughItsLanguageFiles(): void
    {
        $gabarit = (string)file_get_contents(base_path('resources/views/ingame/chat/index.blade.php'));

        foreach (['List of your chats', 'Player list', '>Buddies', '>Strangers', '>Submit<', '>No buddies<'] as $anglaise) {
            $this->assertStringNotContainsString(
                $anglaise,
                $gabarit,
                'A hardcoded English string is back on the chat page: no language file can reach it.'
            );
        }

        $reponse = $this->get('/chat');
        $reponse->assertDontSee('t_ingame.chat.', false);
    }

    /**
     * Le module est dans le bundle, et il attend la page.
     *
     * Meme famille de defaut que `combat.js` : lu au chargement de l'en-tete, il ne trouverait ni
     * la liste, ni `playerId`.
     */
    public function testTheModuleIsBundledAndWaitsForThePage(): void
    {
        $vite = (string)file_get_contents(base_path('vite.config.js'));

        $this->assertStringContainsString(
            "'resources/js/ingame/chat-general.js'",
            $vite,
            'The general chat module is not in the bundle: it never reaches a browser.'
        );

        $source = (string)file_get_contents(base_path('resources/js/ingame/chat-general.js'));

        $this->assertStringContainsString(
            "document.addEventListener('DOMContentLoaded', demarrer)",
            $source,
            'The module runs at bundle time, before the page exists.'
        );

        $this->assertStringContainsString(
            ".listen('.ChatMessageSent'",
            $source,
            'The listener has no leading dot: the library would prefix its default namespace.'
        );
    }

    /**
     * Le panneau emploie les classes existantes, sans direction graphique nouvelle.
     *
     * Decision de Keven : le general reprend le style actuel. Ces classes sont celles des
     * conversations (`chat_msg`, `msg_head`, `msg_content`) et celles du classement (`ally-tag`,
     * `playername`, `badgeAdmin`, `honorScore`).
     */
    public function testTheRoomBorrowsTheStyleAlreadyInPlace(): void
    {
        $module = (string)file_get_contents(base_path('resources/js/ingame/chat-general.js'));

        foreach (['chat_msg', 'msg_head', 'msg_content', 'ally-tag', 'playername', 'badgeAdmin', 'honorScore'] as $classe) {
            $this->assertStringContainsString(
                $classe,
                $module,
                'The room stopped using the class ' . $classe . ': it would drift away from the style already in place.'
            );
        }

        // Ces deux-la n'existent que sous #highscoreContent ; sans reprise, elles ne styleraient rien.
        $feuille = (string)file_get_contents(base_path('resources/css/ingame/azria.css'));

        $this->assertStringContainsString('#generalChat .ally-tag', $feuille);
        $this->assertStringContainsString('#generalChat .honorScore', $feuille);
    }

    /**
     * Ce que l'auteur poste lui revient decore, alors qu'il ne recoit pas sa propre diffusion.
     */
    public function testTheSenderSeesItsOwnBadgesToo(): void
    {
        $service = app(ChatService::class);
        $service->sendGeneralMessage($this->currentUserId, 'Un mot');

        $envoi = $this->post('/chat/send', ['mode' => 5, 'text' => 'Un autre mot']);

        $envoi->assertJsonPath('author.id', $this->currentUserId);
        $envoi->assertJsonPath('author.name', User::query()->findOrFail($this->currentUserId)->username);
    }
}
