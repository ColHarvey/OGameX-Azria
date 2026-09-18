<?php

namespace Tests\Feature;

use Tests\AccountTestCase;

/**
 * **Le chat et les contacts au design approuve par Keven** (kit de Codex du 15 septembre 2026, journal §158).
 *
 * Ce que ces temoins prouvent : la barre porte la classe opt-in et l onglet des contacts du kit ; chaque libelle que
 * `chat.js` affiche passe par `chatLoca`, pose par le gabarit depuis les fichiers de langue (fr et en) ; la feuille du
 * theme est isolee sous `#chatBar.azria-chat`, importee apres la feuille historique, et **servie** (la feuille construite
 * que le manifeste designe la porte) ; le bundle servi porte `chat.js` tel qu il est ecrit ; les douze icones Lucide sont
 * figees avec leur licence et rendues en ligne depuis le bundle ; le panneau Azria ecrit les noms en texte. Ce que les
 * clics prouvent (envoi unique, brouillon garde, filtres, petits ecrans) vit dans le scenario navigateur du journal.
 */
class ChatBarDesignTest extends AccountTestCase
{
    private const array CLEFS_CHAT_LOCA = [
        'PLAYER_LIST', 'FILTER_BY', 'FILTER_ONLINE', 'FILTER_ALL', 'FILTER_ACTIVE', 'BUDDIES', 'NO_BUDDIES', 'ALLIANCE',
        'ALLIANCE_CHAT', 'ALLIANCE_CHANNEL', 'STRANGERS', 'STATUS_ONLINE', 'STATUS_OFFLINE', 'STATUS_HIDDEN', 'COMMUNICATIONS',
        'CONTACTS_ONLINE_SHORT', 'CONTACTS_NETWORK', 'ONLINE_RATIO', 'COLLAPSE_CONTACTS', 'PRIVATE_CONVERSATION',
        'MINIMIZE_CONVERSATION', 'CLOSE_CONVERSATION', 'OPEN_MESSAGING', 'SEND', 'LOAD_ERROR',
    ];

    private const array ICONES = ['radio-tower', 'message-square', 'shield', 'orbit', 'crosshair', 'sparkles', 'moon', 'send', 'minus', 'x', 'chevron-up', 'chevron-down'];

    private function manifeste(): array
    {
        $manifeste = json_decode((string)file_get_contents(public_path('build/manifest.json')), true);
        $this->assertIsArray($manifeste);

        return $manifeste;
    }

    private function bundleServi(): string
    {
        $chemin = public_path('build/' . $this->manifeste()['resources/js/ingame.js']['file']);
        $this->assertFileExists($chemin, 'Le manifeste designe un bundle qui n est pas commite : le jeu ne servirait rien.');

        return (string)file_get_contents($chemin);
    }

    private function feuilleServie(): string
    {
        $chemin = public_path('build/' . $this->manifeste()['resources/css/ingame.css']['file']);
        $this->assertFileExists($chemin, 'Le manifeste designe une feuille qui n est pas commitee.');

        return (string)file_get_contents($chemin);
    }

    /**
     * Le premier groupe d un motif qui doit se trouver dans le sujet.
     */
    private function capture(string $motif, string $sujet, string $message): string
    {
        $this->assertSame(1, preg_match($motif, $sujet, $m), $message);

        return $m[1] ?? '';
    }

    private function chatJs(): string
    {
        return str_replace("\r\n", "\n", (string)file_get_contents(resource_path('js/ingame/chat.js')));
    }

    public function testTheBarCarriesTheOptInClassAndTheContactsTabOfTheKit(): void
    {
        $page = (string)$this->get('/overview')->assertStatus(200)->getContent();
        $this->assertSame(1, preg_match('#<div id="chatBar" class="azria-chat">#', $page), 'La classe opt-in du theme est sur la barre.');
        $this->assertSame(1, preg_match('#<span class="onlineCount" title="[^"]*">\s*<svg class="az-svg"[^>]*aria-hidden="true"[^>]*>.*?</svg>\s*<span class="az-tab-label">' . preg_quote(e(__('t_ingame.chat.contacts')), '#') . '</span>\s*<span class="az-count">\d+</span>\s*<svg class="az-svg"#s', $page), 'L onglet des contacts : icone, libelle traduit, compteur, chevron.');
        $this->assertStringContainsString(e(__('t_ingame.layout.contacts_online', ['count' => 0])), $page, 'Le libelle historique reste en infobulle de l onglet.');
    }

