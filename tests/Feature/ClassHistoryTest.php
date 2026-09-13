<?php

namespace Tests\Feature;

use Exception;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Enums\AllianceClass;
use OGame\Enums\CharacterClass;
use OGame\History\ClassHistoryBaseline;
use OGame\History\ClassHistoryReader;
use OGame\History\ClassHistoryRecorder;
use OGame\Models\FleetMission;
use OGame\Models\User;
use OGame\Services\CharacterClassService;
use RuntimeException;
use Tests\TestCase;

/**
 * Les historiques de classe : ce qu ils ecrivent, dans quelle transaction, et ce qu ils savent d un instant.
 *
 * ## Les regles eprouvees ici
 *
 * - la valeur a un instant est la **derniere decision strictement anterieure** (`DecisionOrder`) : une
 *   decision de la seconde meme compte apres, deux decisions de la meme seconde se departagent par ligne ;
 * - **inconnu** n est jamais `null` : sans ligne avant l instant, ou quand la derniere ligne contredit la
 *   colonne, la valeur est inconnue ;
 * - un compte nait avec ses lignes ; la ligne de base inscrit le present, et rien avant ;
 * - un changement et sa ligne vivent **dans la meme transaction** : un enregistreur qui echoue annule le
 *   changement.
 *
 * ## Pourquoi les essais ecrivent tout l historique qu ils lisent
 *
 * Un compte nait avec sa ligne de creation, datee de l horloge du banc — bien apres les instants que ces
 * essais emploient. Laissee la, elle serait la **derniere** ligne de l historique : la confrontation a la
 * colonne rendrait tout inconnu, et chaque essai mesurerait cela au lieu de sa regle. `anAccount()` la
 * retire donc : l essai etablit le monde qu il exige.
 */
class ClassHistoryTest extends TestCase
{
    private const int T = 1_700_000_000;

    protected function setUp(): void
    {
        parent::setUp();

        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();

        parent::tearDown();
    }

    /**
     * La valeur a un instant est celle de la derniere decision **strictement** anterieure.
     */
    public function testTheValueAtAnInstantIsTheLastDecisionStrictlyBeforeIt(): void
    {
        $compte = $this->anAccount();
        $this->classLine($compte, CharacterClass::COLLECTOR, self::T - 100);
        $this->classLine($compte, CharacterClass::GENERAL, self::T - 10);
        $this->classLine($compte, CharacterClass::DISCOVERER, self::T + 10);
        $this->theColumnSays($compte, CharacterClass::DISCOVERER);

        $lecteur = new ClassHistoryReader();

        $this->assertSame(CharacterClass::GENERAL->value, $lecteur->personalClassAt($compte, self::T)->value());
        $this->assertSame(CharacterClass::COLLECTOR->value, $lecteur->personalClassAt($compte, self::T - 50)->value());
        $this->assertSame(CharacterClass::DISCOVERER->value, $lecteur->personalClassAt($compte, self::T + 11)->value());
    }

    /**
     * **Une decision prise a la seconde meme de l instant compte apres lui** : c est la regle de
     * `DecisionOrder::isStrictlyBefore()`, et celle du jeu, qui traite les missions dues avant l action de la
     * requete.
     */
    public function testADecisionAtTheVerySecondCountsAfterTheInstant(): void
    {
        $compte = $this->anAccount();
        $this->classLine($compte, null, self::T - 100);
        $this->classLine($compte, CharacterClass::GENERAL, self::T);
        $this->theColumnSays($compte, CharacterClass::GENERAL);

        $lecteur = new ClassHistoryReader();

        $this->assertNull($lecteur->personalClassAt($compte, self::T)->value(), 'A class taken at the very second counted before it.');
        $this->assertSame(CharacterClass::GENERAL->value, $lecteur->personalClassAt($compte, self::T + 1)->value());
    }

    /**
     * Deux decisions de la meme seconde se departagent par leur ligne : la plus recente fait foi.
     */
    public function testTwoDecisionsOfTheSameSecondAreOrderedByTheirLine(): void
    {
        $compte = $this->anAccount();
        $this->classLine($compte, CharacterClass::GENERAL, self::T - 5);
        $this->classLine($compte, CharacterClass::COLLECTOR, self::T - 5);
        $this->theColumnSays($compte, CharacterClass::COLLECTOR);

        $this->assertSame(CharacterClass::COLLECTOR->value, (new ClassHistoryReader())->personalClassAt($compte, self::T)->value());
    }

