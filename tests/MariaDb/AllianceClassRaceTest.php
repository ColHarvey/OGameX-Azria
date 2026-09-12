<?php

namespace Tests\MariaDb;

use Exception;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use OGame\Enums\AllianceClass;
use OGame\Enums\DarkMatterTransactionType;
use OGame\Models\Alliance;
use OGame\Models\AllianceMember;
use OGame\Models\User;
use OGame\Services\AllianceClassService;
use OGame\Services\AllianceService;
use OGame\Services\SettingsService;
use PHPUnit\Framework\Attributes\Group;
use stdClass;
use Tests\AccountTestCase;
use Tests\Support\DetachesFromAnyAlliance;

/**
 * Les courses des classes d alliance, entre processus reels.
 *
 * ## Ce que ce bac prouve, et que SQLite ne peut pas
 *
 * Le choix de classe decide sous le verrou de la ligne de l alliance, puis du compte ; la dissolution
 * prend desormais le meme ordre (journal §142). Sous SQLite `lockForUpdate()` ne compile a rien : les
 * essais locaux prouvent l ordre des requetes dans la transaction, jamais une course. Ici, des
 * processus reels se disputent les memes lignes.
 *
 * ## Des courses forcees, pas esperees
 *
 * Deux processus lances « ensemble » peuvent tres bien passer l un apres l autre, et la course n aurait
 * pas eu lieu. **Le parent tient la ligne de l alliance** sur une connexion a part, attend que les
 * enfants butent dessus — lu dans `PROCESSLIST`, pas suppose —, puis la rend : l ordre de passage est
 * alors celui du verrou, et un code qui ne le prendrait pas ne ferait attendre personne.
 *
 * ## Ce qui n a jamais tourne au moment de l ecrire
 *
 * Ce poste n a ni MariaDB ni `pcntl` : ces epreuves ont ete ecrites et controlees (syntaxe, PHPStan),
 * **pas executees**. Le workflow MariaDB les exige par leur nom (`--exige=`) ; c est le rapport JUnit
 * du run qui dira si elles ont tourne, et comment.
 */
#[Group('mariadb')]
final class AllianceClassRaceTest extends AccountTestCase
{
    use DetachesFromAnyAlliance;
    use RunsInParallelProcesses;

    /**
     * Les alliances fondees par l essai, defaites au demontage.
     *
     * @var list<int>
     */
    private array $alliances = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requiresMariaDb();
        $this->requiresProcesses();

