<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Military\MilitaryTallyAggregator;
use OGame\Military\MilitaryTallyRecorder;
use OGame\Military\MilitaryTallyReplay;
use OGame\Military\MilitaryValue;
use OGame\Services\ObjectService;
use ReflectionProperty;
use RuntimeException;
use stdClass;
use Tests\AccountTestCase;

/**
 * **La reprise des événements en attente : la version de l'événement, le prix gardé, entier, une fois.**
 *
 * 1. Un événement repris est évalué avec **sa** version et le prix brut gardé dans sa charge — jamais avec un prix relu
 *    ni une pondération courante —, une seule fois, et sa raison d'attente reste écrite.
 * 2. Une version inconnue de ce code, une unité encore inconnue de sa version, une charge de forme inconnue : il reste
 *    en attente, aucune part écrite. **Aucune conversion.**
 * 3. Une complétion de la version permet la reprise, et ne change jamais un poids que la version connaissait.
 * 4. Une interruption avant la validation laisse l'événement en attente ; la reprise suivante l'évalue une fois.
 * 5. Un événement déjà appliqué n'est jamais touché.
 * 6. De bout en bout : une tranche que le jeu a mise en attente reprend la valeur qu'elle aurait eue.
 *
 * La concurrence réelle — deux reprises, une reprise pendant une agrégation — appartient au bac MariaDB.
 */