    public function testEveryLabelChatJsShowsComesFromChatLocaInTheFiveLanguages(): void
    {
        // Les cinq langues du jeu (le kit les exige) : chaque clef resout dans sa langue, jamais en clef brute ni en repli.
        $temoins = ['fr' => 'Statut non visible', 'en' => 'Status not visible', 'it' => 'Stato non visibile', 'nl' => 'Status niet zichtbaar', 'zh-TW' => '狀態不可見'];
        foreach (['fr', 'en', 'it', 'nl', 'zh-TW'] as $locale) {
            // La page suit le choix de langue de la session (middleware Locale, priorite 1), pas la langue du banc.
            $page = (string)$this->withSession(['locale' => $locale])->get('/overview')->assertStatus(200)->getContent();
            $loca = json_decode($this->capture('#var chatLoca = (\{.*?\});#s', $page, 'chatLoca est pose par le gabarit.'), true);
            $this->assertIsArray($loca);
            foreach (self::CLEFS_CHAT_LOCA as $clef) {
                $this->assertArrayHasKey($clef, $loca, "chatLoca.$clef manque ($locale).");
                $this->assertIsString($loca[$clef]);
                $this->assertNotSame('', $loca[$clef]);
                $this->assertStringNotContainsString('t_ingame.', $loca[$clef], "chatLoca.$clef est une clef brute ($locale).");
            }
            $this->assertSame($temoins[$locale], $loca['STATUS_HIDDEN'], "La clef est traduite en $locale, pas reprise de l anglais.");
            $this->assertStringContainsString('#+#', $loca['CONTACTS_ONLINE_SHORT'], 'Le nombre de contacts en ligne se remplace cote client.');
            $this->assertStringContainsString('#online#', $loca['ONLINE_RATIO']);
        }
        $js = $this->chatJs();
        foreach (["'Player list'", "'Filter by:'", "'Buddies'", "'Strangers'", "'No buddies'", "'Alliance Chat'", "'Status not visible'", "'Online chats", "'Active chats", "Error loading buddies</p>"] as $litteral) {
            $this->assertStringNotContainsString($litteral, $js, "chat.js affiche encore « $litteral » en dur.");
        }
        foreach (self::CLEFS_CHAT_LOCA as $clef) {
            if (in_array($clef, ['FILTER_BY', 'PLAYER_LIST'], true)) {
                continue; // employes par le panneau historique seulement, verifie ci-dessous
            }
            $this->assertStringContainsString("'" . $clef . "'", $js, "chat.js n emploie pas chatLoca.$clef.");
        }
        $this->assertStringContainsString("loca('PLAYER_LIST')", $js);
        $this->assertStringContainsString("loca('FILTER_BY')", $js);
    }

