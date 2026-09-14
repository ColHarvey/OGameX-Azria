<?php

namespace Tests\MariaDb;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Enums\DarkMatterTransactionType;
use OGame\Factories\PlanetServiceFactory;
use OGame\Military\MilitaryTallyAggregator;
use OGame\Military\MilitaryTallyRecorder;
use OGame\Military\MilitaryValue;
use OGame\Models\User;
use OGame\Services\HalvingService;
use OGame\Services\InitialUserDataService;
use OGame\Services\ObjectService;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

/**
 * **Demi-temps contre progression de la file : un dénouement normal, pas seulement l'absence d'interblocage.**
 *
 * Les deux chemins prennent désormais le même ordre : le demi-temps tient le compte, puis le corps, puis sa ligne ;
 * la progression tient le corps, puis ses lignes. Cette course exige ce que cet ordre doit donner :
 *
 * - **les unités payées sont conservées** : ce que le corps a reçu vaut exactement l'avancement de la ligne ;
 * - **aucune unité perdue ni dupliquée** ;
 * - **la matière noire est débitée une fois**, au coût du demi-temps réellement appliqué ;
 * - **aucun double crédit** : les tranches inscrites couvrent l'avancement sans chevauchement, et le compteur vaut
 *   exactement les unités achevées ;
 * - **les deux gestes aboutissent** : un enfant qui recevrait une erreur 1213 la rapporterait, et l'essai tomberait.
 *
 * ## Deux ordres forcés, puis simultané
 *
 * Le parent tient la ligne du corps. Le premier acteur part et bute dessus ; le second part et bute derrière lui ; le
 * parent relâche. Le moteur accorde le verrou dans l'ordre de la file d'attente : l'ordre est forcé, **et vérifié** —
 * l'état final doit être celui du même enchaînement joué en série sur un corps jumeau.
 *
 * Les deux gestes portent des **instants différents** (progression à +305, demi-temps à +310) : les deux ordres
 * donnent des états distincts — tranches `0-50`/`50-80` ou `0-30`/`30-80`, horloge de progression différente —, et
 * l'état final dit lequel a gagné. Le départ simultané, lui, ne force rien : il doit rendre l'un des deux états
 * de référence, et tenir le dénouement normal.
 */
#[Group('mariadb')]
final class HalvingVersusUnitProgressionRaceTest extends TestCase
{
    use RunsInParallelProcesses;

    private const string DEMI_TEMPS = 'demi-temps';

    private const string PROGRESSION = 'progression';

    /** La progression de page passe à +305 : trente chasseurs sont achevés sur le lot d'origine. */
    private const int INSTANT_PROGRESSION = 305;

    /** Le demi-temps s'applique à +310 : il retire 500 secondes et livre cinquante chasseurs. */
    private const int INSTANT_DEMI_TEMPS = 310;

    private int $base = 0;

    private string $dossier = '';

    /** @var list<array{joueur: int, corps: int, lot: int, unites: int}> */
    private array $scenes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->requiresMariaDb();

        $this->base = (int)Date::now()->timestamp;

