<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use OGame\Military\MilitaryTallyPublisher;
use OGame\Military\MilitaryTallyRecorder;
use OGame\Military\MilitaryTallySources;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;
use ReflectionMethod;
use Tests\AccountTestCase;

/**
 * **L'activation des cumuls refuse tant qu'une source n'a pas son témoin d'effet, ou qu'un prérequis manque.**
 *
 * 1. La liste des sources est exactement celle attendue : une source ne disparaît pas en silence.
 * 2. Chaque témoin nommé est un vrai essai public de la suite — pas un nom recopié qui ne mène à rien.
 * 3. L'activation refuse et **nomme** les sources sans témoin ; elle n'écrit aucune date.
 * 4. Elle refuse aussi quand une table manque.
 * 5. Elle n'écrit sa date que lorsque chaque source a son témoin.
 *
 * Que le témoin passe appartient à la suite en intégration continue ; qu'il **tombe** quand le crédit est retiré de son
 * chemin appartient à la batterie de mutations.
 */
class MilitaryTallySourcesTest extends AccountTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('settings')->whereIn('key', [MilitaryTallyRecorder::SINCE_KEY, MilitaryTallyPublisher::PUBLISHED_KEY])->delete();
    }

    protected function tearDown(): void
    {
        DB::table('settings')->whereIn('key', [MilitaryTallyRecorder::SINCE_KEY, MilitaryTallyPublisher::PUBLISHED_KEY])->delete();

        parent::tearDown();
    }

    public function testEverySourceIsListedAndEveryNamedWitnessIsARealTest(): void
    {
        $this->assertSame(
            ['construction', 'demi-temps', 'bataille', 'manoeuvre-de-hamill', 'missile', 'destruction-de-lune', 'expedition', 'contre-espionnage', 'espace-libre'],
            array_keys(MilitaryTallySources::WITNESSES),
            'La liste des sources de crédit a changé : une source ne disparaît pas, et ne s’ajoute pas, sans que cet essai le dise.'
        );

        $nommes = 0;

        foreach (MilitaryTallySources::WITNESSES as $source => $temoin) {
            if ($temoin === null) {
                continue;
            }

            $nommes++;
            $morceaux = explode('::', $temoin, 2);
            $this->assertCount(2, $morceaux, "Le témoin de la source $source n’a pas la forme Classe::méthode.");
            [$classe, $methode] = $morceaux;

            $this->assertTrue(class_exists($classe), "Le témoin de la source $source nomme une classe qui n’existe pas : $classe.");
            $this->assertTrue(is_subclass_of($classe, PhpUnitTestCase::class), "Le témoin de la source $source n’est pas un essai.");
            $this->assertTrue(method_exists($classe, $methode), "Le témoin de la source $source nomme une méthode qui n’existe pas : $methode.");
            $this->assertStringStartsWith('test', $methode, "Le témoin de la source $source n’est pas une méthode d’essai.");
            $this->assertTrue((new ReflectionMethod($classe, $methode))->isPublic(), "Le témoin de la source $source n’est pas public : PHPUnit ne le lancerait pas.");
        }

        $this->assertGreaterThan(0, $nommes, 'Aucun témoin n’est nommé : la garde ne vérifierait rien.');
    }

    public function testTheActivationRefusesWhileASourceHasNoWitnessAndNamesIt(): void
    {
        $manquantes = (new MilitaryTallySources())->unwired();
        $this->assertNotSame([], $manquantes, 'Prémisse : des sources ne sont pas encore raccordées.');

        $this->assertSame(1, Artisan::call('ogamex:military:demarrer-cumuls'), 'L’activation a réussi alors que des sources n’ont pas de témoin d’effet.');

        $sortie = Artisan::output();
        foreach ($manquantes as $source) {
            $this->assertStringContainsString($source, $sortie, "Le refus ne nomme pas la source $source.");
        }

        $this->assertNull(resolve(MilitaryTallyRecorder::class)->collectingSince(), 'Un refus a écrit une date d’activation.');
    }

    public function testTheActivationRefusesWhenATableIsMissing(): void
    {
        $this->toutesLesSourcesOntUnTemoin();

        Schema::rename('military_tallies', 'military_tallies_essai_absente');

        try {
            $this->assertSame(1, Artisan::call('ogamex:military:demarrer-cumuls'), 'L’activation a réussi sans la table des compteurs.');
            $this->assertStringContainsString('la table military_tallies n existe pas', Artisan::output(), 'Le refus ne dit pas que la table des compteurs manque.');
            $this->assertNull(resolve(MilitaryTallyRecorder::class)->collectingSince(), 'Un refus a écrit une date d’activation.');
        } finally {
            Schema::rename('military_tallies_essai_absente', 'military_tallies');
        }
    }

    /**
     * Un témoin vide, ou fait d'espaces, ne raccorde rien.
     */
    public function testAnEmptyWitnessDoesNotWireASource(): void
    {
        $sources = new MilitaryTallySources(['vide' => '', 'espaces' => '   ', 'nul' => null, 'nomme' => 'Tests\\Feature\\MilitaryTalliesBuildTest::testOnePassGivesTheSameCounterAsSeveral']);

        $this->assertSame(['vide', 'espaces', 'nul'], $sources->unwired(), 'Un témoin vide passe pour une source raccordée.');
    }

    /**
     * La table existe, une colonne manque : l'activation refuse aussi, et nomme la table.
     */
    public function testTheActivationRefusesWhenAColumnIsMissing(): void
    {
        $this->toutesLesSourcesOntUnTemoin();

        Schema::table('military_tally_events', static function (Blueprint $table): void {
            $table->dropColumn('resolved_at');
        });

        try {
            $this->assertSame(1, Artisan::call('ogamex:military:demarrer-cumuls'), 'L’activation a réussi sans la colonne de reprise.');
            $this->assertStringContainsString('military_tally_events n a pas les colonnes', Artisan::output(), 'Le refus ne nomme pas la table dont une colonne manque.');
            $this->assertNull(resolve(MilitaryTallyRecorder::class)->collectingSince(), 'Un refus a écrit une date d’activation.');
        } finally {
            Schema::table('military_tally_events', static function (Blueprint $table): void {
                $table->unsignedInteger('resolved_at')->nullable();
            });
        }
    }

    public function testTheActivationWritesItsDateOnlyOnceEverySourceHasItsWitness(): void
    {
        $this->toutesLesSourcesOntUnTemoin();

        $this->assertSame(0, Artisan::call('ogamex:military:demarrer-cumuls'), 'L’activation a refusé alors que chaque source a son témoin.');
        $this->assertNotNull(resolve(MilitaryTallyRecorder::class)->collectingSince(), 'L’activation n’a pas écrit sa date.');
    }

    /**
     * Un registre où chaque source nomme un témoin : il passe par la même règle que le vrai (`unwired()`).
     */
    private function toutesLesSourcesOntUnTemoin(): void
    {
        $temoin = 'Tests\\Feature\\MilitaryTalliesBuildTest::testEachDeliveredSliceIsOneEventAndTheSlicesCoverTheProgressExactly';

        $this->app->instance(MilitaryTallySources::class, new MilitaryTallySources(array_map(
            static fn (string|null $nomme): string => $nomme ?? $temoin,
            MilitaryTallySources::WITNESSES
        )));
    }
}