    public function testTheThemeSheetIsIsolatedImportedAfterTheHistoricSheetAndServed(): void
    {
        $entree = str_replace("\r\n", "\n", (string)file_get_contents(resource_path('css/ingame.css')));
        $historique = strpos($entree, '@import "ingame/469500b3cd5158332fb20a56b14b2c.css";');
        $azria = strpos($entree, '@import "ingame/azria.css";');
        $theme = strpos($entree, '@import "ingame/chat-azria.css";');
        $this->assertIsInt($historique);
        $this->assertIsInt($azria);
        $this->assertIsInt($theme, 'La feuille du theme n est pas importee.');
        // Le theme vient apres la feuille historique (ses regles doivent la battre a specificite egale) et avant azria.css,
        // que OverviewScrollFixTest exige en dernier ; les deux feuilles du fork ne se disputent aucun selecteur.
        $this->assertGreaterThan($historique, $theme, 'Le theme vient apres la feuille historique.');
        $this->assertGreaterThan($theme, $azria, 'azria.css reste la derniere feuille importee.');

        $feuille = str_replace("\r\n", "\n", (string)file_get_contents(resource_path('css/ingame/chat-azria.css')));
        $sansCommentaires = (string)preg_replace('#/\*.*?\*/#s', '', $feuille);
        // Chaque selecteur de chaque regle, blocs @media compris, commence par #chatBar.azria-chat.
        $corps = (string)preg_replace('/@media[^{]*\{/', '', $sansCommentaires);
        $this->assertSame(1, preg_match_all('/([^{}]+)\{[^{}]*\}/', $corps, $regles) > 0 ? 1 : 0);
        $selecteurs = 0;
        foreach ($regles[1] as $groupe) {
            foreach (explode(',', $groupe) as $selecteur) {
                $selecteur = trim($selecteur, " \n\t}");
                if ($selecteur === '') {
                    continue;
                }
                $selecteurs++;
                $this->assertStringStartsWith('#chatBar.azria-chat', $selecteur, "Un selecteur du theme sort de la barre : « $selecteur ».");
                $this->assertStringNotContainsString('#sideBar', $selecteur);
            }
        }
        $this->assertGreaterThan(80, $selecteurs, 'La feuille porte le theme entier.');
        $this->assertSame(0, preg_match('/\.chat_bar_(pl_)?list_item[^{]*\{[^}]*overflow\s*:\s*hidden/', $sansCommentaires), 'Aucun onglet ne coupe sa fenetre.');
        $this->assertStringContainsString('border-radius: 8px;', $feuille, 'Les onglets du bas ont un rayon de 8 px.');
        $this->assertStringContainsString('border-radius: 7px;', $feuille, 'Les lignes de contacts et le canal ont un rayon de 7 px.');
        $this->assertStringNotContainsString('clip-path: polygon', $feuille, 'Aucun coin coupe.');
        $this->assertStringNotContainsString('url(', $sansCommentaires, 'Les cadres et fonds sont entierement en CSS : ni PNG ni JPEG.');

        $servie = $this->feuilleServie();
        $this->assertStringContainsString('#chatBar.azria-chat', $servie, 'La feuille servie ne porte pas le theme : le jeu servirait l ancien rendu.');
        $this->assertStringContainsString('#chatBar.azria-chat .chat_bar_list_item.open{width:350px}', $servie, 'La largeur de 350 px que chat.js suppose sous le theme.');
        $this->assertStringContainsString('#chatBar.azria-chat .cb_playerlist_box{width:266px}', $servie);
    }

    public function testTheServedBundleCarriesChatJsAsWrittenAndOutgameIsUntouched(): void
    {
        $bundle = $this->bundleServi();
        $this->assertStringContainsString(rtrim($this->chatJs(), "\n"), $bundle, 'Le bundle servi ne porte pas chat.js tel qu il est ecrit : le jeu servirait l ancien chat.');
        $this->assertStringContainsString("azriaRoster: function (response) {", $bundle);
        $this->assertSame('assets/outgame-10de045b.js', $this->manifeste()['resources/js/outgame.js']['file'], 'Le bundle outgame a bouge sans raison : la construction derape.');
        $this->assertSame('assets/outgame-Bd4b_3cr.css', $this->manifeste()['resources/css/outgame.css']['file']);
    }

    public function testTheTwelveLucideIconsAreFrozenWithTheirLicenceAndInlinedFromTheBundle(): void
    {
        $this->assertFileExists(public_path('img/chat-azria/LICENSE.txt'));
        $this->assertStringContainsString('ISC License', (string)file_get_contents(public_path('img/chat-azria/LICENSE.txt')));
        $js = $this->chatJs();
        $dictionnaire = $this->capture('/azriaIcons: \{(.*?)\n    \},/s', $js, 'Le dictionnaire des icones est dans chat.js.');
        foreach (self::ICONES as $icone) {
            $fichier = public_path('img/chat-azria/' . $icone . '.svg');
            $this->assertFileExists($fichier, "L icone $icone n est pas figee avec le depot.");
            $svg = (string)file_get_contents($fichier);
            $this->assertStringContainsString('lucide-static v0.468.0', $svg, 'La version est figee (0.468.0).');
            $entree = $this->capture("/'" . preg_quote($icone, '/') . "': '([^']*)'/", $dictionnaire, "Le dictionnaire n a pas $icone.");
            // Le contenu du dictionnaire est celui du fichier : chaque trace du SVG figee s y retrouve.
            preg_match_all('/<(?:path|circle|line|polyline|rect)\b[^>]*>/', $svg, $traces);
            foreach ($traces[0] as $trace) {
                $compacte = str_replace(' />', '/>', (string)preg_replace('/\s+/', ' ', $trace));
                $this->assertStringContainsString($compacte, $entree, "Une trace de $icone manque au dictionnaire.");
            }
            $this->assertStringNotContainsString('<script', $entree);
            $this->assertSame(0, preg_match('/\son[a-z]+=/i', $entree));
        }
        $this->assertStringNotContainsString('lucide.min.js', $js, 'Aucun fournisseur d icones charge depuis le reseau.');
        $this->assertStringNotContainsString('unpkg.com', $js);
        $this->assertStringNotContainsString('jsdelivr', $js);
        $this->assertStringContainsString('stroke="currentColor"', $js, 'Les icones en ligne heritent de la couleur CSS.');
        $this->assertStringContainsString('aria-hidden="true"', $js);
    }

