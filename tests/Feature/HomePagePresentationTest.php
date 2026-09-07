<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use OGame\Models\Planet;
use OGame\Models\User;
use Tests\TestCase;

/**
 * L'accueil d'avant-jeu : ce qu'il montre, et ce qu'il ne casse pas.
 *
 * ## Le perimetre, tel que Keven l'a fixe
 *
 * **Seules les pages d'avant-jeu changent.** Le design du jeu, apres la connexion, ne bouge pas, et
 * aucune mecanique n'est touchee. Ces temoins portent donc sur trois pages — `/login`,
 * `/forgot-password` et la page de nouveau mot de passe — et sur une garantie : les chemins de
 * compte qui vivaient derriere continuent de fonctionner a l'identique.
 *
 * ## Ce qu'ils cherchent en priorite
 *
 * Une refonte de gabarit casse en silence. Une vue qui lit le mauvais sac d'erreurs affiche un
 * formulaire muet ; une ressource mal nommee rend un 404 que personne ne voit dans les journaux ;
 * une cle de traduction absente s'affiche telle quelle. Les trois se verifient ici.
 */
class HomePagePresentationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Un visiteur qui arrive sur le site atterrit sur cette page.
     *
     * **Le trajet passe par deux redirections**, et il valait la peine de le mesurer plutot que de
     * le supposer : `/` renvoie en 301 vers `/overview`, qui exige une session, et le garde
     * d'authentification renvoie alors vers `/login`. C'est donc bien le nouveau design qu'un
     * inconnu voit en tapant l'adresse du jeu.
     */
    public function testAVisitorArrivingAtTheSiteLandsOnThisPage(): void
    {
        $reponse = $this->followingRedirects()->get('/');

        $reponse->assertStatus(200);
        $reponse->assertSee('data-panel="register"', false);
        $reponse->assertSee('azria-home/v2/home.css', false);
    }

    /**
     * La page d'accueil s'affiche et porte ses deux formulaires.
     */
    public function testTheHomePageCarriesBothForms(): void
    {
        $reponse = $this->get('/login');

        $reponse->assertStatus(200);
        $reponse->assertSee('action="' . route('login') . '"', false);
        $reponse->assertSee('action="' . route('register') . '"', false);
        $reponse->assertSee('data-panel="register"', false);
        $reponse->assertSee('data-panel="login"', false);
    }

    /**
     * Le selecteur d'univers est dans les deux formulaires, et il nomme le vrai univers.
     *
     * **Une seule installation existe.** Le sélecteur ne doit donc offrir qu'elle : ajouter des
     * options qui ne mènent nulle part serait promettre un multi-univers qui n'existe pas.
     */
    public function testTheUniverseSelectorNamesTheOnlyUniverseThatExists(): void
    {
        $reponse = $this->get('/login');
        $rendu = (string)$reponse->getContent();

        $this->assertSame(
            2,
            substr_count($rendu, 'name="uni"'),
            'The universe selector is not in both forms — registration and login.'
        );

        $this->assertSame(
            2,
            substr_count($rendu, 'value="s1"'),
            'The selector offers a universe the backend does not serve, or is missing the only one it does.'
        );
    }

    /**
     * La case des conditions est obligatoire et jamais cochee d'avance.
     *
     * Regle de Keven : pour finir son inscription, le joueur coche cette case.
     */
    public function testTheTermsBoxMustBeTickedAndIsNeverPreTicked(): void
    {
        $reponse = $this->get('/login');
        $rendu = (string)$reponse->getContent();

        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="agb"[^>]*required/',
            $rendu,
            'The terms box can be skipped: the browser would submit without it.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/<input[^>]*name="agb"[^>]*checked/',
            $rendu,
            'The terms box comes pre-ticked: consent must be an act, not a default.'
        );
    }

    /**
     * Chaque ressource que les pages demandent existe sur le disque.
     *
     * **Un 404 d'image ne se voit dans aucun journal applicatif** : la page s'affiche, un morceau
     * manque, et personne ne le sait avant qu'un joueur le dise.
     */
    public function testEveryAssetThesePagesAskForExists(): void
    {
        $absentes = [];

        foreach (['outgame/login', 'auth/forgot-password', 'auth/reset-password'] as $vue) {
            $source = (string)file_get_contents(base_path('resources/views/' . $vue . '.blade.php'));

            if (preg_match_all("/asset\('(azria-home\/[^']+)'\)/", $source, $trouves) === false) {
                continue;
            }

            foreach ($trouves[1] as $chemin) {
                if (!file_exists(public_path($chemin))) {
                    $absentes[] = $vue . ' -> ' . $chemin;
                }
            }
        }

        // Et celles que les feuilles de style demandent a leur tour.
        foreach (['home.css', 'reference-alignment.css', 'recovery.css'] as $feuille) {
            $chemin = public_path('azria-home/v2/' . $feuille);

            if (!file_exists($chemin)) {
                $absentes[] = 'manquante : ' . $feuille;

                continue;
            }

            $contenu = (string)file_get_contents($chemin);

            if (preg_match_all('/url\(([^)]+)\)/', $contenu, $trouves) === false) {
                continue;
            }

            foreach ($trouves[1] as $reference) {
                $nom = trim($reference, "\"' ");

                if (str_starts_with($nom, 'data:') || str_starts_with($nom, 'http')) {
                    continue;
                }

                if (!file_exists(public_path('azria-home/v2/' . basename($nom)))) {
                    $absentes[] = $feuille . ' -> ' . $nom;
                }
            }
        }

        $this->assertSame([], $absentes, 'These assets are referenced but absent: ' . implode(', ', $absentes));
    }

    /**
     * Aucune cle de traduction ne s'affiche telle quelle.
     *
     * `__('t_home.x')` rend la cle elle-meme quand elle manque — une chaine lisible, sans erreur.
     */
    public function testNoTranslationKeyIsShownRaw(): void
    {
        foreach (['/login', '/forgot-password'] as $page) {
            $reponse = $this->get($page);

            $reponse->assertStatus(200);
            $reponse->assertDontSee('t_home.', false);
            $reponse->assertDontSee('t_recovery.', false);
        }
    }

    /**
     * Aucun echafaudage de demonstration ne subsiste sur la vraie page.
     *
     * `home.js` sait neutraliser les formulaires — `preventDefault()` sur l'envoi, et un bandeau
     * qui annonce « apercu local uniquement : aucun compte n'est cree ». C'est reserve aux
     * demonstrations, et commande par `data-preview` sur le corps du document.
     *
     * **Deux verrous, parce qu'un seul serait une hypothese.** Le drapeau n'est pose nulle part,
     * donc le chemin est inatteignable ; et le bandeau lui-meme est retire, parce que sa phrase
     * ne peut etre que fausse sur une page qui cree vraiment des comptes.
     */
    public function testNoDemonstrationScaffoldingSurvivesOnTheRealPage(): void
    {
        foreach (['outgame/login', 'auth/forgot-password', 'auth/reset-password'] as $vue) {
            $source = (string)file_get_contents(base_path('resources/views/' . $vue . '.blade.php'));

            $this->assertStringNotContainsString(
                'data-preview',
                $source,
                $vue . ' turns on preview mode: its forms would be neutralised and create nothing.'
            );
        }

        $reponse = $this->get('/login');

        $reponse->assertDontSee('preview-feedback', false);
        $reponse->assertDontSee('data-preview', false);
    }

    /**
     * Les langues du pied de page changent vraiment la page.
     *
     * **Un lien qui pointe quelque part ne prouve pas qu'il agit.** Ce temoin suit le lien puis
     * relit l'accueil, et compare le texte rendu a la traduction attendue : c'est la seule facon
     * de distinguer un lien qui traduit d'un lien qui ne fait rien.
     */
    public function testTheFooterLanguagesActuallyTranslateThePage(): void
    {
        foreach (['fr', 'en'] as $langue) {
            $this->get(route('language.switch', ['lang' => $langue]))->assertStatus(302);

            $page = $this->get('/login');
            $page->assertStatus(200);

            $attendu = trans('t_home.join', [], $langue);

            $page->assertSee($attendu, false);
            $page->assertSee('lang="' . $langue . '"', false);
        }

        // Les cinq langues offertes par le pied sont toutes servies par une route qui existe.
        $accueil = (string)$this->get('/login')->getContent();

        foreach (['fr', 'en', 'it', 'nl', 'zh-TW'] as $langue) {
            $this->assertStringContainsString(
                route('language.switch', ['lang' => $langue]),
                $accueil,
                'The footer no longer offers ' . $langue . ', or points somewhere else.'
            );
        }
    }

    /**
     * La page de demande de mot de passe s'affiche et vise la bonne route.
     */
    public function testTheForgotPasswordPageIsWiredToFortify(): void
    {
        $reponse = $this->get('/forgot-password');

        $reponse->assertStatus(200);
        $reponse->assertSee('action="' . route('password.email') . '"', false);
        $reponse->assertSee('name="email"', false);
    }

    /**
     * La page de nouveau mot de passe porte le jeton recu dans l'adresse.
     *
     * Le jeton vient de l'URL, pas d'une session : le lien du courriel doit suffire.
     */
    public function testTheResetPageCarriesTheTokenFromTheLink(): void
    {
        $reponse = $this->get('/reset-password/un-jeton-de-banc?email=joueur%40exemple.test');

        $reponse->assertStatus(200);
        $reponse->assertSee('action="' . route('password.update') . '"', false);
        $reponse->assertSee('value="un-jeton-de-banc"', false);
    }

    /**
     * L'inscription cree toujours un compte et sa planete.
     *
     * **C'est le temoin qui compte le plus.** La refonte remplace le gabarit entier de la page ; si
     * un champ changeait de nom au passage, le formulaire s'afficherait parfaitement et ne creerait
     * plus rien.
     */
    public function testRegistrationStillCreatesAnAccountAndItsPlanet(): void
    {
        // **Le tout premier inscrit est renomme `Admin` par le jeu** (evenement du modele) : un
        // essai qui s'inscrit sur une base vide mesure ce cas particulier, pas l'inscription
        // ordinaire. Un compte precede donc celui-ci.
        User::factory()->create();

        $avant = User::count();

        $reponse = $this->post(route('register'), [
            'username' => 'JoueurDuBanc',
            'email' => 'joueur.du.banc@exemple.test',
            'password' => 'MotDePasseSolide123',
            'password_confirmation' => 'MotDePasseSolide123',
            'agb' => 'on',
            'uni' => 's1',
        ]);

        $reponse->assertSessionHasNoErrors();
        $this->assertSame($avant + 1, User::count(), 'Registration no longer creates the account.');

        $joueur = User::where('email', 'joueur.du.banc@exemple.test')->firstOrFail();

        $this->assertSame('JoueurDuBanc', $joueur->username);
        $this->assertGreaterThan(
            0,
            Planet::where('user_id', $joueur->id)->count(),
            'The account exists with no planet: the player would land in a game with nowhere to be.'
        );
    }

    /**
     * La connexion fonctionne toujours depuis le nouveau formulaire.
     */
    public function testLoginStillWorksFromTheNewForm(): void
    {
        $this->post(route('register'), [
            'username' => 'JoueurQuiRevient',
            'email' => 'retour@exemple.test',
            'password' => 'MotDePasseSolide123',
            'password_confirmation' => 'MotDePasseSolide123',
            'agb' => 'on',
            'uni' => 's1',
        ]);

        $this->post('/logout');
        $this->assertGuest();

        $this->post(route('login'), [
            'email' => 'retour@exemple.test',
            'password' => 'MotDePasseSolide123',
        ]);

        $this->assertAuthenticated();
    }

    /**
     * Une erreur d'inscription se voit dans le formulaire.
     *
     * La vue lit le sac `register` ; `CreateNewUser` valide par `validateWithBag('register')`. Si
     * l'un des deux changeait, le joueur verrait un formulaire qui refuse sans rien dire.
     */
    public function testARegistrationErrorIsShownInTheForm(): void
    {
        // **Le redirect est suivi dans la meme requete.** Les erreurs sont flashees : une seconde
        // requete separee arrive apres leur consommation, et la page se rendrait sans elles —
        // l'essai conclurait a tort que le formulaire est muet.
        $reponse = $this->followingRedirects()->from('/login')->post(route('register'), [
            'username' => 'JoueurIncomplet',
            'email' => 'pas-une-adresse',
            'password' => 'court',
            'password_confirmation' => 'different',
            'agb' => 'on',
            'uni' => 's1',
        ]);

        $reponse->assertStatus(200);
        $reponse->assertSee('data-error-summary', false);
        $reponse->assertSee('data-errors="true"', false);

        // Le pseudo et l'adresse sont conservés ; le mot de passe, jamais.
        $reponse->assertSee('value="JoueurIncomplet"', false);
        $reponse->assertDontSee('court', false);
    }

    /**
     * Le parcours de recuperation part vraiment, et le jeton mene a la page.
     */
    public function testTheRecoveryJourneyGoesFromRequestToReset(): void
    {
        $joueur = User::factory()->create(['email' => 'oubli@exemple.test']);

        $this->post(route('password.email'), ['email' => 'oubli@exemple.test'])
            ->assertSessionHasNoErrors();

        $jeton = Password::broker()->createToken($joueur);

        $this->get('/reset-password/' . $jeton . '?email=oubli%40exemple.test')
            ->assertStatus(200)
            ->assertSee('value="' . $jeton . '"', false);
    }

    /**
     * Le mot de passe change vraiment, l'ancien cesse de fonctionner, et le jeton ne resservira pas.
     *
     * **Le temoin precedent s'arretait a la page.** Il etablissait que le jeton y arrive, pas que le
     * parcours aboutit — et c'est exactement l'endroit ou une route manquante se cache derriere un
     * « c'est fait ». Trois faits sont mesures ici, pas supposes : le nouveau mot de passe ouvre la
     * session, l'ancien ne l'ouvre plus, et le jeton employe est mort.
     */
    public function testTheNewPasswordWorksTheOldOneStopsAndTheTokenDies(): void
    {
        $joueur = User::factory()->create([
            'email' => 'change@exemple.test',
            'password' => Hash::make('AncienMotDePasse123'),
        ]);

        $jeton = Password::broker()->createToken($joueur);

        $this->post(route('password.update'), [
            'token' => $jeton,
            'email' => 'change@exemple.test',
            'password' => 'NouveauMotDePasse456',
            'password_confirmation' => 'NouveauMotDePasse456',
        ])->assertSessionHasNoErrors();

        $this->post('/logout');
        $this->assertGuest();

        // L'ancien n'ouvre plus rien.
        $this->post(route('login'), ['email' => 'change@exemple.test', 'password' => 'AncienMotDePasse123']);
        // `assertGuest()` ne prend pas de message : le passer etait accepte et **silencieusement
        // ignore**. On lit donc le garde directement, pour qu'un echec dise ce qu'il signifie.
        $this->assertFalse(auth()->check(), 'The old password still opens the account after a reset.');

        // Le nouveau, si.
        $this->post(route('login'), ['email' => 'change@exemple.test', 'password' => 'NouveauMotDePasse456']);
        $this->assertAuthenticated();

        // **Un jeton sert une fois.** Rejoue, il doit etre refuse — sinon un lien intercepte resterait
        // une clef pour qui le retrouve, longtemps apres.
        $this->post('/logout');

        $this->post(route('password.update'), [
            'token' => $jeton,
            'email' => 'change@exemple.test',
            'password' => 'TroisiemeMotDePasse789',
            'password_confirmation' => 'TroisiemeMotDePasse789',
        ])->assertSessionHasErrors();

        $this->post(route('login'), ['email' => 'change@exemple.test', 'password' => 'TroisiemeMotDePasse789']);
        $this->assertFalse(auth()->check(), 'A used token still changes the password: the link stays a key for good.');
    }

    /**
     * Un jeton altere ne change rien.
     */
    public function testATamperedTokenChangesNothing(): void
    {
        User::factory()->create([
            'email' => 'intact@exemple.test',
            'password' => Hash::make('MotDePasseIntact123'),
        ]);

        $this->post(route('password.update'), [
            'token' => 'un-jeton-invente-de-toutes-pieces',
            'email' => 'intact@exemple.test',
            'password' => 'MotDePasseVole456',
            'password_confirmation' => 'MotDePasseVole456',
        ])->assertSessionHasErrors();

        $this->post('/logout');
        $this->post(route('login'), ['email' => 'intact@exemple.test', 'password' => 'MotDePasseVole456']);
        $this->assertFalse(auth()->check(), 'An invented token changed the password.');
    }

    /**
     * Une adresse inconnue est indistinguable d'une adresse connue.
     *
     * ## Le defaut ferme
     *
     * Fortify rendait ses trois issues telles quelles : une adresse inconnue recevait « We can't find
     * a user with that email address. », une adresse connue n'en recevait aucune. **N'importe qui
     * pouvait savoir si un compte existe** en soumettant une adresse au formulaire — sur un jeu ou
     * les pseudonymes sont publics, c'est le premier pas d'une attaque ciblee.
     *
     * ## Pourquoi les trois issues, et pas seulement deux
     *
     * La limitation ne s'applique qu'aux comptes reels : c'est leur jeton precedent qui la declenche.
     * Masquer la seule adresse inconnue aurait donc laisse « trop de demandes » dire « ce compte
     * existe », en deux clics au lieu d'un. Le canal se referme entierement ou pas du tout.
     *
     * La limitation **fonctionne toujours** — aucun courriel supplementaire ne part. Elle n'est plus
     * annoncee, voila tout.
     *
     * ## Ce que ce temoin ne couvre pas
     *
     * Le temps de reponse. Envoyer un courriel prend plus longtemps que ne rien faire, et l'ecart
     * reste mesurable par qui le cherche. Le fermer demande de sortir l'envoi de la requete : un
     * travail distinct, non fait, et qu'il serait faux de laisser croire fait.
     */
    public function testAnUnknownAddressIsIndistinguishableFromAKnownOne(): void
    {
        User::factory()->create(['email' => 'connu@exemple.test']);

        // **Chaque reponse est jugee avant la suivante.** La session est partagee : asserter sur la
        // premiere apres avoir envoye la seconde revient a lire l'etat laisse par la seconde.
        $connue = $this->post(route('password.email'), ['email' => 'connu@exemple.test']);
        $connue->assertSessionHasNoErrors();
        $codeConnue = $connue->getStatusCode();
        $phraseConnue = session('status');

        $inconnue = $this->post(route('password.email'), ['email' => 'jamais-vu@exemple.test']);

        $this->assertSame(
            $codeConnue,
            $inconnue->getStatusCode(),
            'A known and an unknown address answer with different status codes: the accounts can be enumerated.'
        );

        $inconnue->assertSessionHasNoErrors();

        $this->assertSame(
            $phraseConnue,
            session('status'),
            'The two answers carry a different sentence: the difference tells which addresses exist.'
        );

        // Et la phrase ne promet pas un envoi qui n'a pas eu lieu.
        $this->assertSame(trans('t_recovery.sent'), session('status'));
    }
}
