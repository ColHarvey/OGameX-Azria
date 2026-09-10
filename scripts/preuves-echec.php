<?php

/**
 * Prepare les bases du banc a partir en artefact, apres un echec de la suite.
 *
 * ------------------------------------------------------------------------------------
 * POURQUOI CE SCRIPT EXISTE
 *
 * Quand un essai rougit parce qu'un voisin lui a laisse un etat, le rapport dit **lequel** a
 * rougi, jamais **quel etat**. Les bases du banc portent cette reponse, et elles meurent avec
 * le coureur. Les conserver est la seule facon de rendre un rouge intermittent diagnosticable.
 *
 * ------------------------------------------------------------------------------------
 * CE QU'IL RETIRE, ET POURQUOI PAS UN CONTROLE
 *
 * Exclure le `.env` d'un artefact ne dit rien du contenu des bases. Elles ne portent que des
 * comptes generes, mais trois endroits n'ont rien a faire dans un fichier telecharge :
 * `users.password`, les secrets a deux facteurs, et les jetons de reinitialisation.
 *
 * Les **inspecter** pour se rassurer serait fragile : il suffirait qu'une migration en ajoute
 * un quatrieme, et personne ne le verrait. Les copies sont donc **videes** de ces colonnes.
 * Aucune n'est utile a un diagnostic d'ordre d'execution : ce qu'on cherche est l'etat de jeu
 * que les voisins ont laisse, jamais l'authentification.
 *
 * Les sessions partent aussi : elles portent des identifiants de connexion vivants, et le
 * symptome deja rencontre — une page de jeu rendue a la place du formulaire — vient justement
 * d'une session survivante. Les garder serait utile au diagnostic ; les publier ne l'est pas.
 *
 * ------------------------------------------------------------------------------------
 * LA PROVENANCE EST ETABLIE, PAS SUPPOSEE
 *
 * Une base plus ancienne que le debut du passage n'a pas ete creee par lui : elle n'est pas
 * copiee, et le script le dit. Sur un coureur neuf le cas ne se presente pas ; sur un poste de
 * developpement, il empeche une base oubliee de partir.
 */
$racine = dirname(__DIR__);
$depuis = isset($argv[1]) ? (int)$argv[1] : 0;
$destination = $racine . '/storage/logs/preuves';

if (!is_dir($destination) && !mkdir($destination, 0o755, true) && !is_dir($destination)) {
    fwrite(STDERR, "Le dossier des preuves n a pas pu etre cree.\n");
    exit(1);
}

/**
 * Ce qui est vide sur chaque copie. La table est ignoree si elle n existe pas, la colonne aussi :
 * ce script tourne sur des schemas de plusieurs ages, et doit rester muet sur ce qu il ne trouve pas.
 *
 * @var array<string, array<string, string>>
 */
$aVider = [
    'users' => [
        'password' => "''",
        'two_factor_secret' => 'NULL',
        'two_factor_recovery_codes' => 'NULL',
        'remember_token' => 'NULL',
    ],
];

/** @var array<int, string> */
$aSupprimer = ['password_reset_tokens', 'sessions'];

$copiees = 0;

foreach (glob($racine . '/database/database*.sqlite') ?: [] as $base) {
    if ($depuis > 0 && filemtime($base) < $depuis) {
        echo 'ignoree, anterieure au passage : ' . basename($base) . "\n";

        continue;
    }

    $copie = $destination . '/' . basename($base);

    if (!copy($base, $copie)) {
        fwrite(STDERR, 'copie impossible : ' . basename($base) . "\n");
        exit(1);
    }

    $pdo = new PDO('sqlite:' . $copie);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($aVider as $table => $colonnes) {
        if (!in_array($table, $tables, true)) {
            continue;
        }

        $existantes = array_column($pdo->query('PRAGMA table_info("' . $table . '")')->fetchAll(PDO::FETCH_ASSOC), 'name');

        foreach ($colonnes as $colonne => $valeur) {
            if (in_array($colonne, $existantes, true)) {
                $pdo->exec('UPDATE "' . $table . '" SET "' . $colonne . '" = ' . $valeur);
            }
        }
    }

    foreach ($aSupprimer as $table) {
        if (in_array($table, $tables, true)) {
            $pdo->exec('DELETE FROM "' . $table . '"');
        }
    }

    // **Le controle qui suit le nettoyage, sur la copie.** Ecrire « c est vide » sans le relire
    // laisserait passer une colonne renommee : le script echouerait alors en silence, et
    // l artefact partirait avec ce qu il pretend avoir retire.
    $restants = 0;

    if (in_array('users', $tables, true)) {
        $existantes = array_column($pdo->query('PRAGMA table_info("users")')->fetchAll(PDO::FETCH_ASSOC), 'name');

        if (in_array('password', $existantes, true)) {
            $restants += (int)$pdo->query("SELECT COUNT(*) FROM users WHERE password IS NOT NULL AND password <> ''")->fetchColumn();
        }
    }

    if ($restants > 0) {
        fwrite(STDERR, 'Le nettoyage a laisse ' . $restants . " empreintes de mot de passe dans " . basename($copie) . ".\n");
        exit(1);
    }

    echo 'nettoyee : ' . basename($copie) . "\n";
    $copiees++;
}

echo $copiees . " base(s) prete(s) a partir.\n";
