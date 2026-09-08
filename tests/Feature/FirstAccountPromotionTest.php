<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use OGame\Models\User;
use Tests\TestCase;

/**
 * Le premier compte d'une base devient l'administrateur, et lui seul.
 *
 * **Une règle du jeu, pas du banc.** Le crochet `created` de `User` promeut le premier compte qui
 * n'est pas Legor : rôle `admin` et nom « Admin », quel que soit le pseudo choisi. C'est ainsi que
 * le premier inscrit d'une installation neuve devient son administrateur. Les bancs de tests
 * neutralisent cette promotion pour jouer un joueur ordinaire ; sans un témoin à part, un crochet
 * supprimé ne ferait rougir aucun essai.
 *
 * **Une base isolée, à ce seul essai.** La base d'un processus est partagée et porte déjà des
 * comptes quand l'essai tourne, et la règle ne se déclenche que sur une base sans autre compte.
 * L'essai migre donc une base SQLite en mémoire, la sienne, et n'écrit dans aucune autre : les
 * comptes des essais voisins ne sont ni lus ni touchés. La migration des rôles y pose Legor, le
 * compte système, administrateur par SQL brut : c'est exactement l'état d'une installation neuve
 * au moment de la première inscription.
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
     * Le premier compte reçoit le rôle et le nom ; le suivant ne reçoit rien et garde le sien.
     */
    public function testTheFirstAccountBecomesTheAdministratorAndTheNextOneDoesNot(): void
    {
        // Prémisse : la base ne porte que le compte système, que la règle ignore par son nom. S'il
        // comptait comme un « autre » compte, personne ne serait jamais promu.
        $this->assertSame(
            [User::SYSTEM_ACCOUNT_USERNAME],
            User::query()->orderBy('id')->pluck('username')->all(),
            'The isolated database must hold only the system account before the first registration.'
        );

        $first = User::factory()->create(['username' => 'PremierInscrit']);

        // L'instance rendue par la création porte déjà le nom : le courriel de bienvenue et les
        // données initiales tournent sur le compte renommé, sans rechargement.
        $this->assertSame('Admin', $first->username, 'The instance returned by the creation already carries the administrator name.');
        $this->assertTrue($first->hasRole('admin'), 'The first account holds the administrator role.');

        $firstReloaded = User::query()->findOrFail($first->id);
        $this->assertSame('Admin', $firstReloaded->username, 'The administrator name is written to the database.');
        $this->assertTrue($firstReloaded->hasRole('admin'), 'The role is written to the database, not only held by the instance.');

        $second = User::factory()->create(['username' => 'SecondInscrit']);

        $this->assertSame('SecondInscrit', $second->username, 'The second account keeps the name it chose.');
        $this->assertFalse($second->hasRole('admin'), 'The second account is not promoted.');
        $this->assertSame('SecondInscrit', User::query()->findOrFail($second->id)->username);
        $this->assertFalse(User::query()->findOrFail($second->id)->hasRole('admin'));

        // Champ par champ : les administrateurs sont exactement le compte système et le premier inscrit.
        $this->assertSame(
            [User::SYSTEM_ACCOUNT_USERNAME, 'Admin'],
            User::query()->role('admin')->orderBy('id')->pluck('username')->all(),
            'Exactly the system account and the first registered account hold the administrator role.'
        );
    }
}
