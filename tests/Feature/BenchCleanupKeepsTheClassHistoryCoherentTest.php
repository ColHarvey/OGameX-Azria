<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OGame\Enums\CharacterClass;
use OGame\History\ClassHistoryReader;
use OGame\History\HistoricValue;
use OGame\Models\User;
use OGame\Services\AllianceService;
use PHPUnit\Framework\AssertionFailedError;
use Tests\AccountTestCase;
use Tests\RecordsClassHistory;
use Tests\Support\DetachesFromAnyAlliance;

/**
 * **Les aides de nettoyage du banc laissent un historique que le lecteur du gel sait lire.**
 *
 * ## Pourquoi ce temoin, et pourquoi il ne suffit pas de garder le montage
 *
 * La fermeture d un ralliement gele chaque compte a son admission par `ClassHistoryReader`, et se suspend des
 * que l historique contredit la colonne. Le proprietaire de la planete etrangere voisine est partage par tout le
 * processus : un demontage qui remettait `users.alliance_id` a vide sans sa ligne, ou un essai qui ecrivait
 * `character_class` sans elle, faisait suspendre le ralliement d une classe voisine. Vingt classes le faisaient
 * (inventaire du 14 septembre 2026, journal §154.11) ; c est le rouge MariaDB de `eb983eb9` et l intermittent
 * « did not close at once » de `PersistentMoonDestructionTest`.
 *
 * La garde du montage (`FleetDispatchTestCase::requireAnAdmissibleHistoryFor()`) detecte une pollution au moment
 * ou elle nuit. Elle ne prouve pas que les ecrivains nettoient : c est ce temoin-ci, sur chaque aide, avec un
 * temoin negatif qui etablit que le lecteur voit bien l anomalie que les aides evitent — sans lui, une aide qui
 * n ecrirait plus la ligne passerait aussi bien.
 */
final class BenchCleanupKeepsTheClassHistoryCoherentTest extends AccountTestCase
{
    use DetachesFromAnyAlliance;
    use RecordsClassHistory;

    /** @var list<int> */
    private array $alliances = [];

    /** @var list<int> */
    private array $comptes = [];

    protected function tearDown(): void
    {
        $this->dissolveTheBenchAlliances(...$this->alliances);
        $this->detachFromAnyAlliance(...$this->comptes);

        // Les temoins negatifs de cette classe contredisent volontairement l historique : ils le remettent en accord.
        foreach ($this->comptes as $compte) {
            $this->leaveTheClassHistoryCoherentFor($compte);
            $this->leaveTheMembershipHistoryCoherentFor($compte);
        }

        parent::tearDown();
    }

    /**
     * **Le temoin negatif** : la colonne ecrite seule — ce que faisaient les demontages — rend l appartenance inconnue.
     */
    public function testAColumnWrittenAloneIsExactlyWhatTheReaderRefuses(): void
    {
        [$fondateur, $membre, $alliance] = $this->uneAllianceDeDeux();

        DB::table('users')->where('id', $membre)->update(['alliance_id' => null]);

        $lecture = $this->appartenanceDe($membre);
        $this->assertFalse($lecture->isKnown(), 'Une colonne remise a vide sans sa ligne est lue comme connue : ce temoin ne distinguerait plus une aide qui n ecrit pas la ligne.');
        $this->assertStringContainsString('Un changement a ete ecrit sans sa ligne.', $lecture->reason);

        $this->assertSame($alliance, $this->appartenanceDe($fondateur)->value(), 'Premisse : le fondateur, lui, est encore lisible.');
    }

