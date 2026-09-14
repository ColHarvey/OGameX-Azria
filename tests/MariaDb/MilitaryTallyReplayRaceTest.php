<?php

namespace Tests\MariaDb;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Military\MilitaryTallyAggregator;
use OGame\Military\MilitaryTallyRecorder;
use OGame\Military\MilitaryTallyReplay;
use OGame\Military\MilitaryValue;
use OGame\Models\User;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * **La reprise des événements en attente sous une vraie concurrence, sur le schéma réel.**
 *
 * 1. **Deux reprises concurrentes** : la seconde bute réellement sur l'événement que la première tient — l'attente est
 *    lue dans la base —, le relit repris et le laisse. Chaque événement est évalué une fois.
 * 2. **Une reprise pendant une agrégation du même joueur** : l'agrégation tient ses événements appliqués et sa ligne de
 *    compteur ; la reprise d'un de ses événements en attente aboutit sans l'attendre, et rien n'est compté deux fois.
 */
#[Group('mariadb')]
final class MilitaryTallyReplayRaceTest extends TestCase
{
    use RunsInParallelProcesses;

    private const string PREFIXE = 'course-reprise:';

    /** @var list<int> */
    private array $joueurs = [];

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

        $this->dossier = sys_get_temp_dir() . '/ogamex-reprise-' . bin2hex(random_bytes(6));
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

        parent::tearDown();
    }

    public function testTwoConcurrentReplaysEvaluateEachPendingEventOnce(): void
    {
        $this->requiresProcesses();

        $joueur = $this->unJoueur();
        for ($i = 0; $i < 20; $i++) {
            $this->enAttente($joueur, sprintf('%03d', $i), 1_000 + $i);
        }

        $dossier = $this->dossier;
        $premiere = self::PREFIXE . $joueur . ':000';

        $this->inParallel(2, function (int $rang) use ($dossier, $premiere): string {
            if ($rang === 0) {
                // La première reprise tient le premier événement, évalué et écrit, sans valider.
                $reprise = new MilitaryTallyReplay(beforeCommit: function (string $clef) use ($dossier, $premiere): void {
                    if ($clef !== $premiere) {
                        return;
                    }

                    touch($dossier . '/tenue');
                    $this->waitForSignalFile($dossier . '/relache');
                });

                return json_encode($reprise->replay()) ?: '';
            }

            $this->waitForSignalFile($dossier . '/go-1');

            return json_encode((new MilitaryTallyReplay())->replay()) ?: '';
        }, function () use ($dossier): void {
            try {
                $this->waitForSignalFile($dossier . '/tenue');
                touch($dossier . '/go-1');

                $vue = $this->waitUntilAProcessWaitsOnALockOn('military_tally_events');
                $this->assertStringContainsString('military_tally_events', $vue);
            } finally {
                touch($dossier . '/relache');
            }
        });

        $attendu = 0;
        for ($i = 0; $i < 20; $i++) {
            $ligne = DB::table('military_tally_events')->where('event_key', self::PREFIXE . $joueur . ':' . sprintf('%03d', $i))->first();
            $this->assertNotNull($ligne);
            $this->assertSame(MilitaryTallyRecorder::APPLIED, $ligne->status, "L’événement $i n’a pas été repris.");
            $this->assertSame((1_000 + $i) * 2, (int)$ligne->built_value, "L’événement $i n’a pas la valeur de sa version et de son prix gardé.");
            $attendu += (1_000 + $i) * 2;
        }

        (new MilitaryTallyAggregator())->aggregate();
        (new MilitaryTallyAggregator())->aggregate();

        $this->assertSame($attendu, (int)DB::table('military_tallies')->where('player_id', $joueur)->value('built_value'), 'Un événement a été compté deux fois, ou pas du tout.');
    }

    public function testAReplayDuringAnAggregationOfTheSamePlayerNeitherWaitsNorDoublesAnything(): void
    {
        $this->requiresProcesses();

        $joueur = $this->unJoueur();
        foreach (['a', 'b', 'c'] as $nom) {
            $this->assertTrue(resolve(MilitaryTallyRecorder::class)->credit(self::PREFIXE . $joueur . ':applique-' . $nom, $joueur, (int)Date::now()->timestamp, built: 10));
        }
        $clefEnAttente = $this->enAttente($joueur, 'attente', 3_000);

        $dossier = $this->dossier;

        $this->inParallel(2, function (int $rang) use ($dossier, $joueur): string {
            if ($rang === 0) {
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
            $bilan = (new MilitaryTallyReplay())->replay();
            touch($dossier . '/repris');

            return json_encode($bilan) ?: '';
        }, function () use ($dossier): void {
            try {
                $this->waitForSignalFile($dossier . '/tenue');
                touch($dossier . '/go-1');

                // La reprise doit aboutir pendant que l'agrégation tient les lignes du même joueur.
                $this->waitForSignalFile($dossier . '/repris', 20.0);
            } finally {
                touch($dossier . '/relache');
            }
        });

        $this->assertSame(MilitaryTallyRecorder::APPLIED, DB::table('military_tally_events')->where('event_key', $clefEnAttente)->value('status'));

        (new MilitaryTallyAggregator())->aggregate();
        (new MilitaryTallyAggregator())->aggregate();

        $this->assertSame(30 + 3_000 * 2, (int)DB::table('military_tallies')->where('player_id', $joueur)->value('built_value'), 'Le compteur ne vaut pas les trois événements appliqués plus l’événement repris.');
    }

    private function unJoueur(): int
    {
        $compte = User::factory()->create();
        $this->joueurs[] = (int)$compte->id;

        return (int)$compte->id;
    }

    private function enAttente(int $joueur, string $nom, int $prix): string
    {
        $clef = self::PREFIXE . $joueur . ':' . $nom;

        DB::table('military_tally_events')->insert([
            'event_key' => $clef,
            'player_id' => $joueur,
            'status' => MilitaryTallyRecorder::PENDING,
            'reason' => 'unknown_unit_family',
            'payload' => json_encode(['kind' => 'build', 'part' => 'built', 'queue_id' => 0, 'object' => 'rocket_launcher', 'from' => 0, 'to' => 1, 'raw_price' => $prix]),
            'weighting_version' => MilitaryValue::WEIGHTING_VERSION,
            'built_value' => 0,
            'destroyed_value' => 0,
            'lost_value' => 0,
            'recorded_at' => (int)Date::now()->timestamp,
            'aggregated_at' => null,
            'resolved_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $clef;
    }
}
