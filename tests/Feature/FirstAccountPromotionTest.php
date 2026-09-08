<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use OGame\Models\User;
use Tests\TestCase;

/**
 * Le premier inscrit devient l'administrateur, garde son pseudo, et les suivants ne reçoivent rien.
 *
 * **Une règle du jeu, pas du banc.** Le crochet `created` de `User` donne le rôle `admin` au premier
 * compte qui n'est pas Legor. C'est ainsi que le premier inscrit d'une installation neuve devient
 * son administrateur. L'amont le renommait « Admin » ; Azria a décidé qu'il garde le pseudo qu'il a
 * choisi, et cette décision n'avait jamais atteint le crochet. Les bancs de tests neutralisent la
 * promotion pour jouer un joueur ordinaire ; sans un témoin à part, un crochet supprimé ou un
 * renommage revenu ne ferait rougir aucun essai.
 *
 * **Le vrai parcours d'inscription, pas une fabrique.** Les deux comptes naissent du formulaire :
 * `POST /register`, donc Fortify puis `CreateNewUser`, exactement le chemin qu'emprunte un joueur.
 * Un témoin qui appellerait la fabrique prouverait le crochet seul et laisserait passer une seconde
 * règle contradictoire posée en chemin.
 *
 * **Une base isolée, à ce seul essai.** La base d'un processus est partagée et porte déjà des
 * comptes quand l'essai tourne, or la règle ne se déclenche que sur une base sans autre compte.
 * L'essai migre donc une base SQLite en mémoire, la sienne, et n'écrit dans aucune autre : aucun
 * compte d'un essai voisin n'est lu, modifié ni renommé. La migration des rôles y pose Legor, le
 * compte système, administrateur par SQL brut : c'est l'état exact d'une installation neuve au
 * moment de la première inscription.
 */
class FirstAccountPromotionTest extends TestCase
{
    private const string CONNECTION = 'first_account_witness';

    private string $previousDefaultConnection = 'sqlite';

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousDefaultConnection = DB::getDefaultConnection();

        config(['database.connections.' . self::CONNECTION => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        config(['database.default' => self::CONNECTION]);
        DB::setDefaultConnection(self::CONNECTION);

        Artisan::call('migrate', ['--database' => self::CONNECTION, '--force' => true]);
    }

    protected function tearDown(): void
    {
        config(['database.default' => $this->previousDefaultConnection]);
        DB::setDefaultConnection($this->previousDefaultConnection);
        DB::purge(self::CONNECTION);

        parent::tearDown();
    }

    /**
     * Le premier inscrit reçoit le rôle et garde son nom ; le second ne reçoit ni l'un ni l'autre.
     */
    public function testTheFirstRegisteredPlayerBecomesTheAdministratorAndKeepsTheNameItChose(): void
    {
        // Prémisse : la base ne porte que le compte système, que la règle ignore par son nom. S'il
        // comptait comme un « autre » compte, personne ne serait jamais promu.
        $this->assertSame(
            [User::SYSTEM_ACCOUNT_USERNAME],
            User::query()->orderBy('id')->pluck('username')->all(),
            'The isolated database must hold only the system account before the first registration.'
        );

        $first = $this->registerAPlayer('PremierInscrit');

        $this->assertTrue($first->hasRole('admin'), 'The first registered player holds the administrator role.');
        $this->assertSame(
            'PremierInscrit',
            $first->username,
            'The first registered player keeps the name it chose: the upstream rename to Admin is gone.'
        );

        $second = $this->registerAPlayer('SecondInscrit');

        $this->assertFalse($second->hasRole('admin'), 'The second registered player is not promoted.');
        $this->assertSame('SecondInscrit', $second->username, 'The second registered player keeps the name it chose.');

        // Le second passage ne renomme ni ne dépromeut personne rétroactivement.
        $firstAfterwards = User::query()->findOrFail($first->id);
        $this->assertSame('PremierInscrit', $firstAfterwards->username, 'The first player is not renamed afterwards.');
        $this->assertTrue($firstAfterwards->hasRole('admin'), 'The first player keeps the administrator role afterwards.');

        // Champ par champ : les administrateurs sont exactement le compte système et le premier
        // inscrit. Une seconde règle qui promouvrait quelqu'un d'autre tomberait ici.
        $this->assertSame(
            [User::SYSTEM_ACCOUNT_USERNAME, 'PremierInscrit'],
            User::query()->role('admin')->orderBy('id')->pluck('username')->all(),
            'Exactly the system account and the first registered player hold the administrator role.'
        );
    }

    /**
     * Inscrit un joueur par le formulaire du jeu et rend le compte relu en base.
     *
     * @param string $username
     * @return User
     */
    private function registerAPlayer(string $username): User
    {
        // Être invité est un fait de départ, pas une espérance : sans cela `/register` répondrait
        // pour le joueur déjà connecté.
        $this->post('/logout');
        Auth::logout();
        $this->flushSession();
        $this->assertFalse(Auth::check(), 'The bench is still authenticated: the registration form would not be served.');

        $this->get('/login')->assertSee('data-panel="register"', false);

        $response = $this->post('/register', [
            '_token' => csrf_token(),
            'username' => $username,
            'email' => strtolower($username) . '@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'v' => '3',
            'step' => 'validate',
            'kid' => '',
            'errorCodeOn' => '1',
            'is_utf8' => '1',
            'agb' => 'on',
        ]);

        $response->assertStatus(302);
        $this->assertAuthenticated();

        // Relu en base : ce qui compte est ce que le jeu a écrit, pas ce que l'instance porte.
        return User::query()->findOrFail((int)Auth::id());
    }
}