    /**
     * **Le detachement** laisse une appartenance connue et vide, et ne touche pas a un compte deja libre.
     */
    public function testDetachingLeavesAKnownEmptyMembershipAndWritesNothingForAFreeAccount(): void
    {
        [, $membre] = $this->uneAllianceDeDeux();
        $libre = $this->unCompte();
        $lignesDuLibre = $this->lignesDAppartenanceDe($libre);

        $this->detachFromAnyAlliance($membre, $libre);

        $lecture = $this->appartenanceDe($membre);
        $this->assertTrue($lecture->isKnown(), 'Le detachement a laisse une appartenance illisible : ' . $lecture->reason);
        $this->assertNull($lecture->value(), 'Le compte detache est encore lu dans une alliance.');
        $this->assertNull(DB::table('users')->where('id', $membre)->value('alliance_id'));
        $this->assertSame(0, DB::table('alliance_members')->where('user_id', $membre)->count(), 'L inscription du compte detache est restee.');

        $this->assertSame($lignesDuLibre, $this->lignesDAppartenanceDe($libre), 'Un compte qui n appartenait a rien a recu une ligne de depart.');
        $this->assertTrue($this->appartenanceDe($libre)->isKnown());
    }

    /**
     * **La dissolution** sort chaque membre avec sa ligne, puis efface inscriptions, candidatures et alliance.
     */
    public function testDissolvingLeavesEveryMemberKnownAndTheAllianceGone(): void
    {
        [$fondateur, $membre, $alliance] = $this->uneAllianceDeDeux();
        $candidat = $this->unCompte();
        resolve(AllianceService::class)->applyToAlliance($candidat, $alliance);

        $this->dissolveTheBenchAlliances($alliance, null, 0);

        foreach ([$fondateur, $membre] as $compte) {
            $lecture = $this->appartenanceDe($compte);
            $this->assertTrue($lecture->isKnown(), 'La dissolution a laisse le compte ' . $compte . ' illisible : ' . $lecture->reason);
            $this->assertNull($lecture->value(), 'Le compte ' . $compte . ' est encore lu dans l alliance dissoute.');
            $this->assertNull(DB::table('users')->where('id', $compte)->value('alliance_id'));
        }

        $this->assertSame(0, DB::table('alliances')->where('id', $alliance)->count(), 'L alliance est restee.');
        $this->assertSame(0, DB::table('alliance_members')->where('alliance_id', $alliance)->count(), 'Une inscription est restee.');
        $this->assertSame(0, DB::table('alliance_applications')->where('alliance_id', $alliance)->count(), 'Une candidature est restee.');
    }

    /**
     * **L entree directe** d un membre — sans candidature — est lue comme une adhesion.
     */
    public function testJoiningDirectlyIsReadAsAMembership(): void
    {
        [, , $alliance] = $this->uneAllianceDeDeux();
        $entrant = $this->unCompte();
        $modele = User::query()->findOrFail($entrant);

        $this->joinTheBenchAlliance($modele, $alliance);

        $lecture = $this->appartenanceDe($entrant);
        $this->assertTrue($lecture->isKnown(), 'L entree directe a laisse le compte illisible : ' . $lecture->reason);
        $this->assertSame($alliance, $lecture->value(), 'L entree directe n est pas lue comme une adhesion a cette alliance.');
        $this->assertSame($alliance, (int)$modele->alliance_id, 'Le modele tenu par l essai ne porte pas l alliance.');
        $this->assertSame(1, DB::table('alliance_members')->where('alliance_id', $alliance)->where('user_id', $entrant)->count());
    }

    /**
     * **La classe posee sur un modele** est lue comme telle, et le modele reste a jour en memoire.
     */
    public function testRecordingAClassOnAModelIsReadAsThatClassAndTheModelFollows(): void
    {
        $compte = $this->unCompte();
        $modele = User::query()->findOrFail($compte);

        $this->recordCharacterClassOn($modele, CharacterClass::GENERAL);

        $lecture = $this->classeDe($compte);
        $this->assertTrue($lecture->isKnown(), 'La classe posee sur le modele a laisse le compte illisible : ' . $lecture->reason);
        $this->assertSame(CharacterClass::GENERAL->value, $lecture->value());
        $this->assertSame(CharacterClass::GENERAL->value, (int)$modele->character_class, 'Le modele tenu par l essai ne porte pas la classe.');

        $this->recordCharacterClassOn($modele, null);

        $this->assertTrue($this->classeDe($compte)->isKnown());
        $this->assertNull($this->classeDe($compte)->value());
        $this->assertNull($modele->character_class);
    }

