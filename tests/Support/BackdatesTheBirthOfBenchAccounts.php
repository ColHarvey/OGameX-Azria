<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use OGame\History\ClassHistoryRecorder;

/**
 * Ramene la **naissance** d un compte du banc avant l instant qu un essai va employer.
 *
 * ## Le piege, mesure le 19 septembre 2026
 *
 * `ClassHistoryRecorder::accountCreated()` date la ligne de creation de `Date::now()` — l horloge **simulee** du moment
 * ou le compte nait. Les essais ne partagent pas tous la meme horloge : `AccountTestCase` gele au 1er janvier 2024, un
 * essai peut avancer la sienne, et certains tournent a l heure reelle. Le voisin etranger, lui, est **partage par tout
 * le processus** : un compte ne sous une horloge avancee est donc « ne apres » le ralliement de l essai suivant, et le
 * lecteur d historique ne connait aucune decision avant l ouverture. La fermeture se suspendrait pour cela, et le
 * montage s arrete en le disant (`requireAnAdmissibleHistoryFor`). C est ce qui a fait rougir
 * `MissileAgainstAHeldBodyTest` en integration continue, jamais sur un poste a seize processus : la repartition des
 * fichiers decide de la rencontre (journal §166).
 *
 * ## Ce qui est repare, et ce qui ne l est pas
 *
 * Au plus etroit, parce qu il s agit d effacer un artefact d horloge sans effacer un fait :
 *
 * - seule la **premiere** ligne de chaque historique, et seulement si sa cause est la creation ;
 * - seulement si elle est **posterieure** a l instant ;
 * - **aucune valeur ne change** : la classe et l appartenance restent celles du compte, seule la date recule.
 *
 * Une ligne absente, une classe ecrite sans sa ligne, une vraie decision datee trop tard : rien de cela n est repare
 * ici, et le garde doit continuer de le dire. `BenchAccountBirthTest` tient ces quatre cas.
 */
trait BackdatesTheBirthOfBenchAccounts
{
    protected function backdateTheBirthOfABenchAccount(int $playerId, int $instant): void
    {
        foreach (['character_class_history', 'alliance_membership_history'] as $table) {
            $premiere = DB::table($table)->where('user_id', $playerId)->orderBy('changed_at')->orderBy('id')->first();

            if ($premiere === null || (int)$premiere->changed_at <= $instant || (string)$premiere->cause !== ClassHistoryRecorder::CAUSE_CREATION) {
                continue;
            }

            DB::table($table)->where('id', $premiere->id)->update(['changed_at' => $instant - 1]);
        }
    }
}