class MilitaryTalliesReplayTest extends AccountTestCase
{
    private const string PREFIXE = 'essai-reprise:';

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('settings')->where('key', MilitaryTallyRecorder::SINCE_KEY)->delete();
        DB::table('settings')->insert([
            'key' => MilitaryTallyRecorder::SINCE_KEY,
            'value' => (string)((int)Date::now()->timestamp - 1_000),
            'created_at' => Date::now(),
            'updated_at' => Date::now(),
        ]);
    }

    protected function tearDown(): void
    {
        // La table des poids et les complétions reviennent à leur état d'origine : un voisin ne doit pas en hériter.
        (new ReflectionProperty(MilitaryValue::class, 'poids'))->setValue(null, null);
        (new ReflectionProperty(MilitaryValue::class, 'completions'))->setValue(null, [MilitaryValue::WEIGHTING_VERSION => []]);

        DB::table('settings')->where('key', MilitaryTallyRecorder::SINCE_KEY)->delete();
        DB::table('military_tally_events')->where('player_id', $this->currentUserId)->delete();
        DB::table('military_tallies')->where('player_id', $this->currentUserId)->delete();

        parent::tearDown();
    }

    public function testAPendingSliceIsReplayedOnceWithItsOwnVersionAndTheStoredPrice(): void
    {
        $prixCourant = (int)ObjectService::getObjectRawPrice('rocket_launcher')->sum();
        $this->assertNotSame(1_000, $prixCourant, 'Prémisse : le prix gardé diffère du prix courant, sinon l’essai ne dirait pas lequel a servi.');

        $clef = $this->enAttente('prix-garde', $this->charge('rocket_launcher', 0, 3, 1_000));

        resolve(MilitaryTallyReplay::class)->replay();

        $repris = $this->evenement($clef);
        $this->assertSame(MilitaryTallyRecorder::APPLIED, $repris->status, 'L’événement n’a pas été repris.');
        $this->assertSame(1_000 * 3 * 2, (int)$repris->built_value, 'La reprise n’a pas employé le prix gardé dans la charge : attendre changerait les points.');
        $this->assertNotNull($repris->resolved_at, 'La reprise ne dit pas quand elle a eu lieu.');
        $this->assertSame('unknown_unit_family', $repris->reason, 'La reprise a effacé la raison de l’attente.');
        $this->assertSame(MilitaryValue::WEIGHTING_VERSION, $repris->weighting_version, 'La reprise a changé la version de l’événement.');

        resolve(MilitaryTallyReplay::class)->replay();

        $apres = $this->evenement($clef);
        $this->assertSame((int)$repris->built_value, (int)$apres->built_value, 'Une seconde reprise a réévalué l’événement.');
        $this->assertSame((int)$repris->resolved_at, (int)$apres->resolved_at, 'Une seconde reprise a réécrit l’événement.');

        resolve(MilitaryTallyAggregator::class)->aggregate();
        $this->assertSame(6_000, (int)DB::table('military_tallies')->where('player_id', $this->currentUserId)->value('built_value'), 'L’événement repris n’a pas été compté exactement une fois.');
    }

    public function testAnEventOfAnUnknownVersionStaysPendingWithoutConversion(): void
    {
        $clef = $this->enAttente('version-inconnue', $this->charge('rocket_launcher', 0, 3, 2_000), 'v0');

        resolve(MilitaryTallyReplay::class)->replay();

        $this->assertToujoursEnAttente($clef, 'Un événement d’une version inconnue a été évalué avec la version courante : c’est une conversion implicite.');
    }

    public function testAUnitStillUnknownToItsVersionKeepsTheWholeEventPending(): void
    {
        $clef = $this->enAttente('unite-inconnue', $this->charge('unite_hors_catalogue', 0, 3, 2_000));

        resolve(MilitaryTallyReplay::class)->replay();

        $this->assertToujoursEnAttente($clef, 'Une unité encore inconnue de sa version a reçu un poids.');
    }

    /**
     * La version ne sait pas pondérer le lance-missiles : l'événement attend. Une complétion de `v1` le classe : la
     * reprise suivante l'évalue, avec le poids de la complétion.
     */
    public function testACompletionOfTheEventsVersionLetsItBeReplayed(): void
    {
        $this->amputerLaTable('rocket_launcher');
        $clef = $this->enAttente('completion', $this->charge('rocket_launcher', 0, 4, 1_500));

        resolve(MilitaryTallyReplay::class)->replay();
        $this->assertToujoursEnAttente($clef, 'Prémisse : sans complétion, la version ne sait pas pondérer l’unité.');

        (new ReflectionProperty(MilitaryValue::class, 'completions'))->setValue(null, [MilitaryValue::WEIGHTING_VERSION => ['rocket_launcher' => 2]]);

        resolve(MilitaryTallyReplay::class)->replay();

        $repris = $this->evenement($clef);
        $this->assertSame(MilitaryTallyRecorder::APPLIED, $repris->status, 'La complétion de la version n’a pas permis la reprise.');
        $this->assertSame(1_500 * 4 * 2, (int)$repris->built_value);
    }

    public function testACompletionNeverChangesAWeightTheVersionAlreadyKnew(): void
    {
        // Le chasseur léger est un vaisseau militaire : `v1` le pondère entier. Une complétion qui dirait « moitié » ne
        // doit rien y changer.
        (new ReflectionProperty(MilitaryValue::class, 'completions'))->setValue(null, [MilitaryValue::WEIGHTING_VERSION => ['light_fighter' => 1]]);
        $clef = $this->enAttente('poids-connu', $this->charge('light_fighter', 0, 2, 4_000));

        resolve(MilitaryTallyReplay::class)->replay();

        $this->assertSame(4_000 * 2 * 2, (int)$this->evenement($clef)->built_value, 'Une complétion a changé le poids d’une unité que la version savait déjà pondérer.');
    }

    public function testAnInterruptionBeforeTheCommitLeavesTheEventPending(): void
    {
        $clef = $this->enAttente('interruption', $this->charge('rocket_launcher', 0, 3, 1_000));

        $interrompue = new MilitaryTallyReplay(beforeCommit: static function (string $reprise) use ($clef): void {
            if ($reprise === $clef) {
                throw new RuntimeException('interruption avant la validation');
            }
        });

        try {
            $interrompue->replay();
            $this->fail('La couture d’interruption n’a pas été atteinte : l’essai ne prouverait rien.');
        } catch (RuntimeException $interruption) {
            $this->assertSame('interruption avant la validation', $interruption->getMessage());
        }

        $this->assertToujoursEnAttente($clef, 'Une reprise interrompue avant sa validation a laissé une partie de son écriture.');

        resolve(MilitaryTallyReplay::class)->replay();

        $this->assertSame(1_000 * 3 * 2, (int)$this->evenement($clef)->built_value, 'Après l’interruption, l’événement n’a pas été repris exactement une fois.');
    }

    /**
     * Une charge complète et valide, mais d'une forme que la reprise ne connaît pas : elle ne l'évalue pas comme une
     * tranche de construction. Une charge incomplète ne prouverait rien — elle échouerait de toute façon.
     */
    public function testAPayloadOfAnUnknownShapeStaysPending(): void
    {
        $charge = $this->charge('rocket_launcher', 0, 3, 1_000);
        $charge['kind'] = 'combat';
        $clef = $this->enAttente('forme-inconnue', $charge);

        resolve(MilitaryTallyReplay::class)->replay();

        $this->assertToujoursEnAttente($clef, 'Une charge dont la forme n’est pas reconnue a été évaluée comme une tranche de construction.');
    }

    /**
     * Entre la sélection et la relecture verrouillée, une autre reprise a déjà évalué l'événement : celle-ci le laisse,
     * sans le réécrire.
     */
    public function testAnEventReplayedAfterItsSelectionIsLeftAlone(): void
    {
        $clef = $this->enAttente('repris-ailleurs', $this->charge('rocket_launcher', 0, 3, 1_000));

        $bilan = (new MilitaryTallyReplay(afterSelection: static function (string $selectionnee) use ($clef): void {
            if ($selectionnee === $clef) {
                DB::table('military_tally_events')->where('event_key', $clef)->update([
                    'status' => MilitaryTallyRecorder::APPLIED,
                    'built_value' => 7,
                    'resolved_at' => 1,
                ]);
            }
        }))->replay();

        $ligne = $this->evenement($clef);
        $this->assertSame(7, (int)$ligne->built_value, 'Un événement repris ailleurs entre sa sélection et sa relecture a été réévalué : le statut n’est pas revérifié sous le verrou.');
        $this->assertSame(1, (int)$ligne->resolved_at, 'Un événement repris ailleurs a été réécrit.');
        $this->assertGreaterThanOrEqual(1, $bilan['skipped'], 'La reprise ne dit pas qu’elle a laissé un événement repris ailleurs.');
    }

    public function testTheReplayLeavesAppliedEventsUntouched(): void
    {
        $clef = self::PREFIXE . 'applique:' . $this->currentUserId;
        $this->assertTrue(resolve(MilitaryTallyRecorder::class)->credit($clef, $this->currentUserId, (int)Date::now()->timestamp, built: 5));
        $avant = (array)$this->evenement($clef);

        resolve(MilitaryTallyReplay::class)->replay();

        $this->assertSame($avant, (array)$this->evenement($clef), 'La reprise a touché un événement déjà appliqué.');
    }

    /**
     * De bout en bout : la file met une tranche en attente parce que la table des poids ne connaît pas l'unité ; la table
     * redevient complète ; la reprise donne à la tranche exactement la valeur qu'un crédit direct aurait eue.
     */
    public function testASliceTheGameDeferredIsReplayedToTheValueItWouldHaveHad(): void
    {
        $this->amputerLaTable('rocket_launcher');

        $debut = (int)Date::now()->timestamp + 100;
        $lot = (int)DB::table('unit_queues')->insertGetId([
            'planet_id' => $this->planetService->getPlanetId(),
            'object_id' => ObjectService::getUnitObjectByMachineName('rocket_launcher')->id,
            'object_amount' => 12,
            'time_duration' => 60,
            'time_start' => $debut,
            'time_end' => $debut + 60,
            'time_progress' => 0,
            'object_amount_progress' => 0,
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
            'processed' => 0,
        ]);

        $this->travelTo(Date::createFromTimestamp($debut + 100));
        $this->planetService->updateUnitQueue();

        $clef = 'build:' . $lot . ':0-12';
        $charge = json_decode((string)$this->evenement($clef)->payload, true);
        $this->assertIsArray($charge);
        $this->assertSame('build', $charge['kind'] ?? null, 'La tranche en attente ne dit pas sa forme.');
        $this->assertSame((int)ObjectService::getObjectRawPrice('rocket_launcher')->sum(), $charge['raw_price'] ?? null, 'La tranche en attente ne garde pas le prix brut de son unité.');

        (new ReflectionProperty(MilitaryValue::class, 'poids'))->setValue(null, null);

        resolve(MilitaryTallyReplay::class)->replay();

        $this->assertSame(
            MilitaryValue::ofObject(ObjectService::getUnitObjectByMachineName('rocket_launcher'), 12),
            (int)$this->evenement($clef)->built_value,
            'La tranche reprise n’a pas la valeur qu’un crédit direct lui aurait donnée.'
        );
    }

    public function testTheCommandReportsWhatItDid(): void
    {
        $this->enAttente('commande', $this->charge('rocket_launcher', 0, 1, 1_000));

        $this->assertSame(0, Artisan::call('ogamex:military:reprendre-attentes'));
        $this->assertStringContainsString('repris', Artisan::output());
    }

    /**
     * @param array<string, mixed> $charge
     */
    private function enAttente(string $nom, array $charge, string $version = MilitaryValue::WEIGHTING_VERSION): string
    {
        $clef = self::PREFIXE . $nom . ':' . $this->currentUserId;

        DB::table('military_tally_events')->insert([
            'event_key' => $clef,
            'player_id' => $this->currentUserId,
            'status' => MilitaryTallyRecorder::PENDING,
            'reason' => 'unknown_unit_family',
            'payload' => json_encode($charge),
            'weighting_version' => $version,
            'built_value' => 0,
            'destroyed_value' => 0,
            'lost_value' => 0,
            'recorded_at' => (int)Date::now()->timestamp,
            'aggregated_at' => null,
            'resolved_at' => null,
            'created_at' => Date::now(),
            'updated_at' => Date::now(),
        ]);

        return $clef;
    }

    /**
     * @return array<string, mixed>
     */
    private function charge(string $objet, int $de, int $a, int $prix): array
    {
        return ['kind' => 'build', 'part' => 'built', 'queue_id' => 0, 'object' => $objet, 'from' => $de, 'to' => $a, 'raw_price' => $prix];
    }

    private function evenement(string $clef): stdClass
    {
        $ligne = DB::table('military_tally_events')->where('event_key', $clef)->first();
        $this->assertInstanceOf(stdClass::class, $ligne, "L’événement $clef n’existe pas.");

        return $ligne;
    }

    private function assertToujoursEnAttente(string $clef, string $message): void
    {
        $ligne = $this->evenement($clef);

        $this->assertSame(MilitaryTallyRecorder::PENDING, $ligne->status, $message);
        $this->assertSame([0, 0, 0], [(int)$ligne->built_value, (int)$ligne->destroyed_value, (int)$ligne->lost_value], $message);
        $this->assertNull($ligne->resolved_at, $message);
    }

    private function amputerLaTable(string $nom): void
    {
        MilitaryValue::ofObject(ObjectService::getUnitObjectByMachineName('light_fighter'), 1);

        $poids = new ReflectionProperty(MilitaryValue::class, 'poids');
        $table = (array)$poids->getValue();
        unset($table[$nom]);
        $poids->setValue(null, $table);
    }
}