    /**
     * **La remise en accord** ne fait rien a un compte coherent, et rend lisible un compte volontairement contredit.
     */
    public function testLeavingTheHistoryCoherentRepairsOnlyWhatWasContradicted(): void
    {
        $compte = $this->unCompte();
        $this->recordCharacterClass($compte, CharacterClass::COLLECTOR);
        $lignes = $this->lignesDeClasseDe($compte);

        $this->leaveTheClassHistoryCoherentFor($compte);
        $this->assertSame($lignes, $this->lignesDeClasseDe($compte), 'Un compte coherent a recu une ligne de plus.');

        // Ce qu un essai de suspension fait volontairement : la colonne seule, puis le temps passe jusqu au demontage.
        DB::table('users')->where('id', $compte)->update(['character_class' => CharacterClass::GENERAL->value]);
        $this->assertFalse($this->classeDe($compte)->isKnown(), 'Premisse : la colonne ecrite seule est illisible.');
        $this->travelTo(Date::now()->addSeconds(30));

        $this->leaveTheClassHistoryCoherentFor($compte);

        $lecture = $this->classeDe($compte);
        $this->assertTrue($lecture->isKnown(), 'La remise en accord a laisse le compte illisible : ' . $lecture->reason);
        $this->assertSame(CharacterClass::GENERAL->value, $lecture->value(), 'La remise en accord n a pas ecrit ce que la colonne porte.');
    }

    /**
     * **La remise en accord se date apres la derniere ligne, meme si l essai a ramene l horloge en arriere.**
     *
     * Le reglement d un combat ramene l horloge a l echeance ; une ligne de reparation datee la serait avant celle
     * qu elle corrige, et le lecteur ne la verrait pas. Mesure sur `ClassIdentityFrozenAtAdmissionTest`, qui laissait
     * ainsi le proprietaire de la cible illisible (journal §154.11).
     */
    public function testTheRepairIsDatedAfterTheLastLineEvenWhenTheClockWentBack(): void
    {
        $compte = $this->unCompte();
        $this->travelTo(Date::now()->addMinutes(10));
        $this->recordCharacterClass($compte, CharacterClass::COLLECTOR);
        $derniere = (int)Date::now()->timestamp;

        $this->travelTo(Date::now()->subMinutes(5));
        DB::table('users')->where('id', $compte)->update(['character_class' => CharacterClass::GENERAL->value]);

        $this->leaveTheClassHistoryCoherentFor($compte);

        $lecture = resolve(ClassHistoryReader::class)->personalClassAt($compte, $derniere + 2);
        $this->assertTrue($lecture->isKnown(), 'La reparation datee avant la derniere ligne a laisse le compte illisible : ' . $lecture->reason);
        $this->assertSame(CharacterClass::GENERAL->value, $lecture->value());
        $this->assertSame($derniere - 300, (int)Date::now()->timestamp, 'La remise en accord a laisse l horloge du banc deplacee.');
    }

    /**
     * **La remise en accord de l appartenance** ne fait rien a un compte coherent, et rend lisible — apres la derniere
     * ligne, meme l horloge ramenee en arriere — un compte dont la colonne a ete videe seule : le detachement ne voit
     * pas ce compte-la, sa colonne ne portant plus rien.
     */
    public function testLeavingTheMembershipCoherentRepairsOnlyWhatWasContradicted(): void
    {
        $this->travelTo(Date::now()->addMinutes(10));
        [$fondateur, $membre] = $this->uneAllianceDeDeux();
        $adhesion = (int)Date::now()->timestamp;
        $lignes = $this->lignesDAppartenanceDe($fondateur);

        $this->leaveTheMembershipHistoryCoherentFor($fondateur);
        $this->assertSame($lignes, $this->lignesDAppartenanceDe($fondateur), 'Un compte coherent a recu une ligne de plus.');

        DB::table('users')->where('id', $membre)->update(['alliance_id' => null]);
        $this->assertFalse($this->appartenanceDe($membre)->isKnown(), 'Premisse : la colonne videe seule est illisible.');

        $this->detachFromAnyAlliance($membre);
        $this->assertFalse($this->appartenanceDe($membre)->isKnown(), 'Premisse : le detachement ne voit pas un compte deja vide, c est bien la remise en accord qui manque.');

        $this->travelTo(Date::now()->subMinutes(5));
        $this->leaveTheMembershipHistoryCoherentFor($membre);

        $lecture = resolve(ClassHistoryReader::class)->membershipAt($membre, $adhesion + 2);
        $this->assertTrue($lecture->isKnown(), 'La remise en accord a laisse le compte illisible : ' . $lecture->reason);
        $this->assertNull($lecture->value(), 'La remise en accord n a pas ecrit ce que la colonne porte.');
        $this->assertSame($adhesion - 300, (int)Date::now()->timestamp, 'La remise en accord a laisse l horloge du banc deplacee.');
    }

