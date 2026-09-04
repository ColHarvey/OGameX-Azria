<?php

/*
 * Lit un rapport JUnit et exige ce qu'un code de sortie ne dit pas.
 *
 * ## Pourquoi ce script existe
 *
 * Le code de sortie de PHPUnit vaut zero quand tout passe — **y compris quand tout a ete ignore**.
 * Une suite entierement sautee, faute de bibliotheque chargee, sort verte. C'est exactement le cas
 * que la validation du moteur Rust doit refuser : le seul interet du travail est que ces essais-la
 * tournent.
 *
 * Chercher le mot « skipped » dans la sortie texte ne vaut pas mieux : le mot apparait dans une
 * option, dans un nom d'essai, dans un message. Ce script lit la structure.
 *
 * ## Usage
 *
 *     php scripts/verifier-junit.php <fichier.xml> --minimum=30 --sans-ignores
 *
 * Il ecrit un resume lisible, puis sort en erreur si une exigence n'est pas tenue.
 */

$fichier = $argv[1] ?? '';
$minimum = 0;
$sansIgnores = false;

foreach (array_slice($argv, 2) as $argument) {
    if ($argument === '--sans-ignores') {
        $sansIgnores = true;

        continue;
    }

    if (str_starts_with($argument, '--minimum=')) {
        $minimum = max(0, (int)substr($argument, strlen('--minimum=')));
    }
}

if ($fichier === '' || !is_file($fichier)) {
    fwrite(STDERR, "Aucun rapport JUnit a lire : « {$fichier} » n existe pas.\n");
    exit(1);
}

$contenu = file_get_contents($fichier);

if ($contenu === false || trim($contenu) === '') {
    fwrite(STDERR, "Le rapport JUnit « {$fichier} » est vide : aucun essai n a ete enregistre.\n");
    exit(1);
}

$xml = @simplexml_load_string($contenu);

if ($xml === false) {
    fwrite(STDERR, "Le rapport JUnit « {$fichier} » ne se lit pas comme du XML.\n");
    exit(1);
}

$essais = 0;
$echecs = 0;
$erreurs = 0;
$ignores = 0;

// **Les suites s'imbriquent, et seules les feuilles portent des cas.** Additionner tous les
// attributs `tests` compterait chaque essai autant de fois qu'il a d'ancetres.
$parcourir = static function (SimpleXMLElement $noeud) use (&$parcourir, &$essais, &$echecs, &$erreurs, &$ignores): void {
    foreach ($noeud->children() as $nom => $enfant) {
        if ($nom === 'testsuite') {
            $parcourir($enfant);

            continue;
        }

        if ($nom !== 'testcase') {
            continue;
        }

        $essais++;

        foreach ($enfant->children() as $genre => $detail) {
            unset($detail);

            if ($genre === 'failure') {
                $echecs++;
            }

            if ($genre === 'error') {
                $erreurs++;
            }

            if ($genre === 'skipped') {
                $ignores++;
            }
        }
    }
};

$parcourir($xml);

echo 'essais=' . $essais . ' echecs=' . $echecs . ' erreurs=' . $erreurs . ' ignores=' . $ignores . "\n";

$refus = [];

if ($echecs > 0 || $erreurs > 0) {
    $refus[] = $echecs . ' echec(s) et ' . $erreurs . ' erreur(s)';
}

if ($minimum > 0 && $essais < $minimum) {
    $refus[] = $essais . ' essai(s) enregistre(s) pour ' . $minimum . ' attendu(s) : la selection n a pas trouve ce qu elle devait executer';
}

if ($sansIgnores && $ignores > 0) {
    $refus[] = $ignores . ' essai(s) ignore(s) : la bibliotheque n a pas ete chargee, et une suite entierement sautee sort verte';
}

if ($refus !== []) {
    fwrite(STDERR, "Le rapport JUnit refuse : " . implode(' ; ', $refus) . ".\n");
    exit(1);
}

echo "Le rapport JUnit tient les exigences.\n";
