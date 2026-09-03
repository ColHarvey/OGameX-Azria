<?php

/*
 * Amorcage de la suite de tests : une base par processus, et le verrou d'execution.
 *
 * La suite travaille sur un unique fichier SQLite. Deux executions simultanees le partagent :
 * l'une vide la base pendant que l'autre insere, et les erreurs qui en resultent — « database
 * is locked », « UNIQUE constraint failed: users.id » — ressemblent trait pour trait a des
 * regressions.
 *
 * Ce piege a coute du temps trois fois dans la meme journee, dont une fois ou il a fait
 * conclure qu'une migration nouvelle cassait une migration de deux ans plus tot. Un message
 * clair au demarrage vaut mieux qu'un diagnostic a refaire.
 *
 * Le verrou est pose sur un fichier, avec `flock` : il tombe tout seul si le processus meurt,
 * ce qu'un verrou pose en base ne ferait pas.
 */

require __DIR__ . '/../vendor/autoload.php';

// ---------------------------------------------------------------------------------------------
// Une base par processus.
// ---------------------------------------------------------------------------------------------
//
// **C'est ce qui rend la parallelisation possible.** La consigne « ne jamais paralleliser » etait
// juste, et sa raison etait precise : huit processus sur un seul fichier SQLite produisent des
// « database is locked » et des « UNIQUE constraint failed » qui ressemblent a des regressions.
// Ce n'est pas la parallelisation qui casse, c'est la base partagee.
//
// ParaTest donne a chaque processus un `TEST_TOKEN`. Chacun recoit donc son fichier, et les
// collisions disparaissent au lieu d'etre subies.
//
// **Avant tout le reste** : `config/database.php` lit `env('DB_DATABASE', ...)`, et Dotenv ne
// remplace pas une variable deja posee. Poser celle-ci plus tard n'aurait aucun effet.
$jeton = getenv('TEST_TOKEN');

if (is_string($jeton) && $jeton !== '' && $jeton !== '1') {
    $baseDuProcessus = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR
        . 'database' . DIRECTORY_SEPARATOR . 'database_test_' . $jeton . '.sqlite';

    putenv('DB_DATABASE=' . $baseDuProcessus);
    $_ENV['DB_DATABASE'] = $baseDuProcessus;
    $_SERVER['DB_DATABASE'] = $baseDuProcessus;
}

// **Reentrance explicite.** `scripts/suite.php` pose le meme verrou avant `migrate:fresh`, donc
// avant que PHPUnit ne demarre. S'il le detient deja, le reprendre ici bloquerait le lanceur
// contre lui-meme. Le marqueur dit ce que la seule presence du fichier ne peut pas dire :
// qui tient le verrou.
if (getenv('OGAMEX_SUITE_LOCK_HELD') === '1') {
    return;
}

$fichierVerrou = __DIR__ . '/../storage/framework/testing/suite.lock';

if (!is_dir(dirname($fichierVerrou))) {
    mkdir(dirname($fichierVerrou), 0777, true);
}

$verrou = fopen($fichierVerrou, 'c');

if ($verrou === false) {
    fwrite(STDERR, "Impossible d'ouvrir le verrou de la suite de tests : " . $fichierVerrou . "\n");

    exit(1);
}

if (!flock($verrou, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "\n");
    fwrite(STDERR, "  Une autre execution de la suite tient deja le verrou.\n");
    fwrite(STDERR, "\n");
    fwrite(STDERR, "  Les deux partageraient le meme fichier SQLite : l'une viderait la base pendant\n");
    fwrite(STDERR, "  que l'autre insere. Les erreurs qui en resultent ressemblent a des regressions\n");
    fwrite(STDERR, "  sans en etre — « database is locked », « UNIQUE constraint failed ».\n");
    fwrite(STDERR, "\n");
    fwrite(STDERR, "  Attendez la fin de la premiere, ou arretez-la.\n");
    fwrite(STDERR, "\n");

    exit(1);
}

// Le verrou vit aussi longtemps que le processus. La ressource est conservee dans une variable
// globale : sans cela, PHP la fermerait a la fin de ce fichier et le verrou tomberait aussitot.
$GLOBALS['ogamex_verrou_suite'] = $verrou;

register_shutdown_function(static function () use ($verrou): void {
    // **Le fichier n'est pas supprime.** Un `unlink` liberait le verrou tout en laissant un
    // autre processus ouvrir un fichier neuf du meme nom et l'obtenir aussitot : deux passages
    // se croiraient seuls. Le fichier vide qui reste ne coute rien.
    flock($verrou, LOCK_UN);
    fclose($verrou);
});
