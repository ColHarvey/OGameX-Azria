<?php

namespace Tests\Feature;

use OGame\Chat\ChatEmojiPalette;
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
     * La salle ne partage aucun point d'accroche JavaScript avec le reste de la page.
     *
     * ## Le defaut, vu en jeu
     *
     * Ma zone de saisie portait `new_msg_textarea` — pour le style, croyais-je. Cette classe
     * **n'habille rien** : c'est un point d'accroche vise sans portee par une dizaine de scripts.
     *
     * Deux consequences sur la page de chat, toutes deux constatees a l'ecran. L'editeur BBCode
     * s'accrochait a la salle des qu'une conversation etait ouverte : barre d'outils, compteur de
     * caracteres et bouton d'apercu au milieu du panneau. Et surtout `$('.new_msg_textarea').val()`
     * rend le **premier** element du lot — la salle, plus haut dans le document — donc l'envoi d'un
     * message prive lisait une zone vide.
     *
     * Deux verrous : la classe est retiree, et l'editeur est limite a sa propre conversation.
     */
    public function testTheRoomSharesNoJavascriptHookWithTheRestOfThePage(): void
    {
        $reponse = $this->get('/chat');
        $rendu = (string)$reponse->getContent();

        $this->assertMatchesRegularExpression(
            '/<textarea[^>]*id="generalChatText"(?![^>]*new_msg_textarea)[^>]*>/',
            $rendu,
            'The room reuses new_msg_textarea: unscoped scripts would read it instead of the open conversation.'
        );

        $page = (string)file_get_contents(base_path('resources/views/ingame/chat/index.blade.php'));

        $this->assertSame(
            0,
            substr_count($page, "initBBCodeEditor(locaKeys, itemNames, false, '.new_msg_textarea'"),
            'The BBCode editor attaches to every textarea on the page, the general chat one included.'
        );
    }

    /**
     * Le compteur de messages non lus porte le jeton que le code remplace.
     *
     * `ogame.messagecounter` fait `loca.replace('#+#', compte)` — une recherche **litterale**.
     * La traduction francaise portait `#++#`, qui ne contient pas cette suite : rien n'etait
     * remplace, et le joueur lisait « #++# message(s) non lu(s) » dans son infobulle. Les quatre
     * autres langues etaient justes, ce qui rendait la faute invisible depuis le code.
     */
    public function testTheUnreadCounterCarriesTheTokenTheCodeReplaces(): void
    {
        $sansJeton = [];

        foreach (['fr', 'en', 'it', 'nl', 'zh-TW'] as $langue) {
            $chemin = base_path('resources/lang/' . $langue . '/t_ingame.php');

            if (!file_exists($chemin)) {
                continue;
            }

            $textes = include $chemin;
            $phrase = $textes['layout']['chat_new_chats'] ?? null;

            if (!is_string($phrase) || !str_contains($phrase, '#+#')) {
                $sansJeton[] = $langue . ' : ' . (is_string($phrase) ? $phrase : 'absente');
            }
        }

        $this->assertSame(
            [],
            $sansJeton,
            'These translations do not carry the literal token the counter replaces, so the raw text is shown: '
            . implode(' | ', $sansJeton)
        );
    }

    /**
     * Les valeurs publiees par la page sont lues quand la page existe.
     *
     * ## Le defaut, vu en jeu
     *
     * Le module est concatene dans un bundle que `@vite` charge **dans l'en-tete** ; les valeurs
     * que la page publie vivent dans le **corps** du document. Lues au chargement du fichier,
     * elles n'existent pas encore.
     *
     * `var loca = ... generalChatLoca ...` s'executait donc sur une variable absente et gardait un
     * objet vide. Les douze libelles qui en dependent devenaient des chaines vides — et le lien de
     * traduction s'affichait **sans texte**, donc invisible. Tout etait juste par ailleurs : le
     * serveur repondait `true`, l'adresse etait bonne, le bundle etait le bon.
     *
     * **Meme famille que le defaut des reglages Reverb** : le code etait juste, seule sa place
     * dans la page etait fausse.
     */
    public function testThePageValuesAreReadOnceThePageExists(): void
    {
        $module = (string)file_get_contents(base_path('resources/js/ingame/chat-general.js'));

        $this->assertStringNotContainsString(
            'var loca = typeof generalChatLoca',
            $module,
            'The labels are read at bundle time, before the page declares them: every label would be empty.'
        );

        $this->assertStringContainsString(
            'loca = generalChatLoca;',
            $module,
            'Nothing fills the labels once the page exists: they would stay empty for good.'
        );

        // Et l'affectation vit bien apres l'attente de la page, pas avant.
        $demarrage = (int)strpos($module, 'function demarrer()');
        $affectation = (int)strpos($module, 'loca = generalChatLoca;');

        $this->assertGreaterThan(
            $demarrage,
            $affectation,
            'The labels are filled outside the start function, which runs only once the page exists.'
        );
    }

    /**
     * Le script d'une conversation ne sort jamais de sa boite.
     *
     * ## Le defaut, et pourquoi il n'etait pas visible
     *
     * La salle generale porte les memes classes que le fil d'une conversation — `chat`,
     * `largeChat`, `largeChatContainer` — pour avoir le meme style, ce qui etait la consigne.
     * Le script du fil, lui, visait `$('ul.chat.largeChat')` **sans portee** : le selecteur
     * designait donc **deux** listes.
     *
     * `append()` ajoute a chacune des correspondances. Un message prive envoye apparaissait donc
     * aussi dans la salle generale, et le sondage a cinq secondes l'y recopiait. La barre de
     * defilement personnalisee du fil s'installait par-dessus celle de la salle.
     *
     * Rien ne le montrait tant qu'aucune conversation n'etait ouverte : c'est la coexistence des
     * deux sur la meme page qui le revele.
     */
    public function testTheThreadScriptNeverReachesOutsideItsOwnBox(): void
    {
        $code = $this->pageDeChatSansCommentaires();

        foreach (["ul.chat.largeChat", '.new_msg_textarea', '.send_new_msg', '.cnt_chars'] as $partage) {
            $this->assertStringNotContainsString(
                "\$('" . $partage . "')",
                $code,
                'The thread targets ' . $partage . ' without scope: the general chat carries the same classes and would be hit too.'
            );
        }

        $this->assertStringContainsString(
            "\$('#chatContent ul.chat.largeChat')",
            $code,
            'The thread no longer reads its own message list at all: this guard would measure nothing.'
        );
    }

    /**
     * Le code de la page de chat, ses commentaires retires.
     *
     * **Un garde qui lit la prose se trompe de cible** : les commentaires citent les ecritures
     * fautives pour les expliquer, et l'essai echouerait sur une explication.
     */
    private function pageDeChatSansCommentaires(): string
    {
        $brut = (string)file_get_contents(base_path('resources/views/ingame/chat/index.blade.php'));
        $lignes = [];

        foreach (explode("
", str_replace("
", "
", $brut)) as $ligne) {
            if (!str_starts_with(trim($ligne), '//')) {
                $lignes[] = $ligne;
            }
        }

        $code = implode("
", $lignes);

        $this->assertStringContainsString('chatContent', $code, 'Stripping the comments left no code to read.');

        return $code;
    }

    /**
     * Un message se traduit, et lui seul.
     *
     * ## Le moteur vit chez Keven
     *
     * La premiere version employait le traducteur integre de Chrome. **Mesure faite sur sa
     * machine : `typeof Translator` rend `'undefined'`** — l'interface n'existe ni sous Brave, ni
     * sous Firefox, ni sous Safari, ni sous un Chrome dont le drapeau n'est pas leve.
     *
     * Le moteur est donc un LibreTranslate heberge a cote du jeu, joint par une route du jeu.
     * Decision de Keven : plutot un service de plus a maintenir qu'un tiers a qui confier les
     * messages de ses joueurs. Sans traducteur configure, le bouton **n'est pas ecrit du tout**.
     *
     * ## Ce que le bouton ne touche pas
     *
     * Le pseudo, le tag d'alliance, le badge et les points d'honneur sont des donnees, pas du
     * texte a traduire. Seul `.msg_content` change.
     */
    public function testAMessageCanBeTranslatedAndNothingElseIs(): void
    {
        $module = (string)file_get_contents(base_path('resources/js/ingame/chat-general.js'));

        $this->assertStringContainsString(
            'generalChatTraductionActive === true',
            $module,
            'The button no longer asks the server whether a translator answers: it would appear and fail.'
        );

        // **Le navigateur ne parle a aucun tiers.** Il ne connait qu'une route du jeu ; l'adresse
        // du traducteur est interne et ne descend jamais jusqu'a lui.
        foreach (['translate.googleapis', 'deepl.com', 'libretranslate.com', ':5000'] as $tiers) {
            $this->assertStringNotContainsString(
                $tiers,
                $module,
                'The browser reaches ' . $tiers . ' directly: the translator must stay behind the game.'
            );
        }

        // Seul le contenu est reecrit ; la ligne d'en-tete n'est jamais touchee.
        $this->assertStringContainsString(
            "find('.msg_content')",
            $module,
            'The translation targets something other than the message body.'
        );

        $this->assertStringNotContainsString(
            "find('.msg_title')",
            $module,
            'The translation reaches the author line: names and badges are data, not prose.'
        );

        $reponse = $this->get('/chat');
        $reponse->assertSee('var generalChatTraductionActive =', false);
        $reponse->assertSee('var generalChatTraductionUrl =', false);

        foreach (['translate', 'translateOriginal', 'translateWorking', 'translateFailed', 'translateSame'] as $cle) {
            $reponse->assertSee($cle . ':', false);
        }

        $feuille = (string)file_get_contents(base_path('resources/css/ingame/azria.css'));
        $this->assertStringContainsString(
            '#generalChat .js_generalChatTranslate',
            $feuille,
            'The translate link has no styling: it would render as a full-size link, not a discreet one.'
        );

        // **Le lien copie la boite de la date.** Les deux flottent a droite cote a cote ; une
        // taille ou une marge differente les decale verticalement. `margin: 2px 10px 0 0` reprend
        // le retrait haut de `.msg_date` — mesure faite sur elle — et ajoute l ecart a droite, du
        // cote ou se trouve l heure, le lien venant apres elle dans le document.
        $this->assertMatchesRegularExpression(
            '/#generalChat \.js_generalChatTranslate\s*\{(?:[^}]*)margin: 2px 10px 0 0/s',
            $feuille,
            'The link no longer copies the timestamp box: it renders glued to it, or offset below it.'
        );

        $this->assertMatchesRegularExpression(
            '/#generalChat \.js_generalChatTranslate\s*\{(?:[^}]*)font-size: 9px/s',
            $feuille,
            'The link does not share the timestamp font size: the two would not sit on the same line.'
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
     * La salle prend la largeur des deux colonnes, et la hauteur va aux messages.
     *
     * Livree sans ces regles, elle faisait la largeur d'une seule colonne, et sa zone de saisie
     * occupait presque tout le panneau — la salle etant reduite a une ligne, l'inverse de ce
     * qu'on vient y lire.
     *
     * **Aucune largeur n'est imposee, et c'est le point.** Deux versions ont fixe un nombre —
     * 665 puis 650 pixels — a partir d'une largeur de conteneur jamais mesuree ; les deux
     * debordaient. La boite est un bloc : sans largeur, elle remplit ce qu'on lui donne. Et le
     * pied, plus large que sa boite de 15 px par construction, est retreci d'autant : sa
     * decoration s'arrete au bord droit quelle que soit la largeur.
     */
    public function testTheRoomSpansBothColumnsAndGivesItsHeightToTheMessages(): void
    {
        $feuille = (string)file_get_contents(base_path('resources/css/ingame/azria.css'));

        $this->assertDoesNotMatchRegularExpression(
            '/#generalChat\s*\{[^}]*width:/',
            $feuille,
            'The room fixes its own width again: two such numbers were guessed and both overflowed.'
        );

        $this->assertMatchesRegularExpression(
            '/#generalChat \.footer\s*\{[^}]*width:\s*calc\(100% - 15px\)/',
            $feuille,
            'The footer is not narrowed by its own overhang: its decoration runs past the right edge.'
        );

        $this->assertMatchesRegularExpression(
            '/#generalChat \.largeChatContainer\s*\{[^}]*height:/',
            $feuille,
            'The message area has no height of its own: the input takes the panel and the room is one line.'
        );

        $gabarit = (string)file_get_contents(base_path('resources/views/ingame/chat/partials/general.blade.php'));

        $this->assertStringContainsString(
            'rows="1"',
            $gabarit,
            'The input falls back to a multi-line box whenever the stylesheet is late.'
        );

        // **La poignee de redimensionnement est retiree**, pas seulement decouragee : un joueur
        // qui etire la zone repousse la salle hors du panneau, sans moyen de revenir en arriere.
        $this->assertMatchesRegularExpression(
            '/#generalChatText\s*\{(?:[^}]*)resize:\s*none/s',
            $feuille,
            'The message box can be dragged larger: the room gets pushed out of the panel.'
        );

        $this->assertMatchesRegularExpression(
            '/#generalChatText\s*\{(?:[^}]*)max-height:/s',
            $feuille,
            'Nothing bounds the input height should another stylesheet re-enable the handle.'
        );

        // **Les coins du pied gardent leur place.** Les deplacer decouvrait le fond repetitif
        // du pied a ses extremites — un eclat que les autres boites de la page n'ont pas.
        foreach (['#generalChat .footer .c-left', '#generalChat .footer .c-right'] as $coin) {
            $this->assertStringNotContainsString(
                $coin,
                $feuille,
                'The room moves a footer corner: the strip behind it shows through at that end.'
            );
        }
    }

    /**
     * La palette ne se repete pas et tient dans sa grille.
     *
     * Six rangees de huit : un doublon decalerait tout et laisserait un trou en fin de grille.
     */
    public function testThePaletteIsWhatTheGridExpects(): void
    {
        $palette = ChatEmojiPalette::all();

        $this->assertCount(48, $palette, 'The palette no longer fills six rows of eight.');
        $this->assertSame($palette, array_values(array_unique($palette)), 'The palette repeats a sign.');

        foreach ($palette as $signe) {
            $this->assertNotSame('', trim($signe), 'The palette carries an empty entry.');
        }
    }

    /**
     * La page offre les emoji, et le module les pose la ou est le curseur.
     *
     * **Coller a la fin serait faux la moitie du temps** : ecrire une phrase puis vouloir un
     * signe au milieu est le cas normal.
     */
    public function testTheRoomOffersEmojiAndPlacesThemAtTheCursor(): void
    {
        $reponse = $this->get('/chat');

        $reponse->assertStatus(200);
        $reponse->assertSee('id="generalChatEmojiPanel"', false);

        $rendu = (string)$reponse->getContent();
        $this->assertSame(
            count(ChatEmojiPalette::all()),
            substr_count($rendu, 'js_generalChatEmoji'),
            'The page does not offer every sign of the palette.'
        );

        $module = (string)file_get_contents(base_path('resources/js/ingame/chat-general.js'));

        // **Chercher `zone.selectionStart` ne prouvait rien** : la chaine apparait aussi dans la
        // ligne qui replace le curseur apres l'insertion, donc le temoin survivait a une version
        // qui collait tout a la fin. Ce qui compte, c'est **d'ou vient la position d'insertion**.
        $this->assertStringContainsString(
            "var debut = typeof zone.selectionStart === 'number' ? zone.selectionStart",
            $module,
            'The insertion point no longer comes from the cursor: the emoji is appended at the end.'
        );

        $this->assertStringContainsString(
            'zone.value.slice(0, debut) + signe + zone.value.slice(fin)',
            $module,
            'The text is not rebuilt around the selection: a selected passage would not be replaced.'
        );

        $feuille = (string)file_get_contents(base_path('resources/css/ingame/azria.css'));
        $this->assertStringContainsString('#generalChatEmojiPanel', $feuille, 'The picker has no styling of its own.');

        // **`hidden` doit gagner, et il ne gagne pas tout seul.** L'attribut n'agit que par la
        // regle `[hidden] { display: none }` du navigateur, de specificite minuscule ; la regle
        // de mise en grille, portee par un identifiant, l'ecrasait. Le panneau s'ouvrait alors
        // au chargement et le clic qui bascule l'attribut ne changeait rien de visible — donc
        // impossible de le fermer. Mesure faite a l'ecran, pas deduite.
        $this->assertMatchesRegularExpression(
            '/#generalChatEmojiPanel\[hidden\]\s*\{[^}]*display:\s*none/',
            $feuille,
            'Nothing makes the hidden attribute win: the emoji panel opens on load and cannot be closed.'
        );
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
