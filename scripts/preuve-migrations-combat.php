<?php

/*
 * Les migrations de combat : appliquer, defaire, reappliquer, sans residu.
 *
 * ## Ce que ce script prouve, et ce qu'il ne prouve pas
 *
 * Il prouve que les migrations s'appliquent, se defont **entierement**, et se reappliquent en
 * laissant le schema exactement dans l'etat ou il etait. Une lecture du code ne prouve rien de tout
 * cela : un `down()` incomplet se lit tres bien.
 *
 * Il ne remplace pas l'epreuve MariaDB. SQLite accepte des identifiants d'index de n'importe quelle
 * longueur, des types approximatifs et des cles etrangeres qu'il ignore ; MariaDB refuse les trois.
 * Une migration verte ici peut casser un deploiement — c'est deja arrive sur ce depot.
 *
 * ## Comment le lancer contre MariaDB sans risque
 *
 * Par defaut, la connexion est celle des tests. Pour l'epreuve MariaDB, passer le nom d'une
 * connexion configuree :
 *
 *     php scripts/preuve-migrations-combat.php mariadb_bac_a_sable
 *
 * **Le script refuse de tourner sur la base de production.** Le nom `laravel` est rejete
 * explicitement, ainsi que toute base dont le nom ne contient ni « test » ni « sandbox » ni
 * « bac ». Ce n'est pas une precaution decorative : ce script vide la base qu'il touche.
 *
 * ## Le verrou de suite
 *
 * Il est pris avant tout. Un `migrate:fresh` concurrent a deja corrompu le fichier SQLite et fait
 * perdre deux passages complets — voir `scripts/preuve-verrou-suite.php`.
 */

$racine = dirname(__DIR__);

require $racine . '/vendor/autoload.php';

$connexion = $argv[1] ?? null;
$echecs = 0;

$arret = static function (string $raison): never {
    fwrite(STDERR, "\n  " . $raison . "\n\n");

    exit(1);
};

// ---------------------------------------------------------------------------------------------
// 1. Le verrou, avant toute chose.
// ---------------------------------------------------------------------------------------------

$fichierVerrou = $racine . '/storage/framework/testing/suite.lock';

if (!is_dir(dirname($fichierVerrou))) {
    mkdir(dirname($fichierVerrou), 0777, true);
}

$verrou = fopen($fichierVerrou, 'c');

if ($verrou === false) {
    $arret('Impossible d ouvrir le verrou de la suite : ' . $fichierVerrou);
}

if (!flock($verrou, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, "\n  Une execution de la suite tient le verrou. Attente de sa fin...\n");

    if (!flock($verrou, LOCK_EX)) {
        $arret('Impossible d obtenir le verrou de la suite.');
    }
}

// ---------------------------------------------------------------------------------------------
// 2. La base que l'on s'apprete a vider.
// ---------------------------------------------------------------------------------------------

$php = PHP_BINARY;

/**
 * Execute un bout de PHP dans un processus neuf, et rend sa sortie.
 *
 * Un processus neuf a chaque fois : Laravel met le schema en cache dans la connexion, et une
 * introspection faite dans le meme processus qu'une migration peut repondre avec des restes.
 */
$dansUnProcessusNeuf = static function (string $corps) use ($php, $racine, $connexion): string {
    $script = $racine . '/storage/framework/testing/sonde-migrations.php';

    $prologue = "<?php\nrequire " . var_export($racine . '/vendor/autoload.php', true) . ";\n"
        . '$app = require ' . var_export($racine . '/bootstrap/app.php', true) . ";\n"
        . '$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();' . "\n";

    if ($connexion !== null) {
        $prologue .= 'config()->set("database.default", ' . var_export($connexion, true) . ");\n";
    }

    file_put_contents($script, $prologue . $corps);

    $sortie = [];
    exec('"' . $php . '" "' . $script . '" 2>&1', $sortie);

    unlink($script);

    return implode("\n", $sortie);
};

$base = trim($dansUnProcessusNeuf(
    'echo (string)config("database.connections." . config("database.default") . ".database");'
));

$nom = basename(str_replace('\\', '/', $base));

