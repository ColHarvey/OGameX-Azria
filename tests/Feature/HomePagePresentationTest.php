<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
