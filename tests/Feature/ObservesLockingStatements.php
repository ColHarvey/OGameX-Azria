<?php

namespace Tests\Feature;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Support\Facades\DB;

/**
 * **Rendre visibles, sous SQLite, les verrous que le jeu demande.**
 *
 * `lockForUpdate()` ne compile à rien sous SQLite : la requête émise ne dit pas qu'elle voulait un verrou. Le temps
 * d'un geste, la connexion reçoit une grammaire qui écrit le verrou demandé en **commentaire SQL** —
 * `/* for update *` + `/` —, que SQLite accepte et ignore. Chaque instruction verrouillante devient observable,
 * avec sa table, ses liaisons, et dans l'ordre où le jeu la demande.
 *
 * Ce témoin **caractérise la forme**. L'ordre effectivement obtenu, les attentes, les verrous d'intervalle et
 * l'absence d'interblocage appartiennent aux courses du bac MariaDB.
 */
trait ObservesLockingStatements
{
    /**
     * @return list<array{table: string, sql: string, lock: bool, bindings: array<int, mixed>}>
     */
    protected function statementsOf(Closure $geste): array
    {
        $connexion = DB::connection();
        $grammaireDOrigine = $connexion->getQueryGrammar();

        $connexion->setQueryGrammar(new class ($connexion) extends SQLiteGrammar {
            protected function compileLock(Builder $query, $value): string
            {
                return $value === true ? '/* for update */' : '';
            }
        });

        $etat = new class () {
            public bool $ecoute = true;

            /** @var list<array{table: string, sql: string, lock: bool, bindings: array<int, mixed>}> */
            public array $vues = [];
        };

        DB::listen(static function (QueryExecuted $requete) use ($etat): void {
            if (!$etat->ecoute) {
                return;
            }

            $sql = str_replace('`', '"', $requete->sql);

            if (preg_match('/^\s*(?:select\s.*?\sfrom|update|insert\s+into|delete\s+from)\s+"([a-z_]+)"/is', $sql, $table) !== 1) {
                return;
            }

            $etat->vues[] = [
                'table' => $table[1],
                'sql' => $sql,
                'lock' => str_contains($sql, '/* for update */'),
                'bindings' => array_values($requete->bindings),
            ];
        });

        try {
            $geste();
        } finally {
            $etat->ecoute = false;
            $connexion->setQueryGrammar($grammaireDOrigine);
        }

        return $etat->vues;
    }

    /**
     * Les tables des instructions verrouillantes, dans l'ordre.
     *
     * @param list<array{table: string, sql: string, lock: bool, bindings: array<int, mixed>}> $instructions
     * @return list<string>
     */
    protected function lockedTablesIn(array $instructions): array
    {
        $tables = [];

        foreach ($instructions as $instruction) {
            if ($instruction['lock']) {
                $tables[] = $instruction['table'];
            }
        }

        return $tables;
    }
}
