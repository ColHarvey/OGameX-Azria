<?php

namespace Tests\MariaDb;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use OGame\Factories\PlanetServiceFactory;
use OGame\Models\Planet;
use OGame\Models\User;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Le credit d'unites sous une vraie concurrence — ce que SQLite ne peut pas montrer.
 *
 * ## Ce que la suite ordinaire prouve deja, et qu'on ne refait pas ici
 *
 * `Tests\Feature\Combat\AtomicUnitCreditTest` etablit **le contrat** de façon deterministe : la
 * condition sur le proprietaire vit dans l'ecriture, un montant nul est refuse, et le modele en
 * memoire se relit sur la ligne. Elle tue meme le retour au lire-modifier-ecrire, en simulant
 * l'ecriture concurrente par une modification de la ligne apres le chargement.
 *
 * ## Ce qui reste, et qui appartient au bac
 *
 * Deux choses qu'une seule connexion ne peut pas dire.
 *
 * **Le niveau d'isolation.** Sous `REPEATABLE READ`, une lecture ordinaire repond depuis
 * l'instantane de la transaction : elle peut affirmer un proprietaire que la base n'a plus. Le
 * remboursement designait son silo ainsi. Une ecriture conditionnelle, elle, est une lecture
 * **courante** : elle voit l'etat reel et verrouille la ligne. Le premier essai epingle l'ecart
 * entre les deux — et c'est exactement la fenetre que Codex a vue en revue 105.
 *
 * **Deux ecrivains reels.** Deux creances distinctes visent le meme silo ; leurs verrous de creance
 * sont distincts, donc rien ne les serialise. Le second essai les fait tourner dans deux processus
 * et exige la somme.
 *
 * Sous SQLite ces deux essais seraient vides de sens : pas d'instantane a prendre en defaut, pas de
 * seconde connexion. Ils vivent donc hors des suites de `phpunit.xml`.
 */
#[Group('mariadb')]
final class UnitCreditUnderConcurrencyTest extends TestCase
{
    use RunsInParallelProcesses;

    /**
     * @var array<int, int>
     */
    private array $corpsCrees = [];

    /**
     * @var array<int, int>
     */
    private array $joueursCrees = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->requiresMariaDb();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::purge('mysql_temoin');

        if ($this->corpsCrees !== []) {
            DB::table('planets')->whereIn('id', $this->corpsCrees)->delete();
        }

        if ($this->joueursCrees !== []) {
            DB::table('users')->whereIn('id', $this->joueursCrees)->delete();
        }

