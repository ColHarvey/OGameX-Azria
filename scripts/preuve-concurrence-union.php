<?php

/*
 * Deux joueurs rejoignent la meme union au meme instant. Un seul entre.
 *
 * ## La preuve qui manquait
 *
 * `joinUnion()` lit les budgets, puis ecrit. Mes essais precedents mesuraient que ces ecritures ont
 * une transaction a elles ; ils ne provoquaient **aucune course**. Or c'est la course qui compte :
 * a quinze flottes engagees, deux jointures simultanees pouvaient toutes deux lire « il reste une
 * place » et l'occuper.
 *
 * Ce script provoque le chevauchement au lieu de l'esperer, comme `scripts/preuve-verrou-suite.php`
 * le fait pour le verrou de suite : deux processus se preparent, attendent tous deux un signal, puis
 * appellent `joinUnion()` a la meme seconde.
 *
 * ## Ce qui est prouve, et ce qui ne l'est pas
 *
 * **Prouve** : la seconde jointure relit les compteurs **dans sa propre transaction**, et voit la
 * place prise par la premiere. Sans transaction, les deux liraient l'etat d'avant et entreraient
 * toutes les deux — l'union finirait a dix-sept flottes.
 *
 * **Pas prouve** : le verrou de ligne. `lockForUpdate()` ne compile rien sur SQLite, et SQLite
 * serialise de toute facon les ecritures sur le fichier entier. La preuve du verrou lui-meme demande
 * MariaDB, et ce script accepte une connexion nommee pour cela.
 *
 * Le dire est le point : une epreuve de concurrence qui passe sur un moteur qui serialise tout ne
 * prouve pas ce qu'on croit, et la presenter comme une validation du verrou serait faux.
 *
 * Usage :
 *
 *     php scripts/preuve-concurrence-union.php [connexion]
 */

$racine = dirname(__DIR__);

require $racine . '/vendor/autoload.php';

$connexion = $argv[1] ?? null;
$echecs = 0;
$travail = $racine . '/storage/framework/testing/preuve-concurrence';

$arret = static function (string $raison): never {
    fwrite(STDERR, "\n  " . $raison . "\n\n");

    exit(1);
};

$temoin = static function (bool $obtenu, string $enonce, string $detail = '') use (&$echecs): void {
    if (!$obtenu) {
        $echecs++;
    }

    fwrite(STDOUT, '  ' . ($obtenu ? '[ok]     ' : '[ECHEC]  ') . $enonce . "\n");

    if ($detail !== '') {
        fwrite(STDOUT, '            ' . $detail . "\n");
    }
};

// ---------------------------------------------------------------------------------------------
// Le verrou de suite : ce script ecrit dans la base de test.
// ---------------------------------------------------------------------------------------------

$fichierVerrou = $racine . '/storage/framework/testing/suite.lock';

if (!is_dir(dirname($fichierVerrou))) {
    mkdir(dirname($fichierVerrou), 0777, true);
}

$verrou = fopen($fichierVerrou, 'c');

if ($verrou === false) {
    $arret('Impossible d ouvrir le verrou de la suite.');
}

if (!flock($verrou, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, "\n  Une execution de la suite tient le verrou. Attente de sa fin...\n");

    if (!flock($verrou, LOCK_EX)) {
        $arret('Impossible d obtenir le verrou de la suite.');
    }
}

if (!is_dir($travail) && !mkdir($travail, 0777, true)) {
    $arret('Dossier de travail impossible a creer : ' . $travail);
}

foreach (glob($travail . '/*') ?: [] as $reste) {
    unlink($reste);
}

$php = PHP_BINARY;

/**
 * Execute un bout de PHP dans un processus neuf, et rend sa sortie.
 */
