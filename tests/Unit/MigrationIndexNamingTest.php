<?php

namespace Tests\Unit;

use Tests\UnitTestCase;

/**
 * Aucun nom d'index ne depasse ce que MariaDB accepte.
 *
 * ## Le defaut que cette garde existe pour ne pas revoir
 *
 * SQLite accepte un identifiant de n'importe quelle longueur ; **MariaDB s'arrete a 64
 * caracteres**. Une migration peut donc passer en local, passer dans la suite, et echouer au
 * deploiement — ce qui est deja arrive sur ce depot, et c'est pourquoi les migrations de combat
 * nomment leurs index explicitement.
 *
 * Le piege ne vient pas des noms qu'on ecrit : ceux-la sont courts parce qu'on les lit. Il vient de
 * ceux que **Laravel fabrique** quand on n'en donne pas. La regle est
 * `table_colonne1_colonne2_type`, et elle grandit vite :
 *
 *     combat_snapshot_inclusions_combat_instance_id_event_identity_projection_version_unique
 *
 * fait 85 caracteres. Aucune erreur en SQLite, un deploiement casse en production.
 *
 * ## Pourquoi la garde ne dit pas « nomme tes index »
 *
 * Vingt-neuf index multi-colonnes du depot n'ont pas de nom explicite, et ils vont tres bien : leurs
 * noms fabriques tiennent en quarante caracteres. Exiger un nom partout serait une regle de style
 * imposee apres coup a du code qui n'a rien fait de mal.
 *
 * La garde mesure donc **le nom effectif** — celui qu'on a ecrit, ou celui que Laravel fabriquera —
 * et ne se plaint que de ce qui casserait vraiment. Elle est vraie aujourd'hui, et elle tombera le
 * jour ou quelqu'un ajoutera un index de trois colonnes a une table au nom long sans le nommer.
 */
class MigrationIndexNamingTest extends UnitTestCase
{
    /**
     * La limite d'un identifiant MariaDB, en caracteres.
     */
    private const int MARIADB_IDENTIFIER_LIMIT = 64;

    /**
     * Aucun index, unique ou cle etrangere ne portera un nom trop long pour MariaDB.
     */
    public function testNoIndexNameExceedsWhatMariaDbAccepts(): void
    {
        $trop = [];
        $examines = 0;

        foreach ($this->declaredIndexNames() as $declaration) {
            $examines++;

            if (mb_strlen($declaration['nom']) > self::MARIADB_IDENTIFIER_LIMIT) {
                $trop[] = $declaration['fichier'] . ':' . $declaration['ligne']
                    . ' — ' . mb_strlen($declaration['nom']) . ' caracteres : ' . $declaration['nom'];
            }
        }

        $this->assertSame(
            [],
            $trop,
            "These index names are longer than MariaDB's 64-character limit. SQLite accepts them, so the "
            . "suite passes and the deployment fails.\n" . implode("\n", $trop)
        );

        $this->assertGreaterThan(
            150,
            $examines,
            'The scan stopped finding index declarations: check that the parser still recognises them.'
        );
    }

    /**
     * Le nom effectif de chaque index declare dans une migration.
     *
     * Explicite quand il est ecrit, fabrique par la regle de Laravel sinon.
     *
     * @return array<int, array{fichier: string, ligne: int, nom: string}>
     */
    private function declaredIndexNames(): array
    {
        $noms = [];

        foreach ($this->migrationFiles() as $fichier) {
            $lignes = file($fichier);

            if ($lignes === false) {
                continue;
            }

            $table = null;

            foreach ($lignes as $rang => $ligne) {
                $table = $this->tableNameOn($ligne) ?? $table;

                if ($table === null) {
                    continue;
                }

                $nom = $this->indexNameOn($ligne, $table);

                if ($nom === null) {
                    continue;
                }

                $noms[] = [
                    'fichier' => basename($fichier),
                    'ligne' => $rang + 1,
                    'nom' => $nom,
                ];
            }
        }

        return $noms;
    }

    /**
     * La table qu'une ligne `Schema::create()` ou `Schema::table()` ouvre, s'il y en a une.
     */
    private function tableNameOn(string $line): string|null
    {
        if (preg_match("/Schema::(?:create|table)\s*\(\s*'([^']+)'/", $line, $trouve) === 1) {
            return $trouve[1];
        }

        return null;
    }

    /**
     * Le nom effectif de l'index declare sur cette ligne, ou null s'il n'y en a pas.
     */
    private function indexNameOn(string $line, string $table): string|null
    {
        // `dropIndex` et `dropUnique` nomment un index existant : ils ne creent rien, et un nom
        // explicite y est de toute facon obligatoire.
        if (preg_match('/->(index|unique|foreign)\s*\(/', $line, $genre) !== 1) {
            return null;
        }

        $type = $genre[1] === 'foreign' ? 'foreign' : $genre[1];

        // Un nom explicite est la derniere chaine d'un appel a deux arguments.
        if (preg_match("/->(?:index|unique|foreign)\s*\(\s*(?:\[[^\]]*\]|'[^']*')\s*,\s*'([^']+)'/", $line, $explicite) === 1) {
            return $explicite[1];
        }

        // Sinon Laravel fabrique `table_colonne1_colonne2_type`.
        $colonnes = $this->indexedColumnsOn($line);

        if ($colonnes === []) {
            return null;
        }

        return strtolower($table . '_' . implode('_', $colonnes) . '_' . $type);
    }

    /**
     * Les colonnes que cette ligne indexe.
     *
     * Deux formes : l'appel qui les nomme — `index(['a', 'b'])` — et l'appel chaine sans argument,
     * `string('a')->unique()`, ou la colonne est celle que la ligne declare.
     *
     * @return array<int, string>
     */
    private function indexedColumnsOn(string $line): array
    {
        if (preg_match("/->(?:index|unique|foreign)\s*\(\s*\[([^\]]*)\]/", $line, $liste) === 1) {
            preg_match_all("/'([^']+)'/", $liste[1], $trouves);

            return $trouves[1];
        }

        if (preg_match("/->(?:index|unique|foreign)\s*\(\s*'([^']+)'\s*\)/", $line, $seule) === 1) {
            return [$seule[1]];
        }

        // `$table->string('queue')->index();` — la colonne est la premiere chaine de la ligne.
        if (preg_match("/->(?:index|unique|foreign)\s*\(\s*\)/", $line) === 1
            && preg_match("/'([^']+)'/", $line, $premiere) === 1) {
            return [$premiere[1]];
        }

        return [];
    }

    /**
     * Tous les fichiers de migration du depot.
     *
     * @return array<int, string>
     */
    private function migrationFiles(): array
    {
        $fichiers = glob(base_path('database/migrations') . '/*.php');

        return $fichiers === false ? [] : $fichiers;
    }
}
