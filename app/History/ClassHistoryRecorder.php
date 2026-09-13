<?php

namespace OGame\History;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use LogicException;
use OGame\Models\User;

/**
 * L ecrivain unique des trois historiques de classe.
 *
 * ## Dans la transaction du changement, et nulle part ailleurs
 *
 * Chaque methode exige d etre appelee **dans une transaction ouverte** : celle qui change la colonne du
 * compte ou de l alliance. Un changement valide sans sa ligne laisserait un historique qui ne dit plus
 * la verite ; une ligne sans le changement, un historique qui ment dans l autre sens. L exigence est
 * verifiee a l execution, pas seulement supposee : un appel hors transaction leve.
 *
 * La seule exception est la **creation d un compte**, ecrite par l observateur du modele au moment de
 * l insertion : elle partage la transaction de l appelant quand il en ouvre une — l inscription, le
 * compte systeme, les bases pirates le font —, et une fabrique d essai n en ouvre pas.
 *
 * ## L instant
 *
 * L horloge du jeu (`Date::now()`), a la seconde, comme tout instant du combat.
 */
final class ClassHistoryRecorder
{
    public const string CAUSE_CREATION = 'creation';

    public const string CAUSE_SELECTION = 'selection';

    public const string CAUSE_DESELECTION = 'deselection';

    public const string CAUSE_ADMIN_RESET = 'admin_reset';

    public const string CAUSE_DEV_SEED = 'dev_seed';

    public const string CAUSE_JOIN = 'join';

    public const string CAUSE_LEAVE = 'leave';

    public const string CAUSE_KICK = 'kick';

    public const string CAUSE_DISBAND = 'disband';

    public const string CAUSE_ALLIANCE_CREATION = 'alliance_creation';

    public const string CAUSE_CHOICE = 'choice';

    /**
     * La classe personnelle d un compte vient de changer.
     */
    public function personalClass(int $userId, int|null $characterClass, string $cause): void
    {
        $this->mustShareTheChangeTransaction('la classe personnelle');

        DB::table('character_class_history')->insert([
            'user_id' => $userId,
            'character_class' => $characterClass,
            'changed_at' => (int)Date::now()->timestamp,
            'cause' => $cause,
        ]);
    }

    /**
     * L appartenance d un compte vient de changer : une alliance, ou aucune.
     */
    public function membership(int $userId, int|null $allianceId, string $cause): void
    {
        $this->mustShareTheChangeTransaction('l appartenance a une alliance');

        DB::table('alliance_membership_history')->insert([
            'user_id' => $userId,
            'alliance_id' => $allianceId,
            'changed_at' => (int)Date::now()->timestamp,
            'cause' => $cause,
        ]);
    }

    /**
     * La classe d une alliance vient de changer. Le nom est celui que la colonne stocke.
     */
    public function allianceClass(int $allianceId, string|null $allianceClassName, string $cause): void
    {
        $this->mustShareTheChangeTransaction('la classe d une alliance');

        DB::table('alliance_class_history')->insert([
            'alliance_id' => $allianceId,
            'alliance_class' => $allianceClassName,
            'changed_at' => (int)Date::now()->timestamp,
            'cause' => $cause,
        ]);
    }

    /**
     * L etat initial d un compte qui vient d etre insere : sa classe et son alliance telles qu il nait.
     */
    public function accountCreated(User $user): void
    {
        $instant = (int)Date::now()->timestamp;
        $id = (int)$user->id;

        DB::table('character_class_history')->insert([
            'user_id' => $id,
            'character_class' => $user->character_class === null ? null : (int)$user->character_class,
            'changed_at' => $instant,
            'cause' => self::CAUSE_CREATION,
        ]);

        DB::table('alliance_membership_history')->insert([
            'user_id' => $id,
            'alliance_id' => $user->alliance_id === null ? null : (int)$user->alliance_id,
            'changed_at' => $instant,
            'cause' => self::CAUSE_CREATION,
        ]);
    }

    private function mustShareTheChangeTransaction(string $quoi): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'L historique de ' . $quoi . ' s ecrit hors de toute transaction : le changement et sa ligne '
                . 'doivent etre valides ensemble, ou pas du tout.'
            );
        }
    }
}
