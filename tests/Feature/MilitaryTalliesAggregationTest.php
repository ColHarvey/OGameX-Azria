<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Factories\PlayerServiceFactory;
use OGame\Military\MilitaryTallyAggregator;
use OGame\Military\MilitaryTallyRecorder;
use RuntimeException;
use Tests\AccountTestCase;

/**
 * **L'agrégation ajoute chaque événement appliqué au compteur de son compte, une fois — et crédit et marque vont
 * ensemble.**
 *
 * 1. Un événement est compté une fois, quel que soit le nombre de passes, et s'ajoute à ce que le compteur porte.
 * 2. **La sélection ne décide rien** : un candidat compté ou changé entre sa sélection et sa relecture verrouillée est
 *    laissé tel quel.
 * 3. **Une interruption avant la validation n'écrit ni le crédit ni la marque** ; la passe suivante compte une fois.
 * 4. Un événement en attente n'est jamais compté.
 * 5. Un compte disparu ne reçoit pas de compteur ; supprimer un compte efface son compteur et garde ses événements.
 *
 * Les coutures d'essai (`afterSelection`, `beforeCommit`) rejouent sur une seule connexion ce qu'une seconde
 * agrégation ou une panne feraient. La concurrence réelle — deux agrégateurs, une interruption du processus, une
 * livraison pendant l'agrégation — appartient au bac MariaDB.
 */
class MilitaryTalliesAggregationTest extends AccountTestCase
{
    private const string PREFIXE = 'essai-agregation:';

    /** @var list<int> */
    private array $autresJoueurs = [];

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
        DB::table('settings')->where('key', MilitaryTallyRecorder::SINCE_KEY)->delete();
        DB::table('military_tally_events')->where('event_key', 'like', self::PREFIXE . '%')->delete();
        DB::table('military_tallies')->whereIn('player_id', [$this->currentUserId, ...$this->autresJoueurs])->delete();

