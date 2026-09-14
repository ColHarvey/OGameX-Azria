<?php

namespace Tests;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Enums\CharacterClass;
use OGame\History\ClassHistoryRecorder;
use OGame\Models\User;

/**
 * Poser une classe personnelle **comme le jeu la pose** : la colonne et sa ligne d historique, dans la meme
 * transaction.
 *
 * ## Pourquoi un banc ne peut plus ecrire la colonne seule
 *
 * Le gel d une flotte a son admission relit la classe dans l historique, et confronte la derniere ligne a
 * la colonne du compte. Une colonne ecrite seule est exactement l anomalie que cette confrontation existe
 * pour voir : le combat se suspendrait, et l essai mesurerait une suspension au lieu de ce qu il croit
 * mesurer. Un faux porte la regle du vrai.
 *
 * L instant de la ligne est l horloge du banc : poser la classe **avant** le voyage vers l arrivee la fait
 * compter, la poser a la seconde de l arrivee ou apres ne la fait pas compter.
 */
trait RecordsClassHistory
{
    protected function recordCharacterClass(int $userId, CharacterClass|null $classe): void
    {
        $this->refuseALineThatWouldNotBeTheLast($userId);

        DB::transaction(static function () use ($userId, $classe): void {
            DB::table('users')->where('id', $userId)->update(['character_class' => $classe?->value]);
            resolve(ClassHistoryRecorder::class)->personalClass($userId, $classe?->value, 'test');
        });
    }

    /**
     * **Une ligne datee avant la derniere n est pas la derniere.** Le lecteur confronte la colonne a la ligne la plus
     * recente par instant : une classe posee apres un voyage en arriere — le reglement ramene l horloge a l echeance —
     * laisse la colonne contredite par une ligne plus tardive, et le compte illisible pour le prochain combat. Mesure :
     * `ClassIdentityFrozenAtAdmissionTest` remettait le proprietaire de la cible en etat cinq secondes avant la ligne
     * qu il annulait.
     */
    private function refuseALineThatWouldNotBeTheLast(int $userId): void
    {
        $maintenant = (int)Date::now()->timestamp;
        $plusTardive = DB::table('character_class_history')->where('user_id', $userId)->max('changed_at');

        if (is_numeric($plusTardive) && (int)$plusTardive > $maintenant) {
            $this->fail(
                'La classe du compte ' . $userId . ' serait ecrite a l instant ' . $maintenant . ', avant sa derniere ligne ('
                . (int)$plusTardive . ') : le lecteur ne la verrait pas, et le compte resterait illisible. Avancer l horloge avant de la poser.'
            );
        }
    }

    /**
     * La meme chose sur un modele deja charge, qui reste a jour en memoire : un banc qui tient le joueur par son
     * service continue a lire la classe qu il vient de poser.
     *
     * Vingt sites ecrivaient `$user->character_class = ...` puis `save()` : la colonne sans sa ligne, laissee au
     * processus, et le prochain combat durable qui gelait ce compte se suspendait.
     */
    protected function recordCharacterClassOn(User $user, CharacterClass|null $classe): void
    {
        $this->refuseALineThatWouldNotBeTheLast((int)$user->id);

        DB::transaction(static function () use ($user, $classe): void {
            $user->character_class = $classe?->value;
            $user->save();
            resolve(ClassHistoryRecorder::class)->personalClass((int)$user->id, $classe?->value, 'test');
        });
    }

    /**
     * Remet en accord l historique d un compte que l essai a **volontairement** contredit — une colonne ecrite
     * seule pour eprouver la suspension —, en ecrivant la ligne que la colonne attend. Le scenario negatif reste
     * entier ; c est le demontage qui appelle ceci, pour ne pas laisser au processus un compte qui suspendrait le
     * combat d un voisin.
     */
    protected function leaveTheClassHistoryCoherentFor(int $userId): void
    {
        $colonne = DB::table('users')->where('id', $userId)->value('character_class');
        $derniere = DB::table('character_class_history')
            ->where('user_id', $userId)
            ->latest('changed_at')
            ->orderByDesc('id')
            ->first(['character_class', 'changed_at']);

        $classe = $colonne === null ? null : (int)$colonne;

        if ($derniere !== null && ($derniere->character_class === null ? null : (int)$derniere->character_class) === $classe) {
            return;
        }

        // **Apres la derniere ligne, jamais avant** : l essai a pu ramener l horloge en arriere.
        $instant = max((int)Date::now()->timestamp, $derniere === null ? 0 : (int)$derniere->changed_at + 1);
        $horloge = Date::now();
        Date::setTestNow(Date::createFromTimestamp($instant));

        try {
            DB::transaction(static function () use ($userId, $classe): void {
                resolve(ClassHistoryRecorder::class)->personalClass($userId, $classe, 'test');
            });
        } finally {
            Date::setTestNow($horloge);
        }
    }
}