        parent::tearDown();
    }

    /**
     * Un proprietaire lu dans l'instantane n'autorise pas un credit.
     *
     * C'est le defaut sous sa forme la plus nue : la transaction **croit** encore que le corps
     * appartient au creancier — sa propre lecture le lui dit —, et le credit refuse quand meme,
     * parce que la condition est dans l'ecriture et que l'ecriture lit le present.
     *
     * Sans ce temoin, on pourrait deplacer la condition vers une lecture prealable en croyant que
     * c'est equivalent. Ça ne l'est pas, et rien d'autre dans le depot ne le dit.
     */
    public function testAnOwnerReadFromTheSnapshotDoesNotAuthoriseACredit(): void
    {
        [$corps, $proprietaire] = $this->aBodyWithMissiles(7);
        $repreneur = $this->aPlayer();

        $service = resolve(PlanetServiceFactory::class)->make($corps, true);
        $this->assertNotNull($service);

        DB::beginTransaction();

        // Cette lecture fixe l'instantane de la transaction : tout ce qu'elle lira ensuite sera vu
        // dans cet etat-la, quoi qu'il arrive ailleurs.
        $this->assertSame(
            $proprietaire,
            (int)DB::table('planets')->where('id', $corps)->value('user_id'),
            'The body did not start out belonging to its owner.'
        );

        // Une autre transaction fait changer le corps de mains, et valide.
        $temoin = $this->secondConnection();
        $temoin->table('planets')->where('id', $corps)->update(['user_id' => $repreneur]);

        // **La lecture ordinaire ment encore**, et c'est legitime : c'est l'instantane. Ce temoin
        // est la moitie qui donne son sens a l'autre — sans lui, le refus ci-dessous pourrait etre
        // celui d'une lecture simplement a jour.
        $this->assertSame(
            $proprietaire,
            (int)DB::table('planets')->where('id', $corps)->value('user_id'),
            'The snapshot already saw the new owner: this engine does not have the flaw this test describes.'
        );

        // L'ecriture, elle, lit le present.
        $this->assertFalse(
            $service->addUnitAtomicIfStillOwnedBy('interplanetary_missile', 5, $proprietaire),
            'The credit trusted the snapshot and paid a body that had changed hands.'
        );

        DB::rollBack();

        $this->assertSame(7, $this->missilesOn($corps), 'The refused credit still wrote to the row.');
    }

    /**
     * Deux creances distinctes vers le meme silo conservent leur somme.
     *
     * Le verrou d'une creance ne protege que cette creance. Deux creances portent deux lignes
     * differentes, donc deux verrous differents, et rien ne les serialise : c'est le silo qu'elles
     * partagent. Un credit qui repartirait de la valeur lue en perdrait un des deux.
     */
    public function testTwoConcurrentCreditsToTheSameBodyBothLand(): void
    {
        $this->requiresProcesses();

        [$corps, $proprietaire] = $this->aBodyWithMissiles(100);

        $issues = $this->inParallel(2, static function (int $rang) use ($corps, $proprietaire): string {
            $service = resolve(PlanetServiceFactory::class)->make($corps, true);

            if ($service === null) {
                return 'corps introuvable';
            }

            // Des montants distincts : une somme de deux valeurs egales se retrouverait aussi bien
            // par un doublon que par deux credits, et l'essai ne saurait pas lequel il a vu.
            $montant = $rang === 0 ? 5 : 30;

            return $service->addUnitAtomicIfStillOwnedBy('interplanetary_missile', $montant, $proprietaire)
                ? 'credite=' . $montant
                : 'refuse';
        });

        sort($issues);
        $this->assertSame(['credite=30', 'credite=5'], $issues, 'One of the two credits was refused.');

        $this->assertSame(
            135,
            $this->missilesOn($corps),
            'The two credits did not both land: one read the value the other was about to overwrite.'
        );
    }

    private function missilesOn(int $bodyId): int
    {
        return (int)DB::table('planets')->where('id', $bodyId)->value('interplanetary_missile');
    }

    private function secondConnection(): Connection
    {
        config(['database.connections.mysql_temoin' => config('database.connections.mysql')]);
        $temoin = DB::connection('mysql_temoin');
        $temoin->statement('SET SESSION innodb_lock_wait_timeout = 1');

        return $temoin;
    }

    private function aPlayer(): int
    {
        $joueur = (int)User::factory()->create()->id;
        $this->joueursCrees[] = $joueur;

        return $joueur;
    }

    /**
     * @return array{0: int, 1: int} l'identifiant du corps, puis celui de son proprietaire
     */
    private function aBodyWithMissiles(int $missiles): array
    {
        $proprietaire = $this->aPlayer();

        // Une position libre, cherchee et non supposee : la base du bac est unique et partagee.
        $systeme = (int)DB::table('planets')->where('galaxy', 8)->max('system');
        $systeme = max($systeme, 0) + 1;

        $planete = Planet::factory()->create([
            'user_id' => $proprietaire,
            'galaxy' => 8,
            'system' => $systeme,
            'planet' => 1,
            'planet_type' => 1,
            'interplanetary_missile' => $missiles,
            // L'horloge de production est mise au futur : sinon la relecture du service ajouterait
            // la production ecoulee et les nombres attendus ne tiendraient plus.
            'time_last_update' => (int)now()->timestamp + 86_400,
        ]);

        $this->corpsCrees[] = (int)$planete->id;

        return [(int)$planete->id, $proprietaire];
    }
}
