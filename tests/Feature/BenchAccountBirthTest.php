<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\History\ClassHistoryReader;
use OGame\History\ClassHistoryRecorder;
use Tests\AccountTestCase;
use Tests\RecordsClassHistory;
use Tests\Support\BackdatesTheBirthOfBenchAccounts;
use Tests\Support\DetachesFromAnyAlliance;

/**
 * **La naissance d un compte du banc n est pas un fait de jeu** — et la reparer ne doit effacer aucun fait.
 *
 * Les essais ne partagent pas la meme horloge : `AccountTestCase` gele au 1er janvier 2024, un essai peut avancer la
 * sienne, d autres tournent a l heure reelle. La ligne de creation d un compte est datee de l horloge du moment
 * (`ClassHistoryRecorder::accountCreated()`), et le voisin etranger est partage par tout le processus. Un compte ne
 * sous une horloge avancee est donc « ne apres » le ralliement de l essai suivant : le lecteur ne connait aucune
 * decision avant l ouverture, et le montage s arrete. La CI l a montre sur `c0161281`, la repartition des fichiers en
 * quatre processus decidant de la rencontre (journal §166).
 *
 * Ce temoin tient les quatre cas de la reparation : **ce qu elle repare, et les trois choses qu elle ne touche pas.**
 * Sans les trois derniers, une reparation trop large effacerait de vraies anomalies — exactement ce que le garde du
 * montage existe pour montrer.
 */
final class BenchAccountBirthTest extends AccountTestCase
{
    use BackdatesTheBirthOfBenchAccounts;
    use DetachesFromAnyAlliance;
    use RecordsClassHistory;

    /**
     * Les comptes dont cette classe a contredit l historique, pour que le dernier temoin relise ce qu elle laisse.
     *
     * Statique : `AccountTestCase` cree un compte par methode, et c est justement l etat **laisse par les methodes
     * precedentes** qu il faut relire.
     *
     * @var list<int>
     */
    private static array $comptesContredits = [];

    /**
     * **Cette classe contredit volontairement l historique : elle le remet en accord.**
     *
     * Ses scenarios effacent les lignes d un compte pour eprouver les frontieres de la reparation — et ce compte, avec
     * ses deux planetes, reste dans la base du processus, ou `getNearbyForeignPlanetFor()` peut l elire comme voisin
     * etranger pendant une quinzaine de classes. Un compte sans aucune ligne est precisement ce que l aide **refuse**
     * de reparer : il ferait suspendre la fermeture d un ralliement voisin, et la classe qui ferme ce defaut-la
     * l aurait introduit ailleurs. Les deux aides du depot remettent la derniere ligne en accord avec la colonne.
     */
    protected function tearDown(): void
    {
        $this->leaveTheClassHistoryCoherentFor($this->currentUserId);
        $this->leaveTheMembershipHistoryCoherentFor($this->currentUserId);

        parent::tearDown();
    }

    /**
     * Une naissance posterieure a l instant recule d une seconde avant lui, sans changer de valeur — et la classe
     * devient lisible a cet instant, ce qu elle n etait pas.
     */
    public function testABirthAfterTheInstantIsBroughtBackWithoutChangingItsValue(): void
    {
        $compte = $this->currentUserId;
        $instant = (int)Date::now()->timestamp;
        $naissance = $instant + 3600;
        $this->birthOf($compte, $naissance);

        $lecteur = resolve(ClassHistoryReader::class);
        $this->assertFalse($lecteur->personalClassAt($compte, $instant)->isKnown(), 'Premisse : ne apres l instant, le compte n a aucune decision connue.');
        $classeAvant = DB::table('character_class_history')->where('user_id', $compte)->orderBy('changed_at')->value('character_class');

        $this->backdateTheBirthOfABenchAccount($compte, $instant);

        $this->assertSame($instant - 1, (int)DB::table('character_class_history')->where('user_id', $compte)->orderBy('changed_at')->value('changed_at'), 'La naissance recule d une seconde avant l instant.');
        $this->assertSame($instant - 1, (int)DB::table('alliance_membership_history')->where('user_id', $compte)->orderBy('changed_at')->value('changed_at'), 'Les deux historiques reculent ensemble.');
        $this->assertSame($classeAvant, DB::table('character_class_history')->where('user_id', $compte)->orderBy('changed_at')->value('character_class'), 'La valeur ne change pas : seule la date recule.');
        $this->assertTrue($lecteur->personalClassAt($compte, $instant)->isKnown(), 'La classe est desormais lisible a l instant de l essai.');
    }

