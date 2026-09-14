<?php

namespace Tests\MariaDb;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Factories\PlanetServiceFactory;
use OGame\Military\MilitaryTallyAggregator;
use OGame\Military\MilitaryTallyRecorder;
use OGame\Military\MilitaryValue;
use OGame\Models\User;
use OGame\Services\InitialUserDataService;
use OGame\Services\ObjectService;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

/**
 * **L'agrégation des cumuls militaires sous une vraie concurrence, sur le schéma réel.**
 *
 * La suite ordinaire éprouve la règle sur une seule connexion (`MilitaryTalliesAggregationTest`) : relecture,
 * revérification, crédit et marque dans une transaction. Ce qu'une seule connexion ne peut pas dire vit ici.
 *
 * 1. **Deux agrégateurs concurrents** : le second bute réellement sur les lignes que le premier tient — l'attente est
 *    lue dans la base —, puis les relit comptées et les laisse. Chaque événement est compté une fois.
 * 2. **Une interruption avant la validation** : le processus qui agrège est tué au milieu de sa transaction, crédit et
 *    marque écrits. La base ne garde ni l'un ni l'autre ; l'agrégation suivante compte une fois.
 * 3. **Une livraison pendant l'agrégation** : un corps livre ses unités et inscrit sa tranche pendant qu'une
 *    agrégation tient les événements et le compteur du même joueur. La livraison ne l'attend pas — c'est l'affirmation
 *    sur les index et les verrous du registre que cette course éprouve — et rien n'est perdu.
 */
#[Group('mariadb')]
final class MilitaryTallyAggregationRaceTest extends TestCase
{
    use RunsInParallelProcesses;

    private const string PREFIXE = 'course-agregation:';

    /** @var list<int> */
    private array $joueurs = [];

    /** @var list<int> */
    private array $lots = [];

    private string $dossier = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->requiresMariaDb();