        resolve(SettingsService::class)->set('alliance_classes_enabled', '1');
    }

    protected function tearDown(): void
    {
        DB::purge('mysql_temoin');
        DB::purge('mysql_sonde');

        foreach ($this->alliances as $alliance) {
            DB::table('users')->where('alliance_id', $alliance)->update(['alliance_id' => null]);
            AllianceMember::query()->where('alliance_id', $alliance)->delete();
            Alliance::query()->whereKey($alliance)->delete();
        }

        resolve(SettingsService::class)->set('alliance_classes_enabled', '0');

        parent::tearDown();
    }

    /**
     * **Deux premiers choix gratuits en meme temps : une seule classe offerte.**
     *
     * L alliance a quinze jours et aucune classe : le premier choix est gratuit. Le fondateur n a pas
     * un gramme de matiere noire. Si les deux achats lisaient l alliance hors verrou, les deux la
     * verraient sans classe et prendraient chacun le choix offert. Sous le verrou, le second relit la
     * classe posee par le premier : le prix redevient 400 000, et il est refuse.
     */
    public function testTwoFreeFirstChoicesAtOnceGrantOnlyOneFreeClass(): void
    {
        $this->detachFromAnyAlliance($this->currentUserId);
        $alliance = $this->uneAllianceFondee();
        $fondateur = $this->currentUserId;

        DB::table('alliances')->where('id', $alliance)->update([
            'created_at' => now()->subDays(AllianceClassService::FREE_FIRST_CHOICE_AFTER_DAYS + 1),
        ]);
        DB::table('users')->where('id', $fondateur)->update(['dark_matter' => 0]);

        $temoin = $this->uneConnexionTemoin();
        $temoin->beginTransaction();
        $this->assertNotNull($temoin->table('alliances')->where('id', $alliance)->lockForUpdate()->first());

        $issues = $this->inParallel(
            2,
            static function (int $rang) use ($fondateur, $alliance): string {
                $classe = $rang === 0 ? AllianceClass::WARRIORS : AllianceClass::TRADERS;

                try {
                    resolve(AllianceClassService::class)->choose(
                        User::query()->findOrFail($fondateur),
                        Alliance::query()->findOrFail($alliance),
                        $classe
                    );

                    return 'choisie:' . $classe->name;
                } catch (Exception $e) {
                    return 'refusee:' . $e->getMessage();
                }
            },
            function () use ($temoin): void {
                $this->waitUntil(
                    static fn (): bool => self::processusQuiAttendentLAlliance() >= 2,
                    'The two purchases did not both wait on the alliance row: the choice is not decided under its lock.'
                );
                $temoin->commit();
            }
        );

        $choisies = array_values(array_filter($issues, static fn (string $issue): bool => str_starts_with($issue, 'choisie:')));
        $refusees = array_values(array_filter($issues, static fn (string $issue): bool => str_starts_with($issue, 'refusee:')));

        $this->assertCount(1, $choisies, 'Both purchases got the free first choice, or neither did: ' . implode(' | ', $issues));
        $this->assertSame(
            ['refusee:' . __('t_ingame.alliance.class_not_enough_dark_matter', ['price' => number_format(AllianceClass::PRICE_IN_DARK_MATTER, 0, ',', '.')])],
            $refusees,
            'The losing purchase was not refused for its price: it did not re-read the class the winner set.'
        );

        $this->assertSame(
            substr($choisies[0], strlen('choisie:')),
            DB::table('alliances')->where('id', $alliance)->value('alliance_class'),
            'The stored class is not the one the winning purchase chose.'
        );
        $this->assertSame(0, (int)DB::table('users')->where('id', $fondateur)->value('dark_matter'), 'A free first choice was charged.');
        $this->assertSame(
            0,
            DB::table('dark_matter_transactions')
                ->where('user_id', $fondateur)
                ->where('type', DarkMatterTransactionType::ALLIANCE_CLASS->value)
                ->count(),
            'A free choice left a spending line in the dark matter ledger.'
        );
    }

    /**
     * **La dissolution attend l alliance avant de tenir le moindre compte.**
     *
     * Le parent tient l alliance ; la dissolution bute dessus. Pendant qu elle attend, une sonde — une
     * troisieme connexion qui n attend qu une seconde — verrouille les comptes du fondateur et du membre.
     * **Si la dissolution avait deja ecrit un compte**, elle en tiendrait le verrou exclusif et la sonde
     * tomberait en delai (erreur 1205) : c est l ordre inverse, celui qui s interbloquait avec un choix
     * de classe. Une ecriture non validee est invisible a une lecture ; un verrou, lui, se mesure.
     */
    public function testDisbandingWaitsOnTheAllianceBeforeLockingAnyAccount(): void
    {
        $this->detachFromAnyAlliance($this->currentUserId);
        $alliance = $this->uneAllianceFondee();
        $membre = $this->unMembreDe($alliance);
        $fondateur = $this->currentUserId;

        $temoin = $this->uneConnexionTemoin();
        $temoin->beginTransaction();
        $this->assertNotNull($temoin->table('alliances')->where('id', $alliance)->lockForUpdate()->first());

        // Un objet et non une variable capturee : PHPStan tiendrait une chaine capturee pour constante.
        $releve = new stdClass();
        $releve->attente = '';
        $releve->comptes = '';

        $issues = $this->inParallel(
            1,
            static function () use ($alliance, $fondateur): string {
                resolve(AllianceService::class)->disbandAlliance($alliance, $fondateur);

                return 'dissoute';
            },
            function () use ($temoin, $fondateur, $membre, $releve): void {
                $releve->attente = $this->waitUntilAProcessWaitsOnALockOn('alliances');
                $releve->comptes = $this->lesComptesSeVerrouillentEncore([$fondateur, $membre]);
                $temoin->commit();
            }
        );

        $this->assertStringContainsString('for update', strtolower($releve->attente), 'The disbanding did not wait on a locking read of the alliance.');
        $this->assertSame(
            'libres',
            $releve->comptes,
            'While disbanding waited on the alliance, it already held an account lock: the lock order is inverted.'
        );
        $this->assertSame(['dissoute'], $issues);

        $this->assertNull(Alliance::query()->find($alliance), 'The alliance survived its disbanding.');
        $this->assertNull(User::query()->findOrFail($fondateur)->alliance_id);
        $this->assertNull(User::query()->findOrFail($membre)->alliance_id);
    }

    /**
     * **Deux dissolutions en meme temps : les deux finissent, l alliance disparait une fois.**
     *
     * Les deux passent le controle de droit avant leur transaction, puis attendent l alliance. La
     * premiere dissout ; la seconde obtient le verrou sur une ligne supprimee.
     *
     * ## Ce que cette course ne tuera pas, et pourquoi
     *
     * La seconde sort par un retour anticipe quand l alliance a disparu. **Retirer ce retour ne change
     * rien d observable, meme ici** : les membres, les rangs et les candidatures sont supprimes en
     * cascade avec l alliance (cles etrangeres `onDelete('cascade')`), donc la seconde ne trouverait
     * aucun membre a detacher, et `Alliance::destroy()` sur une ligne absente ne fait rien. Le retour
     * est equivalent **sous cet invariant de schema** — pas par construction du code. Cette course
     * prouve l invariant qui compte : aucune erreur, aucun interblocage, une alliance disparue.
     */
    public function testTwoConcurrentDisbandsBothFinishAndLeaveTheAllianceGone(): void
    {
        $this->detachFromAnyAlliance($this->currentUserId);
        $alliance = $this->uneAllianceFondee();
        $membre = $this->unMembreDe($alliance);
        $fondateur = $this->currentUserId;

        $temoin = $this->uneConnexionTemoin();
        $temoin->beginTransaction();
        $this->assertNotNull($temoin->table('alliances')->where('id', $alliance)->lockForUpdate()->first());

        $issues = $this->inParallel(
            2,
            static function () use ($alliance, $fondateur): string {
                try {
                    resolve(AllianceService::class)->disbandAlliance($alliance, $fondateur);

                    return 'passe';
                } catch (Exception $e) {
                    return 'erreur:' . $e::class . ' : ' . $e->getMessage();
                }
            },
            function () use ($temoin): void {
                $this->waitUntil(
                    static fn (): bool => self::processusQuiAttendentLAlliance() >= 2,
                    'The two disbands did not both wait on the alliance row: the race would prove nothing.'
                );
                $temoin->commit();
            }
        );

        $this->assertSame(['passe', 'passe'], $issues, 'A concurrent disband failed instead of finding the alliance already gone.');
        $this->assertNull(Alliance::query()->find($alliance), 'The alliance survived two disbands.');
        $this->assertSame(0, AllianceMember::query()->where('alliance_id', $alliance)->count(), 'Members survived the alliance.');

        foreach ([$fondateur, $membre] as $compte) {
            $ligne = DB::table('users')->where('id', $compte)->first();
            $this->assertNotNull($ligne);
            $this->assertNull($ligne->alliance_id, 'A member still belongs to the disbanded alliance.');
            $this->assertNotNull($ligne->alliance_left_at, 'A member left without the departure being written.');
        }
    }

    /**
     * Le nombre d autres processus arretes au moins une seconde sur une lecture verrouillante de
     * l alliance.
     */
    private static function processusQuiAttendentLAlliance(): int
    {
        return count(DB::select(
            'SELECT ID FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() AND INFO LIKE ? AND TIME >= 1',
            ['%alliances%for update%']
        ));
    }

    /**
     * Une sonde : une connexion qui n attend qu une seconde, et tente de verrouiller ces comptes.
     *
     * @param list<int> $comptes
     */
    private function lesComptesSeVerrouillentEncore(array $comptes): string
    {
        config(['database.connections.mysql_sonde' => config('database.connections.mysql')]);
        $sonde = DB::connection('mysql_sonde');
        $sonde->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $sonde->beginTransaction();

        try {
            $sonde->table('users')->whereIn('id', $comptes)->lockForUpdate()->get();

            return 'libres';
        } catch (QueryException $e) {
            return 'tenus : ' . $e->getMessage();
        } finally {
            $sonde->rollBack();
        }
    }

    /**
     * La connexion du parent, a part de la connexion par defaut : la bifurcation ne la ferme pas.
     */
    private function uneConnexionTemoin(): Connection
    {
        config(['database.connections.mysql_temoin' => config('database.connections.mysql')]);

        return DB::connection('mysql_temoin');
    }

    private function uneAllianceFondee(): int
    {
        $alliance = resolve(AllianceService::class)->createAlliance(
            $this->currentUserId,
            'RC' . substr(md5(uniqid((string)mt_rand(), true)), 0, 5),
            'Course ' . substr(md5(uniqid((string)mt_rand(), true)), 0, 8)
        );

        $this->alliances[] = (int)$alliance->id;

        return (int)$alliance->id;
    }

    /**
     * Un membre qui entre par le chemin du jeu : il postule, le fondateur accepte.
     */
    private function unMembreDe(int $alliance): int
    {
        $membre = User::factory()->create();
        $service = resolve(AllianceService::class);
        $candidature = $service->applyToAlliance((int)$membre->id, $alliance);
        $service->acceptApplication((int)$candidature->id, $this->currentUserId);

        return (int)$membre->id;
    }
}