fwrite(STDOUT, "\n  Base visee : " . ($base === '' ? '(inconnue)' : $base) . "\n");

// La production, toujours et en premier.
if ($nom === 'laravel') {
    $arret(
        "C est la base de production. Ce script la viderait.\n\n"
        . '  Passer le nom d une connexion de bac a sable en argument.'
    );
}

if ($connexion === null) {
    // **La meme regle que `scripts/suite.php`**, qui est eprouvee : la base par defaut doit etre
    // exactement celle des tests du depot. Une regle sur le nom aurait refuse `database.sqlite`
    // lui-meme — et une garde qui bloque le cas legitime finit contournee, ce qui est pire que pas
    // de garde du tout.
    $attendue = $racine . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'database.sqlite';

    if (str_replace('\\', '/', $base) !== str_replace('\\', '/', $attendue)) {
        $arret(
            "La base par defaut n est pas celle des tests du depot.\n\n"
            . '  resolue  : ' . $base . "\n"
            . '  attendue : ' . $attendue . "\n\n"
            . '  Le script refuse de vider une base qu il ne reconnait pas.'
        );
    }
} else {
    // Une connexion nommee vise typiquement MariaDB, ou aucun chemin ne permet de reconnaitre la
    // base : son nom doit alors dire qu elle est jetable.
    $jetable = str_contains(strtolower($nom), 'test')
        || str_contains(strtolower($nom), 'sandbox')
        || str_contains(strtolower($nom), 'bac');

    if (!$jetable) {
        $arret(
            "Le nom de cette base ne dit pas qu elle est jetable : « " . $nom . " ».\n\n"
            . '  Ce script vide la base qu il touche. Une connexion nommee doit contenir « test »,'
            . " « sandbox » ou « bac »."
        );
    }
}

// ---------------------------------------------------------------------------------------------
// 3. L'epreuve.
// ---------------------------------------------------------------------------------------------

/**
 * Les migrations de cette livraison, par leur nom d'inscription.
 *
 * **La garde ci-dessous a deja servi.** En ajoutant la photographie d'alliance, elle a refuse de
 * defaire cinq pas alors que six migrations attendaient : sans elle, le script aurait defait autre
 * chose et l aurait dit vert.
 *
 * @var array<int, string>
 */
$migrations = [
    '2026_09_02_210000_create_celestial_body_combat_barriers_table',
    '2026_09_02_210100_create_combat_effect_receipts_table',
    '2026_09_02_210200_add_frozen_facts_to_combat_instances',
    '2026_09_02_210300_create_combat_loot_reservations_table',
    '2026_09_02_210400_create_combat_outbox_table',
    '2026_09_02_210500_add_frozen_alliance_membership_to_combat_instances',
];

$nouvellesTables = [
    'celestial_body_combat_barriers',
    'combat_effect_receipts',
    'combat_snapshot_inclusions',
    'combat_loot_reservations',
    'combat_outbox',
];

$nouvellesColonnes = [
    'causal_order_version',
    'opener_identity',
    'governing_alliance_id',
    'max_fleets',
    'frozen_settings',
    'frozen_facts_fingerprint',
    'result_published',
];

/**
 * L'etat du schema, vu depuis un processus neuf.
 *
 * @return array{tables: array<string, bool>, colonnes: array<string, bool>}
 */
$schema = static function () use ($dansUnProcessusNeuf, $nouvellesTables, $nouvellesColonnes): array {
    $corps = '$tables = [];' . "\n"
        . 'foreach (' . var_export($nouvellesTables, true) . ' as $t) {'
        . ' $tables[$t] = Illuminate\Support\Facades\Schema::hasTable($t); }' . "\n"
        . '$colonnes = [];' . "\n"
        . 'foreach (' . var_export($nouvellesColonnes, true) . ' as $c) {'
        . ' $colonnes[$c] = Illuminate\Support\Facades\Schema::hasColumn("combat_instances", $c); }' . "\n"
        . 'echo json_encode(["tables" => $tables, "colonnes" => $colonnes]);';

    $json = json_decode($dansUnProcessusNeuf($corps), true);

    return is_array($json) && isset($json['tables'], $json['colonnes'])
        ? $json
        : ['tables' => [], 'colonnes' => []];
};