        DB::table('settings')->where('key', MilitaryTallyRecorder::SINCE_KEY)->delete();
        DB::table('settings')->insert([
            'key' => MilitaryTallyRecorder::SINCE_KEY,
            'value' => (string)((int)Date::now()->timestamp - 10_000),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->dossier = sys_get_temp_dir() . '/ogamex-agregation-' . bin2hex(random_bytes(6));
        mkdir($this->dossier, 0700, true);
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::table('settings')->where('key', MilitaryTallyRecorder::SINCE_KEY)->delete();

        if ($this->joueurs !== []) {
            DB::table('military_tally_events')->whereIn('player_id', $this->joueurs)->delete();
            DB::table('military_tallies')->whereIn('player_id', $this->joueurs)->delete();
        }

        if ($this->lots !== []) {
            DB::table('unit_queues')->whereIn('id', $this->lots)->delete();
        }

        parent::tearDown();
    }

    public function testTwoConcurrentAggregatorsCountEachEventExactlyOnce(): void
    {
        $this->requiresProcesses();

        $attendus = [];
        foreach ([1, 2, 3] as $rang) {
            $joueur = $this->unJoueur();
            $attendus[$joueur] = 0;

            for ($i = 0; $i < 30; $i++) {
                $valeur = $rang * 1_000 + $i;
                $this->inscrire($joueur, sprintf('%03d', $i), $valeur);
                $attendus[$joueur] += $valeur;
            }
        }

        $dossier = $this->dossier;
        $premierJoueur = min(array_keys($attendus));

        $issues = $this->inParallel(2, function (int $rang) use ($dossier, $premierJoueur): string {
            if ($rang === 0) {
                // **Le premier agrégateur tient ses lignes** : il s'arrête dans sa transaction, crédit et marque écrits,
                // jusqu'à ce que le parent ait vu le second buter dessus.
                $agregation = new MilitaryTallyAggregator(beforeCommit: function (int $joueur, array $clefs) use ($dossier, $premierJoueur): void {
                    if ($joueur !== $premierJoueur) {
                        return;
                    }

                    touch($dossier . '/tenue');
                    $this->waitForSignalFile($dossier . '/relache');
                });

                return 'comptes=' . $agregation->aggregate();
            }

            $this->waitForSignalFile($dossier . '/go-1');

            return 'comptes=' . (new MilitaryTallyAggregator())->aggregate();
        }, function () use ($dossier): void {
            try {
                $this->waitForSignalFile($dossier . '/tenue');
                touch($dossier . '/go-1');

                // L'attente est un fait de la base, pas une supposition : sans elle, rien n'aurait chevauché.
                $vue = $this->waitUntilAProcessWaitsOnALockOn('military_tally_events');
                $this->assertStringContainsString('military_tally_events', $vue);
            } finally {
                touch($dossier . '/relache');
            }
        });

        $this->assertCount(2, $issues);

        foreach ($attendus as $joueur => $somme) {
            $this->assertSame($somme, $this->construits($joueur), "Le compteur du joueur $joueur ne vaut pas exactement ses événements : l’un a été compté deux fois, ou pas du tout.");
            $this->assertSame(0, $this->nonMarques($joueur), "Des événements du joueur $joueur sont restés non marqués.");
        }
    }

    public function testAnAggregatorKilledBeforeItsCommitLeavesNeitherCreditNorMark(): void
    {
        $this->requiresProcesses();

        $joueur = $this->unJoueur();
        $somme = 0;
        for ($i = 0; $i < 5; $i++) {
            $this->inscrire($joueur, (string)$i, 100 + $i);
            $somme += 100 + $i;
        }

        $dossier = $this->dossier;

        DB::disconnect();
        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('The process could not fork.');
        }

        if ($pid === 0) {
            try {
                DB::reconnect();

                (new MilitaryTallyAggregator(beforeCommit: static function (int $compte, array $clefs) use ($dossier, $joueur): void {
                    if ($compte !== $joueur) {
                        return;
                    }

                    // Le crédit et la marque sont écrits, la transaction n'est pas validée : le processus meurt ici.
                    touch($dossier . '/atteinte');
                    posix_kill(posix_getpid(), defined('SIGKILL') ? SIGKILL : 9);
                }))->aggregate();
            } finally {
                posix_kill(posix_getpid(), defined('SIGKILL') ? SIGKILL : 9);
            }

            exit(0);
        }

        $statut = 0;
        pcntl_waitpid($pid, $statut);
        DB::reconnect();

        $this->assertFileExists($dossier . '/atteinte', 'Le processus n’a pas atteint la validation : l’interruption n’a pas eu lieu dans la transaction.');
        $this->assertTrue(pcntl_wifsignaled($statut), 'Le processus d’agrégation n’a pas été tué.');

        $this->assertSame(0, $this->construits($joueur), 'Une agrégation tuée avant sa validation a laissé son crédit.');
        $this->assertSame(5, $this->nonMarques($joueur), 'Une agrégation tuée avant sa validation a laissé ses marques : ces événements ne seraient plus jamais comptés.');

        (new MilitaryTallyAggregator())->aggregate();
        (new MilitaryTallyAggregator())->aggregate();

        $this->assertSame($somme, $this->construits($joueur), 'Après l’interruption, les événements ne sont pas comptés exactement une fois.');
        $this->assertSame(0, $this->nonMarques($joueur));
    }

