<?php

namespace Tests;

use Illuminate\Support\Facades\DB;
use OGame\Enums\CharacterClass;
use OGame\History\ClassHistoryRecorder;

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
        DB::transaction(static function () use ($userId, $classe): void {
            DB::table('users')->where('id', $userId)->update(['character_class' => $classe?->value]);
            resolve(ClassHistoryRecorder::class)->personalClass($userId, $classe?->value, 'test');
        });
    }
}
