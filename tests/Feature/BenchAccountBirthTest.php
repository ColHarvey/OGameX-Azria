<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\History\ClassHistoryReader;
use OGame\History\ClassHistoryRecorder;
use Tests\AccountTestCase;
use Tests\Support\BackdatesTheBirthOfBenchAccounts;

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
        $instant = (int)Date::now()->timestamp;
        DB::table('character_class_history')->where('user_id', $compte)->delete();
        DB::table('alliance_membership_history')->where('user_id', $compte)->delete();

        $this->backdateTheBirthOfABenchAccount($compte, $instant);

        $this->assertSame(0, DB::table('character_class_history')->where('user_id', $compte)->count());
        $this->assertSame(0, DB::table('alliance_membership_history')->where('user_id', $compte)->count());
        $this->assertFalse(resolve(ClassHistoryReader::class)->personalClassAt($compte, $instant)->isKnown());
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
