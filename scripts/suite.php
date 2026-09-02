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
 * Usage :
 *
 *     php scripts/suite.php                      la suite complete
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

$code = $executer([$php, 'artisan', 'migrate:fresh', '--force', '--env=testing']);

if ($code !== 0) {
    flock($verrou, LOCK_UN);

    $arret('La remise a zero de la base a echoue : les tests ne sont pas lances.');
}

$arguments = array_slice($argv, 1);

$commande = [$php, '-d', 'memory_limit=2G', 'vendor/bin/phpunit', '--no-coverage', ...$arguments];

$code = $executer($commande);

flock($verrou, LOCK_UN);
fclose($verrou);

exit($code);
