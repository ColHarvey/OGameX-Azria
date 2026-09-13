<?php

namespace Tests\MariaDb;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Enums\CharacterClass;
use OGame\History\ClassHistoryReader;
use OGame\History\ClassHistoryRecorder;
use OGame\Models\User;
use PHPUnit\Framework\Attributes\Group;
use Tests\AccountTestCase;

/**
 * La classe **a l instant d admission**, lue pendant qu un autre processus en change.
 *
 * ## Ce que ce bac prouve, et que SQLite ne peut pas
 *
 * Le gel a l admission demande deux choses au lecteur des historiques : rendre la valeur de **l instant
 * demande**, et ne jamais rendre un melange — l historique d avant un changement confronte a la colonne
 * d apres. Ce melange serait pris pour une anomalie (« un changement ecrit sans sa ligne ») et
 * **suspendrait un combat qui n a rien** : le faux positif coute une bataille.
 *
 * Sous SQLite, un seul ecrivain a la fois : la fenetre n existe pas. Ici, un processus reel change la
 * classe en boucle — colonne et ligne dans la meme transaction, par l ecrivain du jeu — pendant qu un
 * autre reconstruit la classe a un instant passe.
 *
 * ## Etre dans une transaction ne suffit pas a le garantir
 *
 * La premiere version lisait la colonne, puis les lignes, en deux requetes : leur coherence dependait
 * alors du niveau d isolation, du genre de lecture et de ce que les autres validaient entre les deux.
 * Le lecteur prend desormais les deux dans **une seule requete**. Le second essai etablit que le danger
 * etait reel — un couple fabrique a la main, de part et d autre du changement, se contredit bien — et que
 * le lecteur, lui, n en souffre pas.
 *
 * ## Ce qui n a jamais tourne au moment de l ecrire
 *
 * Ce poste n a ni MariaDB ni `pcntl` : cette epreuve a ete ecrite et controlee (syntaxe, PHPStan),
 * **pas executee**. Le workflow MariaDB l exige par son nom (`--exige=`) ; c est le rapport JUnit du run
 * qui dira si elle a tourne, et comment.
 */
#[Group('mariadb')]
final class ClassHistoryVersusAdmissionRaceTest extends AccountTestCase
{
    use RunsInParallelProcesses;

    /**
     * Combien de fois le lecteur interroge l historique pendant que l ecrivain travaille.
     */
    private const int LECTURES = 300;

    private int $compte = 0;

    /**
     * L instant d admission eprouve : dans le passe, avant tout changement que la course ecrira.
     */
    private int $admission = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requiresMariaDb();
        $this->requiresProcesses();

        $this->compte = (int)User::factory()->create()->id;
        $maintenant = (int)Date::now()->timestamp;
        $this->admission = $maintenant - 900;