    /**
     * **Sans ligne avant l instant, la valeur est inconnue — et « inconnu » n est pas `null`.**
     */
    public function testWithoutAnyLineBeforeTheInstantTheValueIsUnknownAndNeverNull(): void
    {
        $compte = $this->anAccount();
        $this->classLine($compte, null, self::T + 60);

        $valeur = (new ClassHistoryReader())->personalClassAt($compte, self::T);

        $this->assertFalse($valeur->isKnown(), 'A class nobody recorded before the instant was read as known.');
        $this->assertStringContainsString('aucune decision connue', $valeur->reason);
    }

    /**
     * **Une derniere ligne qui contredit la colonne rend tout l historique inconnu** : un changement a ete
     * ecrit sans sa ligne, et elle peut manquer n importe ou.
     */
    public function testALastLineThatContradictsTheColumnMakesTheHistoryUnknown(): void
    {
        $compte = $this->anAccount();
        $this->classLine($compte, null, self::T - 100);
        $this->theColumnSays($compte, CharacterClass::GENERAL);

        $valeur = (new ClassHistoryReader())->personalClassAt($compte, self::T);

        $this->assertFalse($valeur->isKnown(), 'A history contradicted by the account column was trusted.');
        $this->assertStringContainsString('sans sa ligne', $valeur->reason);
    }

    /**
     * L appartenance et la classe d une alliance suivent la meme regle, et une alliance dissoute garde son
     * historique pour les instants ou elle existait.
     */
    public function testMembershipAndAllianceClassFollowTheSameRuleEvenAfterADisband(): void
    {
        $compte = $this->anAccount();
        $alliance = 987_654;

        DB::table('alliance_membership_history')->insert([
            ['user_id' => $compte, 'alliance_id' => $alliance, 'changed_at' => self::T - 100, 'cause' => 'test'],
            ['user_id' => $compte, 'alliance_id' => null, 'changed_at' => self::T + 100, 'cause' => 'test'],
        ]);
        DB::table('alliance_class_history')->insert([
            ['alliance_id' => $alliance, 'alliance_class' => null, 'changed_at' => self::T - 200, 'cause' => 'test'],
            ['alliance_id' => $alliance, 'alliance_class' => AllianceClass::WARRIORS->name, 'changed_at' => self::T - 50, 'cause' => 'test'],
        ]);

        $lecteur = new ClassHistoryReader();

        $this->assertSame($alliance, $lecteur->membershipAt($compte, self::T)->value());
        $this->assertNull($lecteur->membershipAt($compte, self::T + 101)->value());
        $this->assertSame(AllianceClass::WARRIORS->name, $lecteur->allianceClassAt($alliance, self::T)->value(), 'A disbanded alliance lost the class it had while it existed.');
        $this->assertNull($lecteur->allianceClassAt($alliance, self::T - 60)->value());
    }

    /**
     * **Un compte nait avec ses lignes** : sa classe et son alliance telles qu il nait, a l instant ou il
     * nait.
     */
    public function testAnAccountIsBornWithItsInitialLines(): void
    {
        $instant = (int)Date::now()->timestamp;
        $compte = (int)User::factory()->create()->id;

        $classe = DB::table('character_class_history')->where('user_id', $compte)->where('cause', ClassHistoryRecorder::CAUSE_CREATION)->first();
        $appartenance = DB::table('alliance_membership_history')->where('user_id', $compte)->where('cause', ClassHistoryRecorder::CAUSE_CREATION)->first();

        $this->assertNotNull($classe, 'An account was born without the line that says what its class was.');
        $this->assertNotNull($appartenance, 'An account was born without the line that says which alliance it belonged to.');
        $this->assertSame($instant, (int)$classe->changed_at);
        $this->assertNull($classe->character_class);
        $this->assertNull($appartenance->alliance_id);

        // Et l historique repond des la seconde suivante, sans rien affirmer d avant.
        $lecteur = new ClassHistoryReader();
        $this->assertNull($lecteur->personalClassAt($compte, $instant + 1)->value());
        $this->assertFalse($lecteur->personalClassAt($compte, $instant)->isKnown(), 'A newborn account claimed to know what it was before it existed.');
    }

    /**
     * **La ligne de base inscrit le present, datee de son instant, et rien avant.**
     */
    public function testTheBaselineWritesThePresentAtItsOwnInstantAndNothingBefore(): void
    {
        $compte = $this->anAccount();
        $this->theColumnSays($compte, CharacterClass::GENERAL);
        DB::table('character_class_history')->where('cause', ClassHistoryBaseline::CAUSE)->delete();

        (new ClassHistoryBaseline())->writeAt(self::T);

        $lecteur = new ClassHistoryReader();

        $this->assertSame(CharacterClass::GENERAL->value, $lecteur->personalClassAt($compte, self::T + 1)->value());
        $this->assertFalse($lecteur->personalClassAt($compte, self::T)->isKnown(), 'The baseline claimed to know the class at its own instant.');
        $this->assertFalse($lecteur->personalClassAt($compte, self::T - 3_600)->isKnown(), 'The baseline invented a history before itself.');
        $this->assertSame(self::T, $lecteur->baselineInstant());
    }