$dansUnProcessusNeuf = static function (string $corps, bool $attendre = true) use ($php, $racine, $connexion, $travail) {
    static $rang = 0;
    $rang++;

    $script = $travail . '/sonde-' . $rang . '.php';

    $prologue = "<?php\nrequire " . var_export($racine . '/vendor/autoload.php', true) . ";\n"
        . '$app = require ' . var_export($racine . '/bootstrap/app.php', true) . ";\n"
        . '$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();' . "\n";

    if ($connexion !== null) {
        $prologue .= 'config()->set("database.default", ' . var_export($connexion, true) . ");\n";
    }

    file_put_contents($script, $prologue . $corps);

    if (!$attendre) {
        return proc_open('"' . $php . '" "' . $script . '"', [1 => ['file', $script . '.out', 'w'], 2 => ['file', $script . '.err', 'w']], $tuyaux);
    }

    $sortie = [];
    exec('"' . $php . '" "' . $script . '" 2>&1', $sortie);

    return implode("\n", $sortie);
};

fwrite(STDOUT, "\n  Deux jointures simultanees sur la derniere place\n");
fwrite(STDOUT, '  ' . str_repeat('-', 62) . "\n\n");

// ---------------------------------------------------------------------------------------------
// 1. Le decor : une union a quinze flottes, et deux candidates pour la seizieme place.
// ---------------------------------------------------------------------------------------------

$decor = <<<'CODE'
use OGame\Models\FleetMission;
use OGame\Models\FleetUnion;
use OGame\Models\Planet;
use OGame\Models\User;

$createur = User::factory()->create();

// **Des coordonnees libres, cherchees et non supposees.** Le script doit etre rejouable : la
// premiere execution avait laisse sa planete, et la seconde echouait sur l unicite des
// coordonnees avant meme d avoir commence la course.
$systeme = 300;

while (Planet::where('galaxy', 7)->where('system', $systeme)->exists()) {
    $systeme++;
}

$planete = Planet::factory()->create([
    'user_id' => $createur->id,
    'galaxy' => 7,
    'system' => $systeme,
    'planet' => 5,
]);

$mission = function (int $userId, int $planetId, int $arrivee): FleetMission {
    return FleetMission::forceCreate([
        'user_id' => $userId,
        'planet_id_from' => $planetId,
        'mission_type' => 1,
        'time_departure' => time(),
        'time_arrival' => $arrivee,
        'galaxy_to' => 7,
        'system_to' => 1,
        'position_to' => 1,
        'type_to' => 1,
        'light_fighter' => 10,
    ]);
};

$arrivee = time() + 100000;

$union = app(OGame\Services\FleetUnionService::class)
    ->createUnion($mission($createur->id, $planete->id, $arrivee));

// Quatorze flottes de plus : l'union en compte quinze avec la fondatrice.
for ($rang = 0; $rang < 14; $rang++) {
    $membre = $mission($createur->id, $planete->id, $arrivee);
    $membre->union_id = $union->id;
    $membre->union_slot = $rang + 2;
    $membre->mission_type = 2;
    $membre->save();
}

// Les deux candidates a la seizieme place.
$un = $mission($createur->id, $planete->id, $arrivee);
$deux = $mission($createur->id, $planete->id, $arrivee);

echo json_encode([
    'union' => $union->id,
    'un' => $un->id,
    'deux' => $deux->id,
    'flottes' => $union->activeFleetMissions()->count(),
]);
CODE;

$json = json_decode($dansUnProcessusNeuf($decor), true);

if (!is_array($json) || !isset($json['union'])) {
    flock($verrou, LOCK_UN);

    $arret("Le decor n a pas pu etre prepare :\n\n  " . $dansUnProcessusNeuf($decor));
}

$temoin(
    $json['flottes'] === 15,
    'L union compte quinze flottes avant la course.',
    'mesure : ' . $json['flottes']
);

// ---------------------------------------------------------------------------------------------
// 2. La course : deux processus attendent le meme signal, puis appellent joinUnion().
// ---------------------------------------------------------------------------------------------