    public function testTheAzriaRosterWritesNamesAsTextAndInventsNoStatus(): void
    {
        $js = $this->chatJs();
        $panneau = $this->capture('/azriaRoster: function \(response\) \{(.*?)\n    \}\n/s', $js, 'Le panneau Azria est une fabrique de chat.js.');
        $this->assertStringContainsString('document.createTextNode(player.username)', $panneau, 'Le nom d un joueur est un noeud texte.');
        $this->assertStringNotContainsString("+ player.username +", $panneau, 'Aucune concatenation HTML d un nom dans le panneau Azria.');
        $this->assertStringContainsString(".text(response.alliance.tag)", $panneau);
        $this->assertStringContainsString("if (!isStranger && player.isOnline) {", $panneau, 'La pastille verte n existe que pour une presence connue et en ligne.');
        $this->assertStringContainsString("isStranger ? 'STATUS_HIDDEN'", $panneau, 'Un inconnu garde « statut non visible », pas un statut invente.');
        $this->assertStringContainsString("attr('data-playerid', player.id)", $panneau, 'L accroche data-playerid du clic historique.');
        $this->assertStringContainsString("'<input id=\"filteronline\" type=\"checkbox\"", $panneau, 'Les deux cases que filterPlayerlist lit restent dans le DOM.');
        $this->assertStringContainsString("'<input id=\"filterchatactive\" type=\"checkbox\"", $panneau);
        $this->assertStringContainsString("attr('data-associationid', response.alliance.id)", $panneau);
        $this->assertStringContainsString("openAssociationChat", $panneau);

        // Le bouton Envoyer prend le chemin de la touche Entree, et rien d autre.
        $envoi = $this->capture('/\.on\("click\.chatBar", "\.chat_box \.az-send", function \(a\) \{(.*?)\n        \}\)/s', $js, 'Le bouton Envoyer a son ecouteur.');
        $this->assertStringContainsString('c.submitChatBarMsg(t, 13, false, t[0].scrollHeight)', $envoi);
        $this->assertStringNotContainsString('$.ajax', $envoi, 'Aucune requete parallele.');
        $this->assertStringContainsString("if ($.trim(t.val()).length > 0) {", $envoi, 'Un texte vide ne part pas.');

        // Le panneau lateral de la page de chat garde le rendu historique.
        $this->assertStringContainsString("$(d).closest('#chatBar').length", $js, 'Le panneau Azria ne va que dans la barre.');
        // Les dates : le serveur envoie des secondes, la fabrique les rend en millisecondes.
        $this->assertStringContainsString('horodatage = horodatage * 1000', $js);
        // Un message recu vise l onglet, jamais la ligne du panneau qui porte le meme identifiant (la fenetre s ouvre en direct).
        $this->assertStringContainsString("find(\".chat_bar_list_item[data-playerid='\" + F + \"']\")", $js);
        $this->assertStringNotContainsString("find(\"[data-playerid='\" + F + \"']\")", $js, 'Le selecteur lache attrapait la ligne du panneau des contacts.');
        // Le pont : l onglet des contacts reste compact, la conversation se decale par une variable posee par chat.js.
        $this->assertStringContainsString("style.setProperty('--az-deck-shift'", $js);
        $regle = '.chat_bar_list_item.open > .chat_box { right: calc(-1px + var(--az-deck-shift, 0px)); }';
        $source = (string)preg_replace('/\s+/', ' ', (string)file_get_contents(resource_path('css/ingame/chat-azria.css')));
        $this->assertStringContainsString('#chatBar.azria-chat ' . $regle, $source, 'La feuille source lit le decalage du pont.');
        $this->assertStringContainsString('#chatBar.azria-chat .chat_bar_list_item.open>.chat_box{right:calc(-1px + var(--az-deck-shift,0px))}', $this->feuilleServie(), 'La feuille servie porte la regle du pont telle que Vite l ecrit.');
        $this->assertStringNotContainsString(':has(', (string)file_get_contents(resource_path('css/ingame/chat-azria.css')), 'Aucun elargissement de l onglet des contacts.');
    }
}
