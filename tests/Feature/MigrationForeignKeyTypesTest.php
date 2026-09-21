<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * **Le type d une colonne de cle etrangere ne se prouve pas sous SQLite.**
 *
 * Mesure du 20 septembre 2026, au bac MariaDB : `daily_rewards.user_id` etait declaree
 * `unsignedBigInteger` alors que `users.id` est un `increments`, donc `int unsigned`. MariaDB refuse la
 * creation de la table — erreur 1005, `errno: 150 "Foreign key constraint is incorrectly formed"` — tandis
 * que SQLite, qui range tout entier dans la meme classe d affinite, l accepte sans rien dire. La suite
 * locale etait verte, et la faute n a ete vue qu en integration continue.
 *
 * Ce temoin la voit **sans base** : il lit les migrations comme du texte et confronte, pour chaque cle
 * etrangere, la famille de la colonne portante a celle de l identite visee.
 *
 *   $table->id()                 -> bigint unsigned  -> exige unsignedBigInteger / bigInteger(unsigned)
 *   $table->bigIncrements('id')  -> bigint unsigned  -> idem
 *   $table->increments('id')     -> int unsigned     -> exige unsignedInteger / integer(..., true)
 *
 * **Il refuse aussi ce qu il ne sait pas lire.** Une cle etrangere dont la colonne ou la table visee reste
 * introuvable est rapportee comme un manque, jamais passee sous silence : un essai qui ne cherche que ce
 * qu il attend ne voit pas ce qui manque.
 */
class MigrationForeignKeyTypesTest extends TestCase
{
    private const GRAND = 'bigint unsigned';

    private const PETIT = 'int unsigned';

    /**
     * Toutes les cles etrangeres du depot portent une colonne du type de l identite qu elles visent.
     */
    public function testEveryForeignKeyColumnMatchesTheIdentityItReferences(): void
    {
        $migrations = $this->migrations();
        $this->assertGreaterThan(80, count($migrations), 'Premisse : les migrations sont bien lues.');

        $tables = $this->colonnesParTable($migrations);
        $this->assertSame(
            self::PETIT,
            $tables['users']['id'] ?? null,
            'Premisse : `users.id` est un `increments`, donc `int unsigned`. Si cela change, ce temoin doit changer aussi.'
        );

        $fautes = [];
        $illisibles = [];
        $vues = 0;

        foreach ($migrations as $chemin => $source) {
            foreach ($this->clesEtrangeres($source) as [$colonne, $cible, $colonneCible]) {
                $vues++;

                $attendu = $tables[$cible][$colonneCible] ?? null;
                if ($attendu === null) {
                    $illisibles[] = basename($chemin) . " : `$cible`.`$colonneCible` introuvable";

                    continue;
                }

                $declare = $this->typeDeLaColonne($migrations, $colonne, $chemin);
                if ($declare === null) {
                    $illisibles[] = basename($chemin) . " : declaration de `$colonne` introuvable";

                    continue;
                }

                if ($declare !== $attendu) {
                    $fautes[] = basename($chemin)
                        . " : `$colonne` est $declare, mais `$cible`.`$colonneCible` est $attendu";
                }
            }
        }

        $this->assertGreaterThan(60, $vues, 'Premisse : les cles etrangeres sont bien reperees dans le texte.');
        $this->assertSame([], $illisibles, "Des cles etrangeres n ont pas pu etre verifiees :\n  " . implode("\n  ", $illisibles));
        $this->assertSame([], $fautes, "MariaDB refuserait ces cles etrangeres (erreur 1005, errno 150) :\n  " . implode("\n  ", $fautes));
    }

    /**
     * @return array<string, string> chemin => source
     */
    private function migrations(): array
    {
        $out = [];
        foreach (glob(database_path('migrations/*.php')) ?: [] as $chemin) {
            $out[$chemin] = (string)file_get_contents($chemin);
        }

        ksort($out);

        return $out;
    }

