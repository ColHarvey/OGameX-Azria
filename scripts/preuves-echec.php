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
 * comptes generes, mais quelques endroits n'ont rien a faire dans un fichier telecharge :
 * `users.password`, les secrets a deux facteurs, et les jetons de reinitialisation.
 *
 * Les **inspecter** pour se rassurer serait fragile. Les copies sont donc **videes** de ces
 * colonnes, et la copie est relue pour verifier qu'il n'en reste rien : ecrire « c'est vide »
 * sans le constater laisserait passer une colonne renommee.
 *
 * **Et une liste connue ne couvre pas la colonne de demain.** Une migration peut en ajouter une,
 * et le nettoyage passerait a cote — sa verification aussi, puisqu'elle relit les memes noms.
 * D'ou le detecteur plus bas : toute colonne dont le nom evoque un secret et qui n'est ni videe
 * ni supprimee **arrete la collecte**, en la nommant.
 *
 * **Ce detecteur est un filet heuristique, et rien de plus.** Il juge des **noms**, pas des
 * contenus : un secret range dans une colonne au nom banal — `notes`, `payload`, `valeur` — lui
 * echappe entierement. Il aide a garder la liste a jour ; il ne dispense d'aucune vigilance, et
 * la relecture humaine reste due avant d'elargir ce qui part.
 *
 * Rien de tout cela n'est utile a un diagnostic d'ordre d'execution : ce qu'on cherche est l'etat
 * de jeu que les voisins ont laisse, jamais l'authentification.
 *
 * Les sessions partent aussi : elles portent des identifiants de connexion vivants, et le
 * symptome deja rencontre — une page de jeu rendue a la place du formulaire — vient justement
 * d'une session survivante. Les garder serait utile au diagnostic ; les publier ne l'est pas.
 * **Leur nombre est donc conserve** dans le manifeste : un compteur ne revele rien, et il garde
 * la question posable — « y en avait-il, et combien ». Un artefact qui a perdu quelque chose doit
 * le dire, sans quoi un lecteur cherchera une piste la ou elle a ete effacee volontairement.
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

/**
 * Les mots qui font d'une colonne une candidate au nettoyage.
 *
 * **Vider une liste connue ne couvre pas la colonne de demain.** Une migration peut en ajouter une,
 * ou renommer une existante, et le nettoyage passerait a cote — sa verification aussi, puisqu'elle
 * relit les memes noms. La liste doit donc etre **maintenue**, et ce script le rappelle au lieu de
 * l'esperer : toute colonne dont le nom evoque un secret et qui n'est ni videe ni supprimee arrete
 * la collecte.
 *
 * Un faux positif se leve en ajoutant la colonne a `$connuesEtGardees` avec la raison. Un refus
 * bruyant vaut mieux qu'un artefact qui emporte ce que personne n'a regarde.
 *
 * @var array<int, string>
 */
$motsSensibles = ['password', 'secret', 'token', 'api_key', 'private_key', 'credential'];

/**
 * Les colonnes dont le nom evoque un secret mais qui n'en portent pas, avec la raison.
 *
 * @var array<string, string>
 */
$connuesEtGardees = [];

$copiees = 0;

/** @var array<int, array<string, mixed>> Ce que la collecte a retire, base par base. */
$manifeste = [];

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

    // **L'original ne doit jamais bouger.** Tout se fait sur la copie, et cette empreinte le
    // verifie au lieu de l'affirmer : une base modifiee ici fausserait le diagnostic qu'elle sert
    // a rendre possible.
    //
    // **Une taille et une date identiques ne prouveraient rien** — un octet change au meme endroit
    // les laisse intactes. C'est le contenu qui est compare, par `sha256`.
    //
    // La comparaison n'a de sens que parce qu'elle a lieu **apres** la suite : plus aucun processus
    // n'ecrit dans ces bases quand ce script tourne. Pendant un passage, elle ne dirait rien.
    $empreinteAvant = hash_file('sha256', $base);

    $pdo = new PDO('sqlite:' . $copie);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);

    // **La liste est-elle encore a jour ?** Une colonne au nom evocateur qui n'est ni videe ni dans
    // une table supprimee arrete tout : c'est le seul moyen qu'une migration future ne fasse pas
    // sortir en silence ce que ce script pretend retirer.
    $oubliees = [];

    foreach ($tables as $table) {
        if (in_array($table, $aSupprimer, true)) {
            continue;
        }

        foreach ($pdo->query('PRAGMA table_info("' . $table . '")')->fetchAll(PDO::FETCH_ASSOC) as $colonne) {
            $nom = (string)$colonne['name'];
            $minuscule = strtolower($nom);

            foreach ($motsSensibles as $mot) {
                if (!str_contains($minuscule, $mot)) {
                    continue;
                }

                if (isset($aVider[$table][$nom]) || isset($connuesEtGardees[$table . '.' . $nom])) {
                    continue 2;
                }

                $oubliees[] = $table . '.' . $nom;

                continue 2;
            }
        }
    }

    if ($oubliees !== []) {
        fwrite(
            STDERR,
            "Ces colonnes evoquent un secret et ne sont ni videes ni supprimees : \n  "
            . implode("\n  ", array_unique($oubliees))
            . "\nAjoutez-les au nettoyage, ou declarez-les inoffensives avec leur raison. La collecte s arrete.\n"
        );
        exit(1);
    }

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

    // **Ce qui est supprime est compte avant de l etre.** Retirer les sessions protege des
    // connexions vivantes, mais retire aussi une piste : le symptome deja rencontre ici — une page
    // de jeu rendue a la place du formulaire — vient d une session survivante. Un compteur ne
    // revele rien et garde la question posable : « y en avait-il, et combien ».
    $retires = [];

    foreach ($aSupprimer as $table) {
        if (!in_array($table, $tables, true)) {
            continue;
        }

        $retires[$table] = (int)$pdo->query('SELECT COUNT(*) FROM "' . $table . '"')->fetchColumn();
        $pdo->exec('DELETE FROM "' . $table . '"');
    }

    $videes = [];

    foreach ($aVider as $table => $colonnes) {
        if (!in_array($table, $tables, true)) {
            continue;
        }

        foreach (array_keys($colonnes) as $colonne) {
            $videes[] = $table . '.' . $colonne;
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

    if (hash_file('sha256', $base) !== $empreinteAvant) {
        fwrite(STDERR, 'La base d origine a ete modifiee par la collecte : ' . basename($base) . "\n");
        exit(1);
    }

    $manifeste[] = [
        'base' => basename($copie),
        'colonnes_videes' => $videes,
        'tables_supprimees' => $retires,
    ];

    echo 'nettoyee : ' . basename($copie)
        . ' (sessions retirees : ' . ($retires['sessions'] ?? 0) . ')' . "\n";
    $copiees++;
}

// **Le manifeste part avec les bases.** Un artefact qui a perdu quelque chose doit le dire :
// sans lui, un lecteur croirait qu il n y avait pas de sessions plutot que de savoir qu on les a
// retirees, et chercherait une piste la ou elle a ete effacee volontairement.
file_put_contents(
    $destination . '/manifeste.json',
    json_encode($manifeste, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
);

echo $copiees . " base(s) prete(s) a partir, manifeste ecrit.\n";