    /**
     * Une naissance anterieure ne bouge pas : il n y a rien a reparer, et deplacer une ligne juste serait mentir.
     */
    public function testABirthBeforeTheInstantIsLeftAlone(): void
    {
        $compte = $this->currentUserId;
        $instant = (int)Date::now()->timestamp;
        $naissance = $instant - 7200;
        $this->birthOf($compte, $naissance);

        $this->backdateTheBirthOfABenchAccount($compte, $instant);

        $this->assertSame($naissance, (int)DB::table('character_class_history')->where('user_id', $compte)->orderBy('changed_at')->value('changed_at'));
    }

    /**
     * **Une vraie decision datee trop tard reste ou elle est**, et le compte reste illisible a l instant : c est une
     * anomalie, pas un artefact d horloge, et le garde du montage doit continuer de la montrer.
     */
    public function testARealDecisionDatedTooLateIsNeverMoved(): void
    {
        $compte = $this->currentUserId;
        self::$comptesContredits[] = $compte;
        $instant = (int)Date::now()->timestamp;
        DB::table('character_class_history')->where('user_id', $compte)->delete();
        DB::table('alliance_membership_history')->where('user_id', $compte)->delete();
        DB::table('character_class_history')->insert([
            'user_id' => $compte,
            'character_class' => null,
            'changed_at' => $instant + 3600,
            'cause' => ClassHistoryRecorder::CAUSE_SELECTION,
        ]);

        $this->backdateTheBirthOfABenchAccount($compte, $instant);

        $this->assertSame($instant + 3600, (int)DB::table('character_class_history')->where('user_id', $compte)->value('changed_at'), 'Une decision n est pas une naissance : elle ne recule pas.');
        $this->assertFalse(resolve(ClassHistoryReader::class)->personalClassAt($compte, $instant)->isKnown(), 'Le compte reste illisible : le garde dira la verite.');
    }

    /**
     * Sans aucune ligne, rien n est invente : un banc qui ecrit une colonne sans son historique reste un defaut a
     * corriger chez lui, pas ici.
     */
    public function testNothingIsInventedWhenThereIsNoHistoryAtAll(): void
    {
        $compte = $this->currentUserId;
        self::$comptesContredits[] = $compte;
        $instant = (int)Date::now()->timestamp;
        DB::table('character_class_history')->where('user_id', $compte)->delete();
        DB::table('alliance_membership_history')->where('user_id', $compte)->delete();

        $this->backdateTheBirthOfABenchAccount($compte, $instant);

        $this->assertSame(0, DB::table('character_class_history')->where('user_id', $compte)->count());
        $this->assertSame(0, DB::table('alliance_membership_history')->where('user_id', $compte)->count());
        $this->assertFalse(resolve(ClassHistoryReader::class)->personalClassAt($compte, $instant)->isKnown());
    }

    /**
     * **Le monde que cette classe laisse derriere elle est lisible** — la moitie que seul un voisin verrait.
     *
     * Les scenarios ci-dessus contredisent volontairement l historique de leur compte, et ce compte, avec ses deux
     * planetes, reste dans la base du processus : `getNearbyForeignPlanetFor()` peut l elire comme voisin etranger
     * pendant une quinzaine de classes, dont la fermeture se suspendrait alors. Le retour vit dans `tearDown()`, donc
     * il s execute entre deux methodes : ce temoin, declare en dernier, relit les comptes que les precedents ont
     * contredits. Sans lui, retirer le retour ne casserait rien **ici** et casserait tout **ailleurs** — exactement le
     * defaut que cette classe ferme (revue du candidat, journal §166.2).
     */
    public function testTheWorldThisClassLeavesBehindStaysReadable(): void
    {
        $this->assertNotSame([], self::$comptesContredits, 'Premisse : aucun compte contredit, ce temoin ne prouverait rien.');

        $lecteur = resolve(ClassHistoryReader::class);
        $plusTard = (int)Date::now()->timestamp + 86400;

        foreach (self::$comptesContredits as $compte) {
            $this->assertTrue($lecteur->personalClassAt($compte, $plusTard)->isKnown(), 'Le compte ' . $compte . ' reste sans classe lisible : un ralliement voisin se suspendrait.');
            $this->assertTrue($lecteur->membershipAt($compte, $plusTard)->isKnown(), 'Le compte ' . $compte . ' reste sans appartenance lisible.');
        }
    }

    /**
     * Pose une naissance unique a cet instant, dans les deux historiques — l etat exact d un compte du banc qui vient
     * de naitre sous une horloge donnee.
     */
    private function birthOf(int $compte, int $instant): void
    {
        foreach (['character_class_history' => 'character_class', 'alliance_membership_history' => 'alliance_id'] as $table => $colonne) {
            DB::table($table)->where('user_id', $compte)->delete();
            DB::table($table)->insert([
                'user_id' => $compte,
                $colonne => null,
                'changed_at' => $instant,
                'cause' => ClassHistoryRecorder::CAUSE_CREATION,
            ]);
        }
    }
}