$artisan = static function (string $titre, array $arguments) use ($php, $racine, $connexion, &$echecs): void {
    $ligne = '"' . $php . '" artisan ' . implode(' ', $arguments);

    if ($connexion !== null) {
        $ligne .= ' --database=' . $connexion;
    }

    $sortie = [];
    $code = 0;
    exec($ligne . ' 2>&1', $sortie, $code);

    fwrite(STDOUT, '  ' . ($code === 0 ? '[ok]     ' : '[ECHEC]  ') . $titre . "\n");

    if ($code !== 0) {
        $echecs++;
        fwrite(STDOUT, '            ' . str_replace("\n", "\n            ", implode("\n", $sortie)) . "\n");
    }
};

$temoin = static function (bool $obtenu, string $enonce) use (&$echecs): void {
    if (!$obtenu) {
        $echecs++;
    }

    fwrite(STDOUT, '  ' . ($obtenu ? '[ok]     ' : '[ECHEC]  ') . $enonce . "\n");
};

chdir($racine);

fwrite(STDOUT, "\n  Les cinq migrations de combat : appliquer, defaire, reappliquer\n");
fwrite(STDOUT, '  ' . str_repeat('-', 66) . "\n\n");

$artisan('base remise a zero', ['migrate:fresh', '--force', '--env=testing']);

$apres = $schema();

$temoin(
    $apres['tables'] !== [] && !in_array(false, $apres['tables'], true),
    'Les cinq tables existent apres migrate'
);

$temoin(
    $apres['colonnes'] !== [] && !in_array(false, $apres['colonnes'], true),
    'Les colonnes de faits geles sont sur combat_instances'
);

// **Le pas n'est pas suppose, il est verifie.** `--step=5` en dur defairait autre chose des qu'une
// migration serait ajoutee au depot apres celles-ci — et le script le dirait vert.
$dernieres = json_decode($dansUnProcessusNeuf(
    'echo json_encode(Illuminate\Support\Facades\DB::table("migrations")'
    . '->orderByDesc("id")->limit(' . count($migrations) . ')->pluck("migration")->all());'
), true);

$attendues = array_reverse($migrations);

$temoin(
    is_array($dernieres) && array_values($dernieres) === $attendues,
    'Les dernieres migrations inscrites sont bien celles de cette livraison'
);

if (!is_array($dernieres) || array_values($dernieres) !== $attendues) {
    flock($verrou, LOCK_UN);

    $arret(
        "Les cinq dernieres migrations ne sont pas celles attendues : defaire cinq pas toucherait\n"
        . "  toucherait autre chose. Mettre a jour la liste dans ce script."
    );
}

$artisan(
    'migrations defaites',
    ['migrate:rollback', '--step=' . count($migrations), '--force', '--env=testing']
);

$defait = $schema();

$temoin(
    !in_array(true, $defait['tables'], true),
    'Aucune des cinq tables ne subsiste apres rollback'
);

$temoin(
    !in_array(true, $defait['colonnes'], true),
    'Aucune colonne de faits geles ne subsiste apres rollback'
);

$artisan('migrations reappliquees', ['migrate', '--force', '--env=testing']);

$temoin(
    $schema() === $apres,
    'Le schema reapplique est identique au premier : aucun residu, aucune colonne perdue'
);

flock($verrou, LOCK_UN);

fwrite(STDOUT, "\n  " . str_repeat('-', 66) . "\n");

if ($echecs === 0) {
    fwrite(STDOUT, "  Les cinq migrations s appliquent, se defont et se reappliquent.\n");

    if ($connexion === null) {
        fwrite(STDOUT, "  Connexion de test. L epreuve MariaDB reste a faire : elle refuse des index,\n");
        fwrite(STDOUT, "  des types et des cles etrangeres que SQLite accepte sans rien dire.\n");
    }

    fwrite(STDOUT, "\n");

    exit(0);
}

fwrite(STDOUT, '  ' . $echecs . " temoin(s) en echec.\n\n");

exit(1);