    /**
     * La famille de **chaque colonne** de chaque table creee, pas seulement de son identite.
     *
     * Une premiere version ne relevait que l identite, et ne reconnaissait donc que les cles etrangeres en
     * `references('id')`. Une cle vers une autre colonne unique — `announcement_bubble_dismissals.version`
     * vers `announcement_bubble_versions.version` — etait **sautee en silence**, ce que ce temoin promet
     * precisement de ne pas faire.
     *
     * @param array<string, string> $migrations
     * @return array<string, array<string, string>>
     */
    private function colonnesParTable(array $migrations): array
    {
        $tables = [];

        foreach ($migrations as $source) {
            // On decoupe sur les `Schema::create('<table>', ...)` pour attribuer chaque colonne a sa table.
            $morceaux = preg_split("/Schema::create\\('([a-z_]+)'/", $source, -1, PREG_SPLIT_DELIM_CAPTURE);
            if ($morceaux === false) {
                continue;
            }

            for ($i = 1; $i < count($morceaux); $i += 2) {
                $table = $morceaux[$i];
                $corps = $morceaux[$i + 1] ?? '';

                if (preg_match("/\\\$table->bigIncrements\\('id'\\)|\\\$table->id\\(\\)/", $corps) === 1) {
                    $tables[$table]['id'] = self::GRAND;
                } elseif (preg_match("/\\\$table->increments\\('id'\\)/", $corps) === 1) {
                    $tables[$table]['id'] = self::PETIT;
                }

                if (preg_match_all("/\\\$table->(unsignedBigInteger|unsignedInteger|bigIncrements|increments|foreignId)\\('([a-z_]+)'\\)/", $corps, $m, PREG_SET_ORDER) !== false) {
                    foreach ($m as $set) {
                        $grand = in_array($set[1], ['unsignedBigInteger', 'bigIncrements', 'foreignId'], true);
                        $tables[$table][$set[2]] = $grand ? self::GRAND : self::PETIT;
                    }
                }
            }
        }

        return $tables;
    }

    /**
     * Les triplets (colonne portante, table visee, colonne visee) d une migration.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function clesEtrangeres(string $source): array
    {
        // `foreign('col')` ou `foreign('col', 'nom_de_index')`, puis la colonne et la table visees.
        $motif = "/->foreign\\(\\s*'([a-z_]+)'(?:\\s*,\\s*'[a-z_0-9]+')?\\s*\\)[^;]*?->references\\('([a-z_]+)'\\)->on\\('([a-z_]+)'\\)/s";
        if (preg_match_all($motif, $source, $m, PREG_SET_ORDER) === false) {
            return [];
        }

        return array_map(static fn (array $set): array => [$set[1], $set[3], $set[2]], $m);
    }

    /**
     * La famille de la colonne portante : cherchee d abord dans sa propre migration, puis dans les autres —
     * une colonne peut etre ajoutee par une migration et contrainte par une suivante.
     *
     * @param array<string, string> $migrations
     */
    private function typeDeLaColonne(array $migrations, string $colonne, string $prefere): string|null
    {
        $ordre = [$prefere => $migrations[$prefere]] + $migrations;

        foreach ($ordre as $source) {
            $c = preg_quote($colonne, '/');

            // Trois ecritures existent dans le depot pour le meme type, et il faut les lire toutes :
            //   $table->unsignedBigInteger('col')
            //   $table->bigInteger('col', false, true)        (troisieme argument)
            //   $table->bigInteger('col')->unsigned()          (modificateur)
            if (preg_match("/\\\$table->(?:unsignedBigInteger|bigIncrements|foreignId)\\('$c'\\)/", $source) === 1
                || preg_match("/\\\$table->bigInteger\\('$c'\\s*,\\s*(?:false|true)\\s*,\\s*true\\)/", $source) === 1
                || preg_match("/\\\$table->bigInteger\\('$c'\\)->unsigned\\(\\)/", $source) === 1) {
                return self::GRAND;
            }

            if (preg_match("/\\\$table->(?:unsignedInteger|increments)\\('$c'\\)/", $source) === 1
                || preg_match("/\\\$table->integer\\('$c'\\s*,\\s*(?:false|true)\\s*,\\s*true\\)/", $source) === 1
                || preg_match("/\\\$table->integer\\('$c'\\)->unsigned\\(\\)/", $source) === 1) {
                return self::PETIT;
            }
        }

        return null;
    }
}
