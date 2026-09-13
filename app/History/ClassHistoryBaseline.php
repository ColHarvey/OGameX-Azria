<?php

namespace OGame\History;

use Illuminate\Support\Facades\DB;

/**
 * La ligne de base des trois historiques : l etat present, date de l instant ou elle s ecrit.
 *
 * ## Ce qu elle affirme, et rien de plus
 *
 * « A cet instant, ce compte avait cette classe et cette alliance ; cette alliance avait cette classe. »
 * Elle ne dit rien d avant : dater la ligne de `joined_at` ou de `character_class_changed_at` ferait
 * croire que l on connait ce qui precede, alors que la valeur d avant n a jamais ete gardee.
 *
 * Elle ne passe par aucun service et ne lit que les colonnes : elle est appelee par une migration, qui
 * doit rester executable quand les services auront change.
 */
final class ClassHistoryBaseline
{
    public const string CAUSE = 'migration';

    private const int CHUNK = 500;

    public function writeAt(int $instant): void
    {
        DB::table('users')
            ->orderBy('id')
            ->select(['id', 'character_class', 'alliance_id'])
            ->chunk(self::CHUNK, static function ($comptes) use ($instant): void {
                $classes = [];
                $appartenances = [];

                foreach ($comptes as $compte) {
                    $classes[] = [
                        'user_id' => (int)$compte->id,
                        'character_class' => $compte->character_class === null ? null : (int)$compte->character_class,
                        'changed_at' => $instant,
                        'cause' => self::CAUSE,
                    ];
                    $appartenances[] = [
                        'user_id' => (int)$compte->id,
                        'alliance_id' => $compte->alliance_id === null ? null : (int)$compte->alliance_id,
                        'changed_at' => $instant,
                        'cause' => self::CAUSE,
                    ];
                }

                DB::table('character_class_history')->insert($classes);
                DB::table('alliance_membership_history')->insert($appartenances);
            });

        DB::table('alliances')
            ->orderBy('id')
            ->select(['id', 'alliance_class'])
            ->chunk(self::CHUNK, static function ($alliances) use ($instant): void {
                $lignes = [];

                foreach ($alliances as $alliance) {
                    $lignes[] = [
                        'alliance_id' => (int)$alliance->id,
                        'alliance_class' => $alliance->alliance_class === null ? null : (string)$alliance->alliance_class,
                        'changed_at' => $instant,
                        'cause' => self::CAUSE,
                    ];
                }

                DB::table('alliance_class_history')->insert($lignes);
            });
    }
}
