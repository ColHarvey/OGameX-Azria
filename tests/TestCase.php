<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use OGame\Models\Planet\Coordinate;
use OGame\Services\SettingsService;

abstract class TestCase extends BaseTestCase
{
    /**
     * Creates the application.
     *
     * @return Application
     */
    public function createApplication(): Application
    {
        $app = require __DIR__ . '/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        $this->registerSqliteCompatibilityFunctions();
        $this->usePhpBattleEngineWhenRustIsUnavailable();

        return $app;
    }

    /**
     * Find an empty coordinate near the given anchor using the shared collision-safe test logic.
     */
    protected function getSafeEmptyCoordinate(
        Coordinate $anchor,
        int $minPosition = 4,
        int $maxPosition = 12,
        int $minSystemDistance = 0
    ): Coordinate {
        $settingsService = resolve(SettingsService::class);
        $maxGalaxies = $settingsService->numberOfGalaxies();

        $galaxy = $anchor->galaxy <= $maxGalaxies ? $anchor->galaxy : 1;
        $coordinate = new Coordinate($galaxy, 0, 0);
        $tryCount = 0;

        while ($tryCount < 100) {
            $tryCount++;

            do {
                $offset = rand(-10, 10);
            } while ($minSystemDistance > 0 && abs($offset) < $minSystemDistance);

            $coordinate->system = max(1, min(499, $anchor->system + $offset));
            $coordinate->position = rand($minPosition, $maxPosition);

            $planetCount = DB::table('planets')
                ->where('galaxy', $coordinate->galaxy)
                ->where('system', $coordinate->system)
                ->where('planet', $coordinate->position)
                ->count();

            if ($planetCount === 0) {
                return $coordinate;
            }
        }

        $this->fail('Failed to find an empty coordinate for testing.');
    }

    /**
     * Teach the SQLite test database the MariaDB functions it lacks.
     *
     * Le jeu tourne sur MariaDB, mais la suite de tests utilise SQLite, qui ne connait pas
     * FIELD(). Or WreckFieldService trie ses resultats avec, sur un chemin traverse par le
     * middleware globalgame : la quasi-totalite des tests Feature echouait donc avec
     * « no such function: FIELD » avant meme d'atteindre ce qu'ils verifient.
     *
     * La fonction n'est enregistree que sur une connexion SQLite : en production, MariaDB
     * fournit la sienne et ce code ne s'execute jamais.
     *
     * @return void
     */
    private function registerSqliteCompatibilityFunctions(): void
    {
        $enregistrer = function (Connection $connection): void {
            if ($connection->getDriverName() !== 'sqlite') {
                return;
            }

            $pdo = $connection->getPdo();

            // FIELD(valeur, a, b, c) renvoie la position de valeur dans la liste, en comptant
            // a partir de 1, et 0 si elle ne s'y trouve pas. MariaDB compare en chaines des
            // lors qu'un des arguments en est une, ce qui est toujours le cas ici.
            $pdo->sqliteCreateFunction('FIELD', function (mixed ...$arguments): int {
                $valeur = array_shift($arguments);

                foreach (array_values($arguments) as $rang => $candidat) {
                    if ((string) $valeur === (string) $candidat) {
                        return $rang + 1;
                    }
                }

                return 0;
            }, -1);

            // Fonctions mathematiques : SQLite ne les fournit que si le module math a ete
            // compile, ce qui n'est pas le cas du binaire livre avec PHP sous Windows.
            $pdo->sqliteCreateFunction('FLOOR', fn (float|int|null $v): float => floor((float) $v), 1);
            $pdo->sqliteCreateFunction('CEIL', fn (float|int|null $v): float => ceil((float) $v), 1);
            $pdo->sqliteCreateFunction('CEILING', fn (float|int|null $v): float => ceil((float) $v), 1);
            $pdo->sqliteCreateFunction('SQRT', fn (float|int|null $v): float => sqrt((float) $v), 1);
            $pdo->sqliteCreateFunction('POW', fn (float|int|null $b, float|int|null $e): float => pow((float) $b, (float) $e), 2);
            $pdo->sqliteCreateFunction('POWER', fn (float|int|null $b, float|int|null $e): float => pow((float) $b, (float) $e), 2);
        };

        // La connexion courante, puis toute connexion ouverte plus tard : les fonctions
        // SQLite sont attachees a une connexion et disparaissent si elle est renouvelee.
        $enregistrer(DB::connection());
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event) use ($enregistrer): void {
            $enregistrer($event->connection);
        });
    }

    /**
     * Skip the test when the Rust battle engine cannot possibly run here.
     *
     * Les classes de test propres au moteur Rust instancient RustBattleEngine directement :
     * elles contournent le reglage bascule par usePhpBattleEngineWhenRustIsUnavailable() et
     * levaient « Class FFI not found » — 30 erreurs sur un poste sans FFI ni bibliotheque
     * compilee, ce qui rendait le bilan de la suite illisible.
     *
     * Une erreur dit « quelque chose est casse » ; un test ignore dit « cet environnement ne
     * peut pas repondre ». Ici c'est la seconde phrase qui est vraie : la bibliotheque est
     * compilee au demarrage du conteneur Docker et n'est pas versionnee.
     *
     * La garde reste conditionnelle : la ou la bibliotheque existe — integration continue,
     * serveur — ces tests s'executent normalement.
     *
     * Le chemin est calcule sans base_path() : la garde s'execute avant parent::setUp(), donc
     * avant que l'application ne soit disponible.
     *
     * @param string $library
     * @return void
     */
    protected function skipWhenTheRustLibraryIsUnavailable(string $library = 'libbattle_engine_ffi.so'): void
    {
        if (!extension_loaded('FFI')) {
            $this->markTestSkipped('The FFI extension is not enabled, so the Rust battle engine cannot be loaded here.');
        }

        if (!file_exists(dirname(__DIR__) . '/storage/rust-libs/' . $library)) {
            $this->markTestSkipped('The compiled Rust library ' . $library . ' is absent; it is built when the Docker container starts and is not versioned.');
        }
    }

    /**
     * Fall back to the PHP battle engine when the Rust library is not available.
     *
     * La bibliotheque Rust est compilee au demarrage du conteneur Docker et n'est pas versionnee.
     * Sur un poste de developpement sans elle — un poste Windows par exemple, ou le binaire .so
     * ne peut de toute facon pas se charger — les combats levaient « Class FFI not found ».
     *
     * La bascule est conditionnelle : la ou la bibliotheque existe, notamment en integration
     * continue, c'est bien le moteur Rust qui reste teste.
     *
     * @return void
     */
    private function usePhpBattleEngineWhenRustIsUnavailable(): void
    {
        if (file_exists(base_path('storage/rust-libs/libbattle_engine_ffi.so')) && extension_loaded('FFI')) {
            return;
        }

        if (!Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')->updateOrInsert(['key' => 'battle_engine'], ['value' => 'php']);
    }
}