        // **L essai ecrit tout l historique qu il lit.** La ligne de naissance du compte est datee de
        // l horloge du banc, apres l admission eprouvee : laissee la, elle rendrait la valeur inconnue et
        // l essai mesurerait cela au lieu de la course.
        DB::table('character_class_history')->where('user_id', $this->compte)->delete();
        DB::table('character_class_history')->insert([
            ['user_id' => $this->compte, 'character_class' => null, 'changed_at' => $maintenant - 3_600, 'cause' => 'test'],
            ['user_id' => $this->compte, 'character_class' => CharacterClass::GENERAL->value, 'changed_at' => $maintenant - 1_800, 'cause' => 'test'],
        ]);
        DB::table('users')->where('id', $this->compte)->update(['character_class' => CharacterClass::GENERAL->value]);
    }

    protected function tearDown(): void
    {
        DB::table('character_class_history')->where('user_id', $this->compte)->delete();

        parent::tearDown();
    }

    /**
     * **Jamais un melange, et toujours la valeur de l instant d admission.**
     *
     * Un processus change la classe sans repit — colonne et ligne dans la meme transaction —, l autre
     * reconstruit la classe a l admission trois cents fois. Chaque reponse doit etre connue, et etre celle
     * du General acquis avant l admission : les changements de la course sont tous posterieurs a elle.
     */
    public function testTheClassAtAnAdmissionIsNeverAMixOfTheColumnAndTheHistory(): void
    {
        $arret = sys_get_temp_dir() . '/ogamex-classe-' . bin2hex(random_bytes(6));
        $compte = $this->compte;
        $admission = $this->admission;

        $lectures = 0;
        $inconnues = 0;
        $fausses = 0;
        $premiereRaison = '';
        $ecrituresAvant = 0;
        $ecrituresApres = 0;

        $issues = $this->inParallel(
            1,
            static function (int $rang) use ($compte, $arret): string {
                // **L ecrivain du jeu**, pas une ecriture a la main : la colonne et sa ligne dans la meme
                // transaction, tant que le lecteur travaille.
                $enregistreur = new ClassHistoryRecorder();
                $tours = 0;

                while (!file_exists($arret) && $tours < 5_000) {
                    $classe = $tours % 2 === 0 ? CharacterClass::COLLECTOR : CharacterClass::GENERAL;

                    DB::transaction(static function () use ($compte, $classe, $enregistreur): void {
                        DB::table('users')->where('id', $compte)->update(['character_class' => $classe->value]);
                        $enregistreur->personalClass($compte, $classe->value, 'test');
                    });

                    $tours++;
                    usleep(500);
                }

                return (string)$tours;
            },
            function () use ($compte, $admission, $arret, &$lectures, &$inconnues, &$fausses, &$premiereRaison, &$ecrituresAvant, &$ecrituresApres): void {
                $lecteur = new ClassHistoryReader();
                $ecrituresAvant = $this->changementsDeLaCourse();

                for ($i = 0; $i < self::LECTURES; $i++) {
                    $valeur = $lecteur->personalClassAt($compte, $admission);
                    $lectures++;

                    if (!$valeur->isKnown()) {
                        $inconnues++;
                        $premiereRaison = $premiereRaison === '' ? $valeur->reason : $premiereRaison;

                        continue;
                    }

                    if ($valeur->value() !== CharacterClass::GENERAL->value) {
                        $fausses++;
                    }
                }

                $ecrituresApres = $this->changementsDeLaCourse();
                touch($arret);
            }
        );

        @unlink($arret);

        // **La premisse d abord** : sans changement valide pendant ces lectures, la course n a pas eu lieu.
        $this->assertGreaterThan($ecrituresAvant, $ecrituresApres, 'No class change was committed while the reader was working: the race never happened and nothing is proved.');
        $this->assertGreaterThan(1, (int)$issues[0], 'The writer committed almost nothing.');

        $this->assertSame(self::LECTURES, $lectures);
        $this->assertSame(0, $inconnues, 'A read mixed the column and the history: a combat would suspend for an anomaly that never happened. First reason: ' . $premiereRaison);
        $this->assertSame(0, $fausses, 'A read did not return the class of the admission instant, but a value the race wrote after it.');
    }

    /**
     * **Le danger etait reel** : deux lectures separees, de part et d autre du changement, se contredisent.
     *
     * Le couple est fabrique a la main — la colonne avant, les lignes apres —, exactement comme deux
     * requetes distinctes pourraient tomber. Le lecteur, lui, prend les deux ensemble et repond.
     */
    public function testTwoSeparateReadsWouldMixWhereTheReaderDoesNot(): void
    {
        $compte = $this->compte;
        $colonneAvant = DB::table('users')->where('id', $compte)->value('character_class');

        $this->inParallel(1, static function (int $rang) use ($compte): string {
            DB::transaction(static function () use ($compte): void {
                DB::table('users')->where('id', $compte)->update(['character_class' => CharacterClass::COLLECTOR->value]);
                (new ClassHistoryRecorder())->personalClass($compte, CharacterClass::COLLECTOR->value, 'test');
            });

            return 'change';
        });

        $derniere = DB::table('character_class_history')
            ->where('user_id', $compte)
            ->orderByDesc('changed_at')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($derniere);
        $this->assertSame(CharacterClass::GENERAL->value, (int)$colonneAvant, 'The premise is missing: the column had already changed before the first read.');
        $this->assertSame(CharacterClass::COLLECTOR->value, (int)$derniere->character_class, 'The premise is missing: the concurrent process did not change the class.');
        $this->assertNotSame((int)$colonneAvant, (int)$derniere->character_class, 'The hand-made pair did not straddle the change: nothing would be proved.');

        $valeur = (new ClassHistoryReader())->personalClassAt($compte, $this->admission);

        $this->assertTrue($valeur->isKnown(), 'The reader mixed the column and the history across a concurrent change.');
        $this->assertSame(CharacterClass::GENERAL->value, $valeur->value(), 'The reader returned the current class instead of the class at the admission instant.');
    }

    /**
     * Les changements que la course a valides : ceux qui suivent l instant d admission.
     */
    private function changementsDeLaCourse(): int
    {
        return DB::table('character_class_history')
            ->where('user_id', $this->compte)
            ->where('changed_at', '>', $this->admission)
            ->count();
    }
}
