<?php

/*
 * Amorcage de la suite de tests, et verrou d'execution.
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

register_shutdown_function(static function () use ($verrou, $fichierVerrou): void {
    flock($verrou, LOCK_UN);
    fclose($verrou);
    @unlink($fichierVerrou);
});
