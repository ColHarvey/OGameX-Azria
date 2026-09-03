<?php

/*
 * Le lanceur unique des passages complets de la suite.
 *
 * ## Le defaut qu'il corrige
 *
 * Le verrou vivait dans `tests/bootstrap.php`, donc **a l'interieur** de PHPUnit. Or la remise a
 * zero de la base se fait avant, par `php artisan migrate:fresh`. Enchainer
 *
 *     php artisan migrate:fresh && vendor/bin/phpunit
 *
 * pendant qu'un autre passage tourne vidait donc la base sous ses pieds, **sans jamais rencontrer
 * le verrou**. Le 2 septembre 2026 cela a corrompu le fichier SQLite — « database disk image is
 * malformed » — et fait perdre deux passages complets.
 *
 * Le verrou couvre desormais l'operation entiere :
 *
 *     verrou -> environnement -> chemin de base -> migrate:fresh -> outils et tests -> liberation
 *
 * ## Le verrou imbrique
 *
 * `tests/bootstrap.php` pose le meme verrou. Si ce lanceur le detient deja, PHPUnit se bloquerait
 * lui-meme. Le lanceur pose donc un marqueur d'environnement que le bootstrap reconnait — une
 * reentrance **explicite**, pas un verrou partage dont on espererait qu'il se comporte bien.
 *
 * ## Ce qu'il refuse de faire
 *
 * Il ne lance `migrate:fresh` que si l'environnement est `testing`, si la base resolue est bien
 * celle des tests, et si son chemin n'est pas ambigu. Un `migrate:fresh` sur autre chose que la
 * base de test detruirait des donnees.
 *
 * ## Une base par processus
 *
 * La consigne etait « ne jamais paralleliser ». Elle etait juste, et sa raison etait precise : huit
 * processus sur **un seul fichier SQLite** produisent des « database is locked » et des « UNIQUE
 * constraint failed » qui ressemblent trait pour trait a des regressions.
 *
 * Ce n'est pas la parallelisation qui casse, c'est la base partagee. ParaTest donne un `TEST_TOKEN`
 * a chaque processus, `tests/bootstrap.php` lui donne sa base, et ce lanceur les remet toutes a zero
 * avant le depart.
 *
 * La parallelisation reste **interne a un passage** : le verrou est pris avant tout, et deux suites
 * concurrentes demeurent aussi dangereuses qu'avant.
 *
 * Usage :
 *
 *     php scripts/suite.php                      la suite complete, sur tous les coeurs
 *     php scripts/suite.php --sequentiel         un seul processus, l'ancien chemin
 *     php scripts/suite.php --officiel           `php artisan test`, la commande d'AGENTS.md
 *     php scripts/suite.php --processus=4        un nombre choisi
 *     php scripts/suite.php tests/Unit/Combat/   une partie, avec la meme securite
 */

$racine = dirname(__DIR__);

require $racine . '/vendor/autoload.php';

/**
 * Arrete le lanceur avec un message lisible.
 */
$arret = static function (string $raison): never {
    fwrite(STDERR, "\n  " . $raison . "\n\n");

    exit(1);
};

// ---------------------------------------------------------------------------------------------
// 1. Le verrou, avant tout le reste.
// ---------------------------------------------------------------------------------------------

$fichierVerrou = $racine . '/storage/framework/testing/suite.lock';

if (!is_dir(dirname($fichierVerrou))) {
    mkdir(dirname($fichierVerrou), 0777, true);
}

$verrou = fopen($fichierVerrou, 'c');

if ($verrou === false) {
    $arret('Impossible d ouvrir le verrou de la suite : ' . $fichierVerrou);
}

// **Le second attend, il ne refuse pas.** Un refus obligerait a relancer a la main, et laisserait la
// tentation de contourner le verrou en appelant `migrate:fresh` soi-meme — exactement ce qui a
// corrompu le fichier SQLite. L attente est bloquante, mais annoncee : on sait pourquoi rien ne se
// passe, et la seconde execution ne videra jamais la base pendant que la premiere s en sert.
if (!flock($verrou, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, "\n  Une autre execution de la suite tient le verrou. Attente de sa fin...\n");

    if (!flock($verrou, LOCK_EX)) {
        $arret('Impossible d obtenir le verrou de la suite.');
    }

    fwrite(STDOUT, "  Verrou obtenu : la suite demarre.\n");
}

// ---------------------------------------------------------------------------------------------
// 2. L'environnement, et la base que l'on s'apprete a vider.
// ---------------------------------------------------------------------------------------------

$configuration = @simplexml_load_file($racine . '/phpunit.xml');

if ($configuration === false) {
    $arret('phpunit.xml est illisible : impossible de verifier l environnement de test.');
}

$environnement = null;

foreach ($configuration->php->env ?? [] as $variable) {
    if ((string)$variable['name'] === 'APP_ENV') {
        $environnement = (string)$variable['value'];
    }
}

if ($environnement !== 'testing') {
    $arret(
        'phpunit.xml declare APP_ENV = « ' . (string)$environnement . " » au lieu de « testing ».\n\n"
        . "  Un migrate:fresh dans un autre environnement detruirait des donnees qui ne sont pas des\n"
        . "  donnees de test."
    );
}

$chemin = getenv('DB_DATABASE');

if (!is_string($chemin) || $chemin === '') {
    $chemin = $racine . '/database/database.sqlite';
}

$reel = realpath($chemin);

if ($reel === false) {
    // Le fichier peut ne pas exister encore : on resout alors son dossier.
    $dossier = realpath(dirname($chemin));

    if ($dossier === false) {
        $arret('Le chemin de la base de test ne se resout pas : ' . $chemin);
    }

    $reel = $dossier . DIRECTORY_SEPARATOR . basename($chemin);
}