    /**
     * **Poser une classe avant la derniere ligne est refuse**, au lieu de laisser un compte que le lecteur contredira.
     */
    public function testRecordingAClassBeforeTheLastLineIsRefused(): void
    {
        $compte = $this->unCompte();
        $this->travelTo(Date::now()->addMinutes(10));
        $this->recordCharacterClass($compte, CharacterClass::COLLECTOR);
        $derniere = (int)Date::now()->timestamp;

        $this->travelTo(Date::now()->subMinutes(5));

        try {
            $this->recordCharacterClass($compte, CharacterClass::GENERAL);
            $this->fail('Une ligne datee avant la derniere a ete ecrite sans rien dire.');
        } catch (AssertionFailedError $refus) {
            $this->assertStringContainsString('avant sa derniere ligne', $refus->getMessage());
        }

        $lecture = resolve(ClassHistoryReader::class)->personalClassAt($compte, $derniere + 1);
        $this->assertTrue($lecture->isKnown(), 'Le refus a laisse le compte illisible : ' . $lecture->reason);
        $this->assertSame(CharacterClass::COLLECTOR->value, $lecture->value(), 'Le refus a tout de meme ecrit la colonne.');
    }

    /**
     * @return array{0: int, 1: int, 2: int} Le fondateur, un membre entre par candidature, l alliance.
     */
    private function uneAllianceDeDeux(): array
    {
        $service = resolve(AllianceService::class);
        $fondateur = $this->unCompte();
        $membre = $this->unCompte();

        $alliance = (int)$service->createAlliance($fondateur, 'BN' . substr((string)$fondateur, -4), 'Nettoyage ' . $fondateur)->id;
        $this->alliances[] = $alliance;
        $service->acceptApplication((int)$service->applyToAlliance($membre, $alliance)->id, $fondateur);

        $this->assertSame($alliance, $this->appartenanceDe($membre)->value(), 'Premisse : le membre entre par le jeu est lu dans l alliance.');

        return [$fondateur, $membre, $alliance];
    }

    private function unCompte(): int
    {
        $compte = User::factory()->create(['username' => 'nettoyage_' . Str::random(10)]);
        $this->comptes[] = (int)$compte->id;

        return (int)$compte->id;
    }

    /**
     * L instant de lecture : juste apres l horloge du banc, ou chaque ligne est ecrite.
     */
    private function appartenanceDe(int $compte): HistoricValue
    {
        return resolve(ClassHistoryReader::class)->membershipAt($compte, (int)Date::now()->timestamp + 1);
    }

    private function classeDe(int $compte): HistoricValue
    {
        return resolve(ClassHistoryReader::class)->personalClassAt($compte, (int)Date::now()->timestamp + 1);
    }

    /** @return array<int, array<string, mixed>> */
    private function lignesDAppartenanceDe(int $compte): array
    {
        return DB::table('alliance_membership_history')->where('user_id', $compte)->orderBy('id')->get()->map(static fn (object $l): array => (array)$l)->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function lignesDeClasseDe(int $compte): array
    {
        return DB::table('character_class_history')->where('user_id', $compte)->orderBy('id')->get()->map(static fn (object $l): array => (array)$l)->all();
    }
}