$concurrent = static function (int $missionId, int $unionId) use ($travail): string {
    return <<<CODE
use OGame\\Models\\FleetMission;
use OGame\\Models\\FleetUnion;

\$signal = '{$travail}/partez';

// Prete, puis on attend le signal : c'est ce qui fait la simultaneite.
touch('{$travail}/prete-{$missionId}');

\$echeance = time() + 60;

while (!file_exists(\$signal) && time() < \$echeance) {
    usleep(20000);
}

\$union = FleetUnion::find({$unionId});
\$mission = FleetMission::find({$missionId});

try {
    app(OGame\\Services\\FleetUnionService::class)->joinUnion(\$union, \$mission);
    echo json_encode(['issue' => 'admise']);
} catch (Throwable \$refus) {
    echo json_encode(['issue' => 'refusee', 'raison' => \$refus->getMessage()]);
}
CODE;
};

$processus = [];

foreach (['un', 'deux'] as $nom) {
    $processus[$nom] = $dansUnProcessusNeuf($concurrent($json[$nom], $json['union']), attendre: false);
}

// Les deux sont prets ?
$echeance = time() + 60;
$pretes = false;

while (time() < $echeance) {
    if (file_exists($travail . '/prete-' . $json['un']) && file_exists($travail . '/prete-' . $json['deux'])) {
        $pretes = true;

        break;
    }

    usleep(50000);
}

$temoin($pretes, 'Les deux concurrents sont prets et attendent le meme signal.');

if (!$pretes) {
    flock($verrou, LOCK_UN);

    $arret('Les concurrents ne se sont pas mis en place : la course n a pas eu lieu.');
}

touch($travail . '/partez');

foreach ($processus as $handle) {
    if (is_resource($handle)) {
        proc_close($handle);
    }
}

// ---------------------------------------------------------------------------------------------
// 3. Le verdict.
// ---------------------------------------------------------------------------------------------

$issues = [];

foreach (glob($travail . '/sonde-*.php.out') ?: [] as $fichier) {
    $contenu = json_decode((string)file_get_contents($fichier), true);

    if (is_array($contenu) && isset($contenu['issue'])) {
        $issues[] = $contenu;
    }
}

$admises = array_filter($issues, static fn (array $i): bool => $i['issue'] === 'admise');
$refusees = array_filter($issues, static fn (array $i): bool => $i['issue'] === 'refusee');

$temoin(count($issues) === 2, 'Les deux concurrents ont rendu une issue.', 'issues : ' . count($issues));

$temoin(
    count($admises) === 1,
    'Exactement une des deux jointures est admise.',
    'admises : ' . count($admises) . ', refusees : ' . count($refusees)
);

foreach ($refusees as $refus) {
    $temoin(
        str_contains($refus['raison'], '16') || str_contains(strtolower($refus['raison']), 'complet'),
        'La refusee lit que l union est pleine, et non une erreur technique.',
        $refus['raison']
    );
}

$compte = $dansUnProcessusNeuf(
    'echo (string)OGame\Models\FleetUnion::find(' . $json['union'] . ')->activeFleetMissions()->count();'
);

$temoin(
    trim($compte) === '16',
    'L union compte seize flottes, jamais dix-sept.',
    'mesure : ' . trim($compte)
);

flock($verrou, LOCK_UN);

fwrite(STDOUT, "\n  " . str_repeat('-', 62) . "\n");

if ($echecs === 0) {
    fwrite(STDOUT, "  La seconde jointure relit les compteurs dans sa propre transaction.\n");

    if ($connexion === null) {
        fwrite(STDOUT, "  SQLite serialise les ecritures : le verrou de ligne lui-meme reste a\n");
        fwrite(STDOUT, "  eprouver en MariaDB. Cette preuve ne le remplace pas.\n");
    }

    fwrite(STDOUT, "\n");

    exit(0);
}

fwrite(STDOUT, '  ' . $echecs . " temoin(s) en echec. Sondes conservees dans " . $travail . "\n\n");

exit(1);