    /**
     * **Le jeu refuse de changer de classe tant qu une mission n est pas traitee** — une flotte arrivee mais
     * pas encore traitee comprise. Ce refus ne dispense pas de l historique, mais il dit pourquoi le chemin du
     * jeu ne change jamais la classe d un joueur entre l arrivee de sa flotte et son traitement.
     */
    public function testAClassChangeIsRefusedWhileAnArrivedFleetIsNotProcessed(): void
    {
        $compte = $this->anAccount();

        FleetMission::forceCreate([
            'user_id' => $compte,
            'mission_type' => 1,
            'time_departure' => self::T - 600,
            'time_arrival' => self::T - 10,
            'galaxy_to' => 1,
            'system_to' => 1,
            'position_to' => 1,
            'type_to' => 1,
            'light_fighter' => 5,
            'processed' => 0,
        ]);

        try {
            resolve(CharacterClassService::class)->selectClass(User::query()->findOrFail($compte), CharacterClass::GENERAL);
            $this->fail('A class change went through while an arrived fleet was not processed.');
        } catch (Exception $refus) {
            $this->assertStringContainsString('fleet missions are active', $refus->getMessage());
        }

        $this->assertNull(DB::table('users')->where('id', $compte)->value('character_class'));
        $this->assertSame(0, DB::table('character_class_history')->where('user_id', $compte)->where('cause', ClassHistoryRecorder::CAUSE_SELECTION)->count());
    }

    /**
     * **Une selection qui aboutit ecrit sa ligne**, dans la transaction qui change la colonne.
     */
    public function testASelectedClassWritesItsLine(): void
    {
        $compte = $this->anAccount();
        $instant = (int)Date::now()->timestamp;

        resolve(CharacterClassService::class)->selectClass(User::query()->findOrFail($compte), CharacterClass::GENERAL);

        $ligne = DB::table('character_class_history')->where('user_id', $compte)->orderByDesc('id')->first();

        $this->assertNotNull($ligne, 'A class was selected without its history line.');
        $this->assertSame(CharacterClass::GENERAL->value, (int)$ligne->character_class);
        $this->assertSame($instant, (int)$ligne->changed_at);
        $this->assertSame(ClassHistoryRecorder::CAUSE_SELECTION, $ligne->cause);
        $this->assertSame(CharacterClass::GENERAL->value, (int)DB::table('users')->where('id', $compte)->value('character_class'));

        // Et l historique repond des la seconde suivante, jamais a la seconde meme.
        $lecteur = new ClassHistoryReader();
        $this->assertSame(CharacterClass::GENERAL->value, $lecteur->personalClassAt($compte, $instant + 1)->value());
    }

    /**
     * **Le changement et sa ligne, ensemble ou pas du tout** : un enregistreur qui echoue annule le changement.
     */
    public function testAFailingHistoryUndoesTheClassChange(): void
    {
        $compte = $this->anAccount();

        $this->app->bind(ClassHistoryRecorder::class, static fn (): ClassHistoryRecorder => throw new RuntimeException('historique indisponible'));

        try {
            resolve(CharacterClassService::class)->selectClass(User::query()->findOrFail($compte), CharacterClass::GENERAL);
            $this->fail('The class change went through although its history could not be written.');
        } catch (RuntimeException $panne) {
            $this->assertSame('historique indisponible', $panne->getMessage());
        }

        $this->assertNull(DB::table('users')->where('id', $compte)->value('character_class'), 'The class changed without its history line.');
    }

    /**
     * Un compte dont l essai ecrit lui-meme tout l historique : ses lignes de naissance sont retirees.
     */
    private function anAccount(): int
    {
        $compte = (int)User::factory()->create()->id;

        DB::table('character_class_history')->where('user_id', $compte)->delete();
        DB::table('alliance_membership_history')->where('user_id', $compte)->delete();

        return $compte;
    }

    private function classLine(int $compte, CharacterClass|null $classe, int $instant): void
    {
        DB::table('character_class_history')->insert([
            'user_id' => $compte,
            'character_class' => $classe?->value,
            'changed_at' => $instant,
            'cause' => 'test',
        ]);
    }

    private function theColumnSays(int $compte, CharacterClass|null $classe): void
    {
        DB::table('users')->where('id', $compte)->update(['character_class' => $classe?->value]);
    }
}
