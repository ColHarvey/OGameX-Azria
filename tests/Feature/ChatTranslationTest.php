<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use OGame\Chat\ChatTranslator;
use OGame\Models\User;
use Tests\AccountTestCase;

/**
 * La traduction d'un message : ce qu'elle promet, et ce qu'elle refuse.
 *
 * ## Pourquoi le moteur a change
 *
 * La premiere version employait le traducteur integre de Chrome. **Mesure faite sur la machine de
 * Keven : `typeof Translator` rend `'undefined'`** — l'interface n'existe ni sous Brave, ni sous
 * Firefox, ni sous Safari, ni sous un Chrome dont le drapeau n'est pas leve. La fonctionnalite ne
 * servait donc presque personne.
 *
 * Le moteur est desormais un LibreTranslate heberge a cote du jeu. Decision de Keven : plutot un
 * service de plus a maintenir qu'un tiers a qui confier les messages de ses joueurs.
 *
 * ## Les trois garanties que ces temoins tiennent
 *
 * **Le traducteur n'est joignable que par le jeu.** Il tourne sans port publie ; cette route est le
 * seul chemin, elle exige une session et elle borne le debit — sinon le service deviendrait une API
 * de traduction gratuite pour qui la trouve.
 *
 * **Une panne ne casse rien.** Service absent, arrete, lent ou incoherent : la route repond
 * « indisponible » et le joueur garde son message. Le chat n'a jamais besoin du traducteur.
 *
 * **Rien n'est enregistre ni diffuse.** La traduction ne vit que dans la reponse ; les autres
 * lecteurs voient toujours l'original.
 */
class ChatTranslationTest extends AccountTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('chat-translate:' . $this->currentUserId);
        config(['services.libretranslate.url' => 'http://traducteur-de-banc:5000']);

        // **La langue se pose sur le joueur, pas par `App::setLocale()`.** Le middleware la lit
        // pendant la requete — session d'abord, champ `lang` ensuite — et ecrase toute valeur
        // posee avant l'appel. Deux essais ont d'abord rendu l'inverse de ce qu'ils attendaient.
        $joueur = User::query()->findOrFail($this->currentUserId);
        $joueur->lang = 'fr';
        $joueur->save();
    }

    /**
     * Un message dans une autre langue revient traduit.
     */
    public function testAForeignMessageComesBackTranslated(): void
    {
        Http::fake([
            '*/translate' => Http::response([
                'translatedText' => 'Bonjour tout le monde',
                'detectedLanguage' => ['language' => 'en', 'confidence' => 98],
            ]),
        ]);

        $reponse = $this->traduire(['text' => 'Hello everyone']);

        $reponse->assertStatus(200);
        $reponse->assertJsonPath('status', 'OK');
        $reponse->assertJsonPath('text', 'Bonjour tout le monde');
    }

    /**
     * Un message deja dans la langue du lecteur se dit tel, et non « impossible ».
     *
     * **Les deux issues ne se confondent pas.** Annoncer un echec la ou il n'y a rien a faire
     * apprendrait au joueur a se mefier d'un bouton qui fonctionne.
     */
    public function testAMessageAlreadyInTheReadersLanguageSaysSo(): void
    {
        Http::fake([
            '*/translate' => Http::response([
                'translatedText' => 'Bonjour',
                'detectedLanguage' => ['language' => 'fr', 'confidence' => 99],
            ]),
        ]);

        $this->traduire(['text' => 'Bonjour'])
            ->assertJsonPath('status', 'SAME_LANGUAGE');
    }

    /**
     * Sans traducteur configure, la route le dit — et la page n'affiche pas le bouton.
     */
    public function testWithNoTranslatorConfiguredNothingIsPromised(): void
    {
        config(['services.libretranslate.url' => '']);

        $this->traduire(['text' => 'Hello'])
            ->assertJsonPath('status', 'UNAVAILABLE');

        $this->get('/chat')->assertSee('var generalChatTraductionActive = false', false);
    }

    /**
     * Le service en panne ne casse rien.
     *
     * Trois formes de panne, une seule reponse : le joueur garde son message.
     */
    public function testAFailingServiceNeverBreaksTheChat(): void
    {
        foreach ([Http::response('', 502), Http::response(['rien' => 'de bon']), Http::response('pas du json', 200)] as $panne) {
            Http::fake(['*/translate' => $panne]);

            $this->traduire(['text' => 'Hello'])
                ->assertStatus(200)
                ->assertJsonPath('status', 'UNAVAILABLE');
        }
    }

    /**
     * Au-dela de la limite, la route refuse et dit quand reessayer.
     *
     * Le traducteur est un service de Keven : sans borne, un seul lecteur pourrait le saturer.
     */
    public function testBeyondTheLimitTheRouteRefuses(): void
    {
        Http::fake(['*/translate' => Http::response(['translatedText' => 'x', 'detectedLanguage' => ['language' => 'en']])]);

        for ($i = 0; $i < 20; $i++) {
            $this->traduire(['text' => 'Hello ' . $i])->assertJsonPath('status', 'OK');
        }

        $refuse = $this->traduire(['text' => 'Un de trop']);

        $refuse->assertJsonPath('status', 'TOO_MANY_TRANSLATIONS');
        $this->assertGreaterThan(0, (int)$refuse->json('retryAfter'));
    }

    /**
     * La langue cible vient de la session, jamais du navigateur.
     *
     * **Un joueur ne choisit pas la langue d'arrivee par la requete.** Elle est celle de son jeu ;
     * la laisser passer par le reseau serait offrir un parametre de plus a manipuler pour rien.
     */
    public function testTheTargetLanguageComesFromTheSessionNotTheRequest(): void
    {
        Http::fake(['*/translate' => Http::response(['translatedText' => 'ok', 'detectedLanguage' => ['language' => 'en']])]);

        $this->traduire(['text' => 'Hello', 'target' => 'de']);

        Http::assertSent(function ($requete): bool {
            return $requete['target'] === 'fr';
        });

        $module = (string)file_get_contents(base_path('resources/js/ingame/chat-general.js'));

        $this->assertStringNotContainsString(
            'target:',
            $module,
            'The browser sends a target language: that decision belongs to the session.'
        );
    }

    /**
     * Une demande de traduction, faite par un lecteur francais.
     *
     * **La locale se pose dans la session, pas par `App::setLocale()`.** Le middleware la lit
     * pendant la requete et sa premiere source est la session ; une valeur posee avant l'appel,
     * ou meme le champ `lang` du joueur, ne l'emporte pas dessus. Deux essais ont d'abord rendu
     * exactement l'inverse de ce qu'ils attendaient, faute de le savoir.
     */
    private function traduire(array $donnees): \Illuminate\Testing\TestResponse
    {
        return $this->withSession(['locale' => 'fr'])->post(route('chat.translate'), $donnees);
    }

    /**
     * Le traducteur ne se declare pas configure sans adresse.
     */
    public function testTheTranslatorKnowsWhenItIsNotConfigured(): void
    {
        $traducteur = resolve(ChatTranslator::class);

        $this->assertTrue($traducteur->configured());

        config(['services.libretranslate.url' => '   ']);
        $this->assertFalse($traducteur->configured(), 'A blank address counts as configured: every click would fail.');

        $this->assertNull($traducteur->translate('Hello', 'fr'));
    }

    /**
     * Un texte vide ne part pas sur le reseau.
     */
    public function testAnEmptyTextNeverReachesTheService(): void
    {
        Http::fake();

        $this->assertNull(resolve(ChatTranslator::class)->translate('   ', 'fr'));

        Http::assertNothingSent();
    }
}