    public function testADeliveryDuringAnAggregationNeitherWaitsForItNorIsLost(): void
    {
        $this->requiresProcesses();

        [$joueur, $corps] = $this->unJoueurAvecUnCorps();
        foreach (['a', 'b', 'c'] as $nom) {
            $this->inscrire($joueur, $nom, 10);
        }

        $maintenant = (int)Date::now()->timestamp;
        $avant = (int)DB::table('planets')->where('id', $corps)->value('rocket_launcher');
        $lot = (int)DB::table('unit_queues')->insertGetId([
            'planet_id' => $corps,
            'object_id' => ObjectService::getUnitObjectByMachineName('rocket_launcher')->id,
            'object_amount' => 12,
            'time_duration' => 60,
            'time_start' => $maintenant - 100,
            'time_end' => $maintenant - 40,
            'time_progress' => 0,
            'object_amount_progress' => 0,
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
            'processed' => 0,
        ]);
        $this->lots[] = $lot;

        $dossier = $this->dossier;

        $this->inParallel(2, function (int $rang) use ($dossier, $joueur, $corps): string {
            if ($rang === 0) {
                // L'agrégation tient les trois événements du joueur et sa ligne de compteur, sans valider.
                $agregation = new MilitaryTallyAggregator(beforeCommit: function (int $compte, array $clefs) use ($dossier, $joueur): void {
                    if ($compte !== $joueur) {
                        return;
                    }

                    touch($dossier . '/tenue');
                    $this->waitForSignalFile($dossier . '/relache');
                });

                return 'comptes=' . $agregation->aggregate();
            }

            $this->waitForSignalFile($dossier . '/go-1');

            $planete = resolve(PlanetServiceFactory::class)->make($corps, true);
            if ($planete === null) {
                throw new RuntimeException('Le corps ' . $corps . ' est introuvable.');
            }

            $planete->update();
            touch($dossier . '/livre');

            return 'livre';
        }, function () use ($dossier): void {
            try {
                $this->waitForSignalFile($dossier . '/tenue');
                touch($dossier . '/go-1');

                // **La livraison doit aboutir pendant que l'agrégation tient ses verrous.** Si elle butait sur eux, ce
                // signal ne viendrait qu'après la libération — et le parent ne la donne qu'après l'avoir reçu.
                $this->waitForSignalFile($dossier . '/livre', 20.0);
            } finally {
                touch($dossier . '/relache');
            }
        });

        (new MilitaryTallyAggregator())->aggregate();
        (new MilitaryTallyAggregator())->aggregate();

        $this->assertSame($avant + 12, (int)DB::table('planets')->where('id', $corps)->value('rocket_launcher'), 'La livraison a perdu ou dupliqué des unités.');
        $this->assertSame(12, (int)DB::table('unit_queues')->where('id', $lot)->value('object_amount_progress'));
        $this->assertSame(
            30 + MilitaryValue::ofObject(ObjectService::getUnitObjectByMachineName('rocket_launcher'), 12),
            $this->construits($joueur),
            'Le compteur ne vaut pas les événements d’avant plus la tranche livrée pendant l’agrégation.'
        );
        $this->assertSame(0, $this->nonMarques($joueur));
    }

    private function unJoueur(): int
    {
        $compte = User::factory()->create();
        $this->joueurs[] = (int)$compte->id;

        return (int)$compte->id;
    }

    /**
     * @return array{0: int, 1: int} le joueur, puis son premier corps
     */
    private function unJoueurAvecUnCorps(): array
    {
        $compte = User::factory()->create();

        if ($compte->hasRole('admin')) {
            $compte->removeRole('admin');
        }

        resolve(InitialUserDataService::class)->createFor($compte);
        $this->joueurs[] = (int)$compte->id;

        return [(int)$compte->id, (int)DB::table('planets')->where('user_id', $compte->id)->orderBy('id')->value('id')];
    }

    private function inscrire(int $joueur, string $nom, int $valeur): void
    {
        $this->assertTrue(
            resolve(MilitaryTallyRecorder::class)->credit(self::PREFIXE . $joueur . ':' . $nom, $joueur, (int)Date::now()->timestamp, built: $valeur),
            'Prémisse : l’événement est inscrit.'
        );
    }

    private function construits(int $joueur): int
    {
        return (int)(DB::table('military_tallies')->where('player_id', $joueur)->value('built_value') ?? 0);
    }

    private function nonMarques(int $joueur): int
    {
        return DB::table('military_tally_events')->where('player_id', $joueur)->whereNull('aggregated_at')->count();
    }
}