$attendu = $racine . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'database.sqlite';

if (str_replace('\\', '/', $reel) !== str_replace('\\', '/', $attendu)) {
    $arret(
        "La base resolue n est pas celle des tests.\n\n"
        . '  resolue : ' . $reel . "\n"
        . '  attendue : ' . $attendu . "\n\n"
        . "  Le lanceur refuse de vider une base qu il ne reconnait pas."
    );
}

// ---------------------------------------------------------------------------------------------
// 3. La remise a zero, puis les tests, sous le meme verrou.
// ---------------------------------------------------------------------------------------------

/**
 * Execute une commande et rend son code de sortie, en laissant sa sortie passer.
 */
$executer = static function (array $commande) use ($racine): int {
    $ligne = implode(' ', array_map(static fn (string $part): string => str_contains($part, ' ') ? '"' . $part . '"' : $part, $commande));

    fwrite(STDOUT, "\n  > " . $ligne . "\n");

    $processus = proc_open($ligne, [1 => STDOUT, 2 => STDERR], $tuyaux, $racine, [
        // **Le marqueur de reentrance.** `tests/bootstrap.php` le reconnait et ne reprend pas le
        // verrou que ce lanceur detient deja.
        'OGAMEX_SUITE_LOCK_HELD' => '1',
    ] + getenv());

    if (!is_resource($processus)) {
        return 1;
    }

    return proc_close($processus);
};

$php = PHP_BINARY;

// ---------------------------------------------------------------------------------------------
// 4. Combien de processus, et sur quoi.
// ---------------------------------------------------------------------------------------------

$arguments = [];
$sequentiel = false;
$officiel = false;
$processus = null;

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--sequentiel') {
        $sequentiel = true;

        continue;
    }

    if ($argument === '--officiel') {
        $officiel = true;
        $sequentiel = true;

        continue;
    }

    if (str_starts_with($argument, '--processus=')) {
        $processus = max(1, (int)substr($argument, strlen('--processus=')));

        continue;
    }

    $arguments[] = $argument;
}

/**
 * Le nombre de coeurs logiques, ou une valeur prudente.
 */
$coeurs = static function (): int {
    $annonce = getenv('NUMBER_OF_PROCESSORS');

    if (is_string($annonce) && (int)$annonce > 0) {
        return (int)$annonce;
    }

    return 4;
};

// **Les coeurs logiques, sans reserve.** Les processus attendent le disque autant qu'ils calculent :
// en laisser un de cote ne rendrait pas la machine plus reactive, et couterait un huitieme du temps.
$processus ??= $coeurs();

if ($sequentiel) {
    $processus = 1;
}

fwrite(STDOUT, "\n  Processus : " . $processus . ($sequentiel ? ' (sequentiel demande)' : '') . "\n");

// ---------------------------------------------------------------------------------------------
// 5. Une base remise a zero par processus.
// ---------------------------------------------------------------------------------------------

$racineBase = $racine . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR;

/**
 * Le fichier de base d'un processus. Le premier garde la base du depot.
 */
$baseDuJeton = static function (int $jeton) use ($racineBase): string {
    return $jeton === 1
        ? $racineBase . 'database.sqlite'
        : $racineBase . 'database_test_' . $jeton . '.sqlite';
};

$migrations = [];

for ($jeton = 1; $jeton <= $processus; $jeton++) {
    $fichier = $baseDuJeton($jeton);

    if (!file_exists($fichier)) {
        touch($fichier);
    }

    // Les remises a zero partent ensemble : huit fois deux secondes en serie couteraient plus que
    // le temps gagne sur un petit passage.
    $migrations[] = proc_open(
        '"' . $php . '" artisan migrate:fresh --force --env=testing',
        [1 => ['file', $racine . '/storage/framework/testing/migrate-' . $jeton . '.log', 'w'], 2 => ['file', $racine . '/storage/framework/testing/migrate-' . $jeton . '.log', 'a']],
        $tuyaux,
        $racine,
        ['OGAMEX_SUITE_LOCK_HELD' => '1', 'DB_DATABASE' => $fichier] + getenv()
    );
}

$code = 0;

foreach ($migrations as $migration) {
    if (!is_resource($migration)) {
        $code = 1;

        continue;
    }

    $code = proc_close($migration) === 0 ? $code : 1;
}

fwrite(STDOUT, '  > migrate:fresh sur ' . $processus . " base(s)\n");

if ($code !== 0) {
    flock($verrou, LOCK_UN);

    $arret('La remise a zero d une base a echoue : les tests ne sont pas lances.');
}

// ---------------------------------------------------------------------------------------------
// 6. Les tests.
// ---------------------------------------------------------------------------------------------

// **`--officiel` lance la commande exacte d'`AGENTS.md`, sous le meme verrou.**
//
// Elle doit passer au moins une fois avant un candidat : c'est elle que la chaine de
// contribution nomme, et un lanceur maison ne la remplace pas. Mais la lancer a la main hors du
// verrou reviendrait a poser une remise a zero concurrente — exactement la faute que ce script
// existe pour rendre impossible.
//
// Le verdict doit distinguer les deux passages : le parallele protege, et la commande officielle.
$commande = match (true) {
    $officiel => [$php, 'artisan', 'test'],
    $processus === 1 => [$php, '-d', 'memory_limit=2G', 'vendor/bin/phpunit', '--no-coverage', ...$arguments],
    default => [$php, '-d', 'memory_limit=2G', 'vendor/bin/paratest', '--processes=' . $processus, '--no-coverage', ...$arguments],
};

$code = $executer($commande);

flock($verrou, LOCK_UN);
fclose($verrou);

exit($code);
