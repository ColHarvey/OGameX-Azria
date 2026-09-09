<?php

namespace OGame\Combat\Services;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Exceptions\StepAlreadyPlayed;
use OGame\Combat\Replay\BattleFieldStateCodec;
use OGame\GameMissions\BattleEngine\State\BattleFieldState;
use RuntimeException;

/**
 * L ecriture et la relecture d une etape de bataille — une seule porte, une seule transaction.
 *
 * ## Le defaut que cette classe existe pour rendre impossible
 *
 * Une etape produit **deux ecritures** : l etat du champ apres le round, et le round lui-meme dans
 * la chronologie que le joueur lit. Separees, une panne entre les deux ferait perdre un round au
 * joueur — l etat avance, l historique non — ou le lui ferait rejouer — l historique avance, l etat
 * non.
 *
 * La regle ne peut pas etre « l appelant pensera a ouvrir une transaction » : elle serait tenue par
 * la memoire de qui ecrit le prochain chemin. `writeStep()` prend donc l historique **avec** l etat
 * et ecrit les deux, ou n ecrit rien.
 *
 * ## L idempotence vient de la cle, pas d une lecture prealable
 *
 * L unicite `(combat, index)` refuse la seconde ecriture d une meme etape. Une verification « la
 * ligne existe-t-elle deja ? » avant l insertion ne serait qu une lecture de plus, et deux
 * travailleurs pourraient la passer ensemble. La cle, elle, tranche dans la base.
 *
 * **Et surtout, pas de compte de lignes affectees** : MariaDB compte les lignes changees, SQLite les
 * lignes trouvees. Le depot a paye huit jours de production sur cette difference — un battement de
 * bail qui reecrivait la meme seconde rendait zero et faisait rendre le bail.
 *
 * ## La relecture se fait sous verrou
 *
 * `heldLatestStep()` verrouille la derniere ligne : un travailleur qui decide du prochain round doit
 * savoir qu aucun autre n est en train d ecrire celui-ci. Sous SQLite `lockForUpdate()` ne compile a
 * rien — ce que les essais locaux montrent est la forme, la preuve appartient au banc MariaDB.
 *
 * ## Inerte
 *
 * Aucun chemin du jeu n appelle encore cette classe : l avanceur progressif n existe pas. Elle est
 * ecrite avec ses epreuves d abord, comme le reste du socle.
 */
final class BattleFieldStateStore
{
    /**
     * Ecrit une etape : son etat, et l historique du round qui vient d etre joue.
     *
     * @param Closure(): void $history Ce que le round a produit pour le joueur, ecrit dans la meme
     *        transaction que l etat. Vide si l etape n a rien a montrer — l ouverture du champ, par
     *        exemple, qui est l etape zero.
     *
     * @throws StepAlreadyPlayed Si cette etape est deja ecrite.
     */
    public function writeStep(int $combatInstanceId, int $stepIndex, BattleFieldState $state, Closure $history): void
    {
        $ecrit = BattleFieldStateCodec::toStorage($state);
        $maintenant = now();

        DB::transaction(function () use ($combatInstanceId, $stepIndex, $ecrit, $history, $maintenant): void {
            try {
                DB::table('combat_field_states')->insert([
                    'combat_instance_id' => $combatInstanceId,
                    'step_index' => $stepIndex,
                    'schema_version' => BattleFieldStateCodec::SCHEMA,
                    'state' => json_encode($ecrit, JSON_THROW_ON_ERROR),
                    'created_at' => $maintenant,
                    'updated_at' => $maintenant,
                ]);
            } catch (QueryException $course) {
                // **Relire tranche.** Une insertion peut echouer pour autre chose qu un doublon ;
                // ne rendre `StepAlreadyPlayed` que si la ligne est bien la, et laisser remonter le
                // reste tel quel.
                if (!$this->stepExists($combatInstanceId, $stepIndex)) {
                    throw $course;
                }

                throw new StepAlreadyPlayed($combatInstanceId, $stepIndex);
            }

            $history();
        });
    }

    /**
     * La derniere etape ecrite pour ce combat, **verrouillee**, ou `null` s il n y en a aucune.
     *
     * @return array{index: int, state: BattleFieldState}|null
     */
    public function heldLatestStep(int $combatInstanceId): array|null
    {
        $ligne = DB::table('combat_field_states')
            ->where('combat_instance_id', $combatInstanceId)
            ->orderByDesc('step_index')
            ->lockForUpdate()
            ->first();

        if ($ligne === null) {
            return null;
        }

        $ecrit = json_decode((string)$ligne->state, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($ecrit)) {
            throw new RuntimeException('The written state of combat ' . $combatInstanceId . ' is not an array.');
        }

        return [
            'index' => (int)$ligne->step_index,
            'state' => BattleFieldStateCodec::fromStorage($ecrit),
        ];
    }

    /**
     * Combien d etapes ce combat a-t-il ecrites ? Sans verrou : pour lire, pas pour decider.
     */
    public function stepsWritten(int $combatInstanceId): int
    {
        return DB::table('combat_field_states')->where('combat_instance_id', $combatInstanceId)->count();
    }

    private function stepExists(int $combatInstanceId, int $stepIndex): bool
    {
        return DB::table('combat_field_states')
            ->where('combat_instance_id', $combatInstanceId)
            ->where('step_index', $stepIndex)
            ->exists();
    }
}