        parent::tearDown();
    }

    public function testEachEventIsCountedOnceWhateverTheNumberOfPasses(): void
    {
        $this->inscrire('a', built: 100);
        $this->inscrire('b', destroyed: 10);
        $this->inscrire('c', lost: 1);

        $agregation = resolve(MilitaryTallyAggregator::class);
        $agregation->aggregate();

        $this->assertSame([100, 10, 1], $this->compteurs($this->currentUserId));
        $this->assertSame(0, $this->nonMarques(), 'Un événement compté n’est pas marqué.');

        $agregation->aggregate();
        $agregation->aggregate();

        $this->assertSame([100, 10, 1], $this->compteurs($this->currentUserId), 'Une passe suivante a recompté des événements déjà comptés.');
    }

    public function testTheCountIsAddedToWhatTheCounterAlreadyHolds(): void
    {
        $this->inscrire('premier', built: 100);
        resolve(MilitaryTallyAggregator::class)->aggregate();

        $this->inscrire('second', built: 7, lost: 3);
        resolve(MilitaryTallyAggregator::class)->aggregate();

        $this->assertSame([107, 0, 3], $this->compteurs($this->currentUserId), 'L’agrégation remplace le compteur au lieu d’y ajouter.');
    }

    /**
     * Entre la sélection et la relecture verrouillée, une autre agrégation a compté les deux candidats.
     */
    public function testACandidateCountedAfterItsSelectionIsLeftAlone(): void
    {
        $this->inscrire('a', built: 100);
        $this->inscrire('b', built: 200);

        $joueur = $this->currentUserId;
        (new MilitaryTallyAggregator(afterSelection: static function (int $selectionne, array $clefs) use ($joueur): void {
            if ($selectionne === $joueur) {
                DB::table('military_tally_events')->whereIn('event_key', $clefs)->update(['aggregated_at' => 1]);
            }
        }))->aggregate();

        $this->assertSame([0, 0, 0], $this->compteurs($this->currentUserId), 'Un candidat déjà compté par une autre agrégation a été compté une seconde fois : le statut n’est pas revérifié sous le verrou.');
    }

    /**
     * Entre la sélection et la relecture verrouillée, l'événement est repassé en attente.
     */
    public function testACandidateWhoseStatusChangedAfterItsSelectionIsLeftAlone(): void
    {
        $this->inscrire('a', built: 100);

        $joueur = $this->currentUserId;
        (new MilitaryTallyAggregator(afterSelection: static function (int $selectionne, array $clefs) use ($joueur): void {
            if ($selectionne === $joueur) {
                DB::table('military_tally_events')->whereIn('event_key', $clefs)->update(['status' => MilitaryTallyRecorder::PENDING]);
            }
        }))->aggregate();

        $this->assertSame([0, 0, 0], $this->compteurs($this->currentUserId), 'Un événement repassé en attente après sa sélection a été compté.');
        $this->assertSame(1, $this->nonMarques(), 'Un événement en attente a été marqué compté.');
    }

    public function testAnInterruptionBeforeTheCommitWritesNeitherTheCreditNorTheMark(): void
    {
        $this->inscrire('a', built: 100);
        $this->inscrire('b', lost: 5);

        $joueur = $this->currentUserId;
        $interrompue = new MilitaryTallyAggregator(beforeCommit: static function (int $compte, array $clefs) use ($joueur): void {
            if ($compte === $joueur) {
                throw new RuntimeException('interruption avant la validation');
            }
        });

        try {
            $interrompue->aggregate();
            $this->fail('La couture d’interruption n’a pas été atteinte : l’essai ne prouverait rien.');
        } catch (RuntimeException $interruption) {
            $this->assertSame('interruption avant la validation', $interruption->getMessage());
        }

        $this->assertSame([0, 0, 0], $this->compteurs($this->currentUserId), 'Une agrégation interrompue a laissé son crédit.');
        $this->assertSame(2, $this->nonMarques(), 'Une agrégation interrompue a laissé ses marques : ces événements ne seraient plus jamais comptés.');

        resolve(MilitaryTallyAggregator::class)->aggregate();
        resolve(MilitaryTallyAggregator::class)->aggregate();

        $this->assertSame([100, 0, 5], $this->compteurs($this->currentUserId), 'Après l’interruption, les événements ne sont pas comptés exactement une fois.');
    }

    public function testAPendingEventIsNeverCounted(): void
    {
        $this->inscrire('applique', built: 100);
        resolve(MilitaryTallyRecorder::class)->defer(self::PREFIXE . 'attente:' . $this->currentUserId, $this->currentUserId, (int)Date::now()->timestamp, 'unknown_unit_family', ['object' => 'unite_hors_catalogue']);

        resolve(MilitaryTallyAggregator::class)->aggregate();

        $this->assertSame([100, 0, 0], $this->compteurs($this->currentUserId));
        $this->assertNull(DB::table('military_tally_events')->where('event_key', self::PREFIXE . 'attente:' . $this->currentUserId)->value('aggregated_at'), 'Un événement en attente a été marqué compté.');
    }

    public function testTheEventsOfAVanishedAccountAreMarkedWithoutRecreatingItsCounter(): void
    {
        $disparu = (int)DB::table('users')->max('id') + 10_000;
        $this->autresJoueurs[] = $disparu;

        $this->assertTrue(resolve(MilitaryTallyRecorder::class)->credit(self::PREFIXE . 'disparu', $disparu, (int)Date::now()->timestamp, built: 7));

        resolve(MilitaryTallyAggregator::class)->aggregate();

        $this->assertNotNull(DB::table('military_tally_events')->where('event_key', self::PREFIXE . 'disparu')->value('aggregated_at'), 'L’événement d’un compte disparu reste candidat pour toujours.');
        $this->assertFalse(DB::table('military_tallies')->where('player_id', $disparu)->exists(), 'Un compteur a été recréé pour un compte qui n’existe plus.');
    }

    public function testDeletingAnAccountDeletesItsCounterAndKeepsItsEvents(): void
    {
        $this->inscrire('a', built: 100);
        resolve(MilitaryTallyAggregator::class)->aggregate();
        $this->assertSame([100, 0, 0], $this->compteurs($this->currentUserId), 'Prémisse : le compte a un compteur.');

        resolve(PlayerServiceFactory::class)->make($this->currentUserId, true)->delete();

        $this->assertFalse(DB::table('users')->where('id', $this->currentUserId)->exists(), 'Prémisse : le compte est supprimé.');
        $this->assertFalse(DB::table('military_tallies')->where('player_id', $this->currentUserId)->exists(), 'Le compteur d’un compte supprimé est resté.');
        $this->assertSame(1, DB::table('military_tally_events')->where('event_key', 'like', self::PREFIXE . '%')->where('player_id', $this->currentUserId)->count(), 'La suppression du compte a effacé des faits du registre.');
    }

    private function inscrire(string $nom, int $built = 0, int $destroyed = 0, int $lost = 0): void
    {
        $this->assertTrue(
            resolve(MilitaryTallyRecorder::class)->credit(self::PREFIXE . $nom . ':' . $this->currentUserId, $this->currentUserId, (int)Date::now()->timestamp, $built, $destroyed, $lost),
            "Prémisse : l’événement $nom est inscrit."
        );
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function compteurs(int $joueur): array
    {
        $ligne = DB::table('military_tallies')->where('player_id', $joueur)->first(['built_value', 'destroyed_value', 'lost_value']);

        if ($ligne === null) {
            return [0, 0, 0];
        }

        return [(int)$ligne->built_value, (int)$ligne->destroyed_value, (int)$ligne->lost_value];
    }

    private function nonMarques(): int
    {
        return DB::table('military_tally_events')
            ->where('event_key', 'like', self::PREFIXE . '%')
            ->where('player_id', $this->currentUserId)
            ->whereNull('aggregated_at')
            ->count();
    }
}
