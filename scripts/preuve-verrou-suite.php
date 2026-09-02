<?php

/*
 * La preuve deterministe que deux passages de la suite ne se marchent jamais dessus.
 *
 * ## Pourquoi une preuve, et pas un essai
 *
 * `scripts/suite.php` place `migrate:fresh` et les tests sous le meme verrou. Une premiere
 * verification s'etait contentee de lancer deux fois la suite « en meme temps » : les deux
 * passages avaient reussi, la ligne « Attente de sa fin » n'etait apparue nulle part, et rien
 * n'avait ete demontre. Deux processus lances a la suite ne se chevauchent pas parce qu'on
 * l'espere — le premier peut tres bien avoir fini avant que le second ne commence.
 *
 * Ce script provoque donc le chevauchement au lieu de l'esperer. Le premier passage est le vrai
 * lanceur, sur un essai qui **bloque volontairement** jusqu'a un signal de ce script. Tant que ce
 * signal n'est pas donne, le premier detient le verrou, de facon certaine et observable.
 *
 * ## Ce qui est demontre, et par quels temoins
 *
 * 1. le second annonce qu'il attend (« Attente de sa fin ») ;
 * 2. il **n'entre pas** dans `migrate:fresh` — temoin direct : la ligne de commande n'apparait
 *    pas dans sa sortie ;
 * 3. il ne vide pas la base — temoin **independant du journal** : une table sentinelle, creee
 *    apres le `migrate:fresh` du premier, est toujours la ;
 * 4. une fois le premier libere, le second obtient le verrou, execute enfin son `migrate:fresh`
 *    — la sentinelle disparait — et se termine normalement.
 *
 * Le troisieme temoin est le plus important : un journal peut mentir par bufferisation, la base
 * non. C'est exactement le defaut d'origine — le 2 septembre 2026, un `migrate:fresh` concurrent
 * a corrompu le fichier SQLite et fait perdre deux passages complets.
 *
 * ## Precautions
 *
 * Le script ne touche qu'a la base de test. L'essai bloquant porte sa propre echeance : meme si
 * ce script est interrompu, le premier passage se debloque seul au lieu de tenir le verrou
 * indefiniment.
 *
 * Usage :
 *
 *     php scripts/preuve-verrou-suite.php
 */

$racine = dirname(__DIR__);
$travail = $racine . '/storage/framework/testing/preuve-verrou';
$base = $racine . '/database/database.sqlite';

$echecs = 0;

/**
 * Affiche le resultat d'un temoin et retient les echecs.
 */
$temoin = static function (bool $obtenu, string $enonce, string $detail = '') use (&$echecs): void {
    if (!$obtenu) {
        $echecs++;
    }

    fwrite(STDOUT, ($obtenu ? '  [ok]     ' : '  [ECHEC]  ') . $enonce . "\n");

    if ($detail !== '') {
        fwrite(STDOUT, '            ' . $detail . "\n");
    }
};

$arret = static function (string $raison): never {
    fwrite(STDERR, "\n  " . $raison . "\n\n");

    exit(1);
};

if (!is_dir($travail) && !mkdir($travail, 0777, true)) {
    $arret('Le dossier de travail n a pas pu etre cree : ' . $travail);
}

foreach (glob($travail . '/*') ?: [] as $reste) {
    unlink($reste);
}

// ---------------------------------------------------------------------------------------------
// Les deux essais, ecrits a la volee : le depot n'a pas a porter un test qui bloque.
// ---------------------------------------------------------------------------------------------

$signal = str_replace('\\', '/', $travail);

$barriere = <<<'CODE'
<?php

use PHPUnit\Framework\TestCase;

/**
 * L'essai qui tient le verrou du premier passage jusqu'a ce que la preuve le libere.
 *
 * Il porte sa propre echeance : si la preuve est interrompue, le verrou finit par se relacher
 * seul au lieu de bloquer la machine.
 */
final class BarriereDuPremierTest extends TestCase
{
    public function testLePremierPassageTientLeVerrouJusquAuSignal(): void
    {
        $dossier = '@@DOSSIER@@';

        touch($dossier . '/premier-dans-phpunit');

        $echeance = time() + 300;

        while (!file_exists($dossier . '/liberer') && time() < $echeance) {
            usleep(100_000);
        }

        $this->assertFileExists($dossier . '/liberer', 'La barriere a expire au lieu d etre liberee.');
    }
}

CODE;

file_put_contents($travail . '/BarriereDuPremierTest.php', str_replace('@@DOSSIER@@', $signal, $barriere));

$court = <<<'CODE'
<?php

use PHPUnit\Framework\TestCase;

/**
 * L'essai du second passage : il n'a rien a prouver lui-meme, seulement a se terminer.
 */
final class PassageCourtTest extends TestCase
{
    public function testLeSecondPassageVaJusquAuBout(): void
    {
        $this->assertTrue(true);
    }
}

CODE;

file_put_contents($travail . '/PassageCourtTest.php', $court);

// ---------------------------------------------------------------------------------------------
// Outils : lancement d'un passage, attente d'une condition, sentinelle en base.
// ---------------------------------------------------------------------------------------------

/**
 * Lance un passage complet de la suite, sortie redirigee dans un journal.
 *
 * @return array{0: resource, 1: string, 2: string}
 */