        DB::table('settings')->where('key', MilitaryTallyRecorder::SINCE_KEY)->delete();
        DB::table('settings')->insert([
            'key' => MilitaryTallyRecorder::SINCE_KEY,
            'value' => (string)($this->base - 10_000),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->dossier = sys_get_temp_dir() . '/ogamex-demi-temps-' . bin2hex(random_bytes(6));
        mkdir($this->dossier, 0700, true);
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::purge('mysql_verrou');
        DB::table('settings')->where('key', MilitaryTallyRecorder::SINCE_KEY)->delete();

        foreach ($this->scenes as $scene) {
            DB::table('military_tally_events')->where('player_id', $scene['joueur'])->delete();
            DB::table('military_tallies')->where('player_id', $scene['joueur'])->delete();
            DB::table('unit_queues')->where('id', $scene['lot'])->delete();
        }

        parent::tearDown();
    }

    public function testTheHalvingFirstThenTheProgressionEndsNormally(): void
    {
        $this->forcedOrder([self::DEMI_TEMPS, self::PROGRESSION]);
    }

    public function testTheProgressionFirstThenTheHalvingEndsNormally(): void
    {
        $this->forcedOrder([self::PROGRESSION, self::DEMI_TEMPS]);
    }

    public function testTheHalvingAndTheProgressionStartedTogetherEndNormally(): void
    {
        $this->requiresProcesses();

        $references = [
            $this->serialReference([self::DEMI_TEMPS, self::PROGRESSION]),
            $this->serialReference([self::PROGRESSION, self::DEMI_TEMPS]),
        ];

        $scene = $this->uneScene();
        $gestes = [self::DEMI_TEMPS, self::PROGRESSION];

        $issues = $this->inParallel(2, fn (int $rang): string => $this->agir($gestes[$rang], $scene));

        $etat = $this->etat($scene);
        $this->assertUnDenouementNormal($etat, $this->coutRapporte($issues), 'départ simultané');
        $this->assertContains($etat, $references, 'Le départ simultané a donné un état qu’aucun enchaînement en série ne donne.');
    }

    /**
     * @param array{0: string, 1: string} $ordre
     */
    private function forcedOrder(array $ordre): void
    {
        $this->requiresProcesses();

        $reference = $this->serialReference($ordre);
        $autre = $this->serialReference([$ordre[1], $ordre[0]]);
        $this->assertNotSame($reference, $autre, 'Les deux ordres donnent le même état : l’état final ne dirait pas lequel a gagné.');

        $scene = $this->uneScene();
        $dossier = $this->dossier;

        // Le parent tient le corps : les deux acteurs buteront dessus, dans l'ordre où ils partent.
        $verrou = $this->uneConnexionDeVerrou();
        $verrou->beginTransaction();
        $verrou->select('select id from planets where id = ? for update', [$scene['corps']]);

        $issues = $this->inParallel(2, function (int $rang) use ($ordre, $scene, $dossier): string {
            $this->waitForSignalFile($dossier . '/go-' . $rang);

            return $this->agir($ordre[$rang], $scene);
        }, function () use ($dossier, $verrou): void {
            try {
                touch($dossier . '/go-0');
                $this->waitUntilProcessesWaitOn('planets', 1);
                touch($dossier . '/go-1');
                $this->waitUntilProcessesWaitOn('planets', 2);
            } finally {
                $verrou->rollBack();
            }
        });

        $etat = $this->etat($scene);
        $contexte = implode(' puis ', $ordre);

        $this->assertUnDenouementNormal($etat, $this->coutRapporte($issues), $contexte);
        $this->assertSame($reference, $etat, "$contexte : l’état final n’est pas celui du même enchaînement joué en série.");
    }

    /**
     * Le même enchaînement, joué en série par le parent sur un corps jumeau.
     *
     * @param array{0: string, 1: string} $ordre
     * @return array<string, mixed>
     */
    private function serialReference(array $ordre): array
    {
        $scene = $this->uneScene();
        $issues = [];

        foreach ($ordre as $geste) {
            $issues[] = $this->agir($geste, $scene);
        }

        Date::setTestNow();

        $etat = $this->etat($scene);
        $this->assertUnDenouementNormal($etat, $this->coutRapporte($issues), 'référence ' . implode(' puis ', $ordre));

        return $etat;
    }

    /**
     * @param array{joueur: int, corps: int, lot: int, unites: int} $scene
     */
    private function agir(string $geste, array $scene): string
    {
        $corps = resolve(PlanetServiceFactory::class)->make($scene['corps'], true);

        if ($corps === null) {
            throw new RuntimeException('Le corps ' . $scene['corps'] . ' est introuvable.');
        }

        if ($geste === self::DEMI_TEMPS) {
            Date::setTestNow(Date::createFromTimestamp($this->base + self::INSTANT_DEMI_TEMPS));
            $compte = User::query()->findOrFail($scene['joueur']);

            return 'cout=' . resolve(HalvingService::class)->halveUnit($compte, $scene['lot'], $corps)['cost'];
        }

        Date::setTestNow(Date::createFromTimestamp($this->base + self::INSTANT_PROGRESSION));
        $corps->update();

        return self::PROGRESSION;
    }

    /**
     * Un joueur réel, son corps, de quoi payer, et cent chasseurs à dix secondes chacun à partir de la base.
     *
     * @return array{joueur: int, corps: int, lot: int, unites: int}
     */
    private function uneScene(): array
    {
        $compte = User::factory()->create();

        if ($compte->hasRole('admin')) {
            $compte->removeRole('admin');
        }

        resolve(InitialUserDataService::class)->createFor($compte);

        $corps = (int)DB::table('planets')->where('user_id', $compte->id)->orderBy('id')->value('id');
        DB::table('users')->where('id', $compte->id)->update(['dark_matter' => 1_000_000]);

        $lot = (int)DB::table('unit_queues')->insertGetId([
            'planet_id' => $corps,
            'object_id' => ObjectService::getUnitObjectByMachineName('light_fighter')->id,
            'object_amount' => 100,
            'time_duration' => 1_000,
            'time_start' => $this->base,
            'time_end' => $this->base + 1_000,
            'time_progress' => 0,
            'object_amount_progress' => 0,
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
            'processed' => 0,
        ]);

        $scene = [
            'joueur' => (int)$compte->id,
            'corps' => $corps,
            'lot' => $lot,
            'unites' => (int)DB::table('planets')->where('id', $corps)->value('light_fighter'),
        ];

        $this->scenes[] = $scene;

        return $scene;
    }

    /**
     * Ce qui décide du dénouement, sans aucun identifiant : deux scènes jumelles doivent pouvoir se comparer.
     *
     * @param array{joueur: int, corps: int, lot: int, unites: int} $scene
     * @return array<string, mixed>
     */
    private function etat(array $scene): array
    {
        resolve(MilitaryTallyAggregator::class)->aggregate();
        resolve(MilitaryTallyAggregator::class)->aggregate();

        $ligne = DB::table('unit_queues')->where('id', $scene['lot'])->first(['object_amount_progress', 'time_start', 'time_end', 'time_progress', 'processed', 'dm_halved']);
        $this->assertNotNull($ligne);

        $tranches = [];
        foreach (DB::table('military_tally_events')->where('event_key', 'like', 'build:' . $scene['lot'] . ':%')->get(['event_key', 'built_value']) as $evenement) {
            [$de, $a] = array_map('intval', explode('-', substr((string)$evenement->event_key, strlen('build:' . $scene['lot'] . ':'))));
            $tranches[] = ['de' => $de, 'a' => $a, 'valeur' => (int)$evenement->built_value];
        }
        usort($tranches, static fn (array $x, array $y): int => $x['de'] <=> $y['de']);

        return [
            'unites_livrees' => (int)DB::table('planets')->where('id', $scene['corps'])->value('light_fighter') - $scene['unites'],
            'avancement' => (int)$ligne->object_amount_progress,
            'debut' => (int)$ligne->time_start - $this->base,
            'fin' => (int)$ligne->time_end - $this->base,
            'horloge_de_progression' => (int)$ligne->time_progress - $this->base,
            'traite' => (int)$ligne->processed,
            'demi_temps' => (int)$ligne->dm_halved,
            'matiere_noire_debitee' => 1_000_000 - (int)DB::table('users')->where('id', $scene['joueur'])->value('dark_matter'),
            'debits' => DB::table('dark_matter_transactions')->where('user_id', $scene['joueur'])->where('type', DarkMatterTransactionType::HALVING->value)->count(),
            'tranches' => $tranches,
            'compteur' => (int)(DB::table('military_tallies')->where('player_id', $scene['joueur'])->value('built_value') ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $etat
     */
    private function assertUnDenouementNormal(array $etat, int $coutRapporte, string $contexte): void
    {
        $this->assertSame(80, $etat['avancement'], "$contexte : l’avancement n’est pas celui des deux gestes appliqués.");
        $this->assertSame($etat['avancement'], $etat['unites_livrees'], "$contexte : le corps n’a pas reçu exactement l’avancement — des unités payées sont perdues ou dupliquées.");
        $this->assertSame(1, $etat['debits'], "$contexte : la matière noire n’a pas été débitée exactement une fois.");
        $this->assertSame($coutRapporte, $etat['matiere_noire_debitee'], "$contexte : le débit ne vaut pas le coût du demi-temps appliqué.");

        $borne = 0;
        $tranches = $etat['tranches'];
        $this->assertIsArray($tranches);

        foreach ($tranches as $tranche) {
            $this->assertSame($borne, $tranche['de'], "$contexte : deux tranches se chevauchent, ou l’une manque.");
            $this->assertGreaterThan($tranche['de'], $tranche['a']);
            $borne = $tranche['a'];
        }

        $this->assertSame($etat['avancement'], $borne, "$contexte : les tranches inscrites ne couvrent pas l’avancement.");
        $this->assertSame(
            MilitaryValue::ofObject(ObjectService::getUnitObjectByMachineName('light_fighter'), (int)$etat['avancement']),
            $etat['compteur'],
            "$contexte : le compteur ne vaut pas exactement les unités achevées."
        );
    }

    /**
     * @param array<int, string> $issues
     */
    private function coutRapporte(array $issues): int
    {
        foreach ($issues as $issue) {
            if (str_starts_with($issue, 'cout=')) {
                return (int)substr($issue, strlen('cout='));
            }
        }

        $this->fail('Le demi-temps n’a rapporté aucun coût : il ne s’est pas appliqué.');
    }

    private function uneConnexionDeVerrou(): Connection
    {
        config(['database.connections.mysql_verrou' => config('database.connections.mysql')]);

        return DB::connection('mysql_verrou');
    }
}