$lancer = static function (string $test, string $nom) use ($racine, $travail, $arret): array {
    $journal = $travail . '/' . $nom . '.log';
    $erreurs = $travail . '/' . $nom . '.err';

    $processus = proc_open(
        PHP_BINARY . ' scripts/suite.php ' . $test,
        [1 => ['file', $journal, 'w'], 2 => ['file', $erreurs, 'w']],
        $tuyaux,
        $racine
    );

    if (!is_resource($processus)) {
        $arret('Le passage « ' . $nom . ' » n a pas demarre.');
    }

    return [$processus, $journal, $erreurs];
};

/**
 * Attend qu'une condition devienne vraie, ou rend faux a l'expiration.
 */
$attendre = static function (callable $condition, int $secondes): bool {
    $echeance = microtime(true) + $secondes;

    while (microtime(true) < $echeance) {
        if ($condition()) {
            return true;
        }

        usleep(200_000);
    }

    return false;
};

/**
 * Le contenu d'un journal, meme absent.
 */
$journalDe = static function (string $chemin): string {
    $contenu = @file_get_contents($chemin);

    return $contenu === false ? '' : $contenu;
};

/**
 * Si la table sentinelle existe dans la base de test.
 */
$sentinelleExiste = static function () use ($base): bool {
    $pdo = new PDO('sqlite:' . $base, null, null, [PDO::ATTR_TIMEOUT => 15]);

    $requete = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'preuve_chevauchement'");

    return $requete !== false && $requete->fetchColumn() !== false;
};

// ---------------------------------------------------------------------------------------------
// La preuve.
// ---------------------------------------------------------------------------------------------

fwrite(STDOUT, "\n  Preuve deterministe du verrou de la suite\n");
fwrite(STDOUT, '  ' . str_repeat('-', 62) . "\n\n");

fwrite(STDOUT, "  1. Le premier passage demarre et va se bloquer sur la barriere.\n");

[$premier, $journalPremier, $erreursPremier] = $lancer('storage/framework/testing/preuve-verrou/BarriereDuPremierTest.php', 'premier');

$dansPhpunit = $attendre(static fn (): bool => file_exists($travail . '/premier-dans-phpunit'), 900);

$temoin(
    $dansPhpunit,
    'Le premier passage a franchi migrate:fresh et bloque dans PHPUnit, verrou en main.',
    $dansPhpunit ? '' : 'Journal : ' . $journalPremier
);

if (!$dansPhpunit) {
    touch($travail . '/liberer');
    proc_close($premier);

    $arret('Le premier passage n a jamais atteint la barriere : la preuve ne peut pas etre menee.');
}

// La sentinelle est posee **apres** le migrate:fresh du premier. Elle ne peut donc disparaitre
// que par un second migrate:fresh — celui qu'on veut voir ne pas arriver.
$pdo = new PDO('sqlite:' . $base, null, null, [PDO::ATTR_TIMEOUT => 15]);
$pdo->exec('CREATE TABLE IF NOT EXISTS preuve_chevauchement (id INTEGER)');
unset($pdo);

$temoin($sentinelleExiste(), 'La table sentinelle est posee dans la base de test.');

fwrite(STDOUT, "\n  2. Le second passage demarre pendant que le premier tient le verrou.\n");

[$second, $journalSecond, $erreursSecond] = $lancer('storage/framework/testing/preuve-verrou/PassageCourtTest.php', 'second');

$annonce = $attendre(
    static fn (): bool => str_contains($journalDe($journalSecond), 'Attente de sa fin'),
    120
);

$temoin($annonce, 'Le second passage annonce qu il attend le verrou.', $annonce ? '' : 'Journal : ' . $journalSecond);

// On laisse volontairement passer du temps : si le verrou ne tenait pas, le second aurait
// largement de quoi lancer sa remise a zero.
sleep(15);

$sortieSecond = $journalDe($journalSecond) . $journalDe($erreursSecond);

$temoin(
    !str_contains($sortieSecond, 'migrate:fresh'),
    'Le second passage n est pas entre dans migrate:fresh pendant l attente.'
);

$temoin(
    $sentinelleExiste(),
    'La base de test est intacte : la sentinelle est toujours la (temoin independant du journal).'
);

$temoin(
    proc_get_status($second)['running'] === true,
    'Le second passage est bien vivant et bloque, et non termine en erreur.'
);

fwrite(STDOUT, "\n  3. Le premier est libere.\n");

touch($travail . '/liberer');

$codePremier = proc_close($premier);

$temoin($codePremier === 0, 'Le premier passage se termine normalement.', 'code de sortie : ' . $codePremier);

$reprise = $attendre(
    static fn (): bool => str_contains($journalDe($journalSecond), 'Verrou obtenu'),
    180
);

$temoin($reprise, 'Le second passage obtient le verrou des que le premier le relache.');

$codeSecond = proc_close($second);

$temoin($codeSecond === 0, 'Le second passage se termine normalement.', 'code de sortie : ' . $codeSecond);

$temoin(
    str_contains($journalDe($journalSecond), 'migrate:fresh'),
    'Le second passage a bien execute sa remise a zero, mais seulement apres.'
);

$temoin(
    !$sentinelleExiste(),
    'La sentinelle a disparu : c est bien le migrate:fresh du second, et il a eu lieu apres la liberation.'
);

// ---------------------------------------------------------------------------------------------

fwrite(STDOUT, "\n  " . str_repeat('-', 62) . "\n");

if ($echecs === 0) {
    fwrite(STDOUT, "  Preuve etablie : aucun chevauchement possible.\n\n");
    fwrite(STDOUT, '  Journaux conserves dans ' . $travail . "\n\n");

    exit(0);
}

fwrite(STDOUT, '  ' . $echecs . " temoin(s) en echec. Journaux dans " . $travail . "\n\n");

exit(1);
