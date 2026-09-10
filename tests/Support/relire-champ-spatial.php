<?php

/**
 * Relit un etat de champ persiste, **dans un processus qui n'a rien vu de la bataille**.
 *
 * ------------------------------------------------------------------------------------
 * POURQUOI UN AUTRE PROCESSUS
 *
 * Une reprise eprouvee dans le processus qui vient d'ouvrir le champ peut passer sans rien
 * prouver : un objet encore vivant en memoire porterait ce que la persistance a perdu, et
 * l'essai serait vert en lisant ce qu'il croit avoir relu.
 *
 * Ce lecteur ne connait que le document. Il ne **compose** aucun champ, et surtout **ne rejoue
 * pas la manoeuvre de Hamill** : il n'a aucun moyen de le faire. C'est precisement ce qui rend
 * la mesure concluante — ce qu'il rapporte vient du document seul.
 *
 * ------------------------------------------------------------------------------------
 * IL PEUT AUSSI JOUER LA SUITE
 *
 * Avec un second argument, il **continue** la bataille de ce nombre de rounds. Le champ seul
 * n'y suffit pas : le moteur veut aussi les identites des flottes. Elles sont reconstruites
 * depuis les faits geles du butin — jamais depuis une flotte relue en base, qui aurait pu
 * changer depuis l'ouverture.
 *
 * C'est ce qui permet de comparer une bataille interrompue puis reprise a une bataille menee
 * d'une traite : deux chemins differents, un seul etat attendu.
 *
 * ------------------------------------------------------------------------------------
 * CE QU'IL RAPPORTE
 *
 * L'effectif defenseur et le nombre d'Etoiles de la Mort qui y restent — l'**effet** de la
 * manoeuvre —, et pour chacune des deux bandes sa graine, son mot courant et le nombre de
 * tirages bruts consommes — sa **position**. Les deux doivent survivre a la persistance, et
 * ce sont deux faits distincts.
 *
 * Usage : `php tests/Support/relire-champ-spatial.php <fichier.json> [rounds]`, qui ecrit un
 * document JSON sur la sortie standard.
 */
$racine = dirname(__DIR__, 2);

require $racine . '/vendor/autoload.php';

/** @var Illuminate\Foundation\Application $app */
$app = require $racine . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// **Toute panne sort avec un code non nul.** Le noyau de Laravel installe un gestionnaire qui
// rend une exception joliment et laisse le processus sortir avec zero : un appelant croirait
// alors que la reprise a reussi et lirait une sortie vide. Une mutation l a montre.
set_exception_handler(static function (Throwable $panne): void {
    fwrite(STDERR, get_class($panne) . ' : ' . $panne->getMessage() . PHP_EOL);
    exit(1);
});

$chemin = $argv[1] ?? '';

if ($chemin === '' || !is_file($chemin)) {
    fwrite(STDERR, "Aucun document a relire.\n");
    exit(1);
}

$document = json_decode((string)file_get_contents($chemin), true, 512, JSON_THROW_ON_ERROR);

if (!is_array($document) || !is_array($document['champ'] ?? null)) {
    fwrite(STDERR, "Le document relu ne porte pas de champ.\n");
    exit(1);
}

$champ = OGame\Combat\Replay\BattleFieldStateCodec::fromStorage($document['champ']);

// **Jouer la suite, si on le demande.** Le champ seul ne suffit pas : le moteur veut aussi les
// identites des flottes. Elles sont reconstruites depuis les faits geles du butin — jamais depuis
// une flotte relue en base, qui aurait pu changer depuis l ouverture.
$aJouer = (int)($argv[2] ?? 0);

if ($aJouer > 0) {
    if (!is_array($document['butin'] ?? null)) {
        fwrite(STDERR, "Le document ne porte pas les faits geles du butin : la reprise ne peut pas se monter.\n");
        exit(1);
    }

    $butin = OGame\Combat\Support\LootContext::fromFrozenFacts($document['butin']);

    $proprietaire = new OGame\Patrol\Combat\FrozenCombatant(
        (int)($document['proprietaire'] ?? 0),
        0,
        0,
        0,
        0,
    );

    $site = new OGame\Patrol\Combat\SpatialCombatSite(
        resolve(OGame\Factories\PlayerServiceFactory::class),
        resolve(OGame\Services\SettingsService::class),
        $proprietaire,
        new OGame\Patrol\Geometry\SpatialPoint(0, 0),
        (int)($document['galaxie'] ?? 1),
        (int)($document['systeme'] ?? 1),
    );

    OGame\Patrol\Combat\SpatialFieldResumption::of(
        $champ,
        $site,
        resolve(OGame\Services\SettingsService::class),
        $butin,
    )->play($champ, $aJouer);
}

// **Une bande dictee par un essai n a ni graine ni mot courant** : `BattleDraws` est une interface,
// et seule une suite a graine sait dire ou elle en est. Un document relu qui n en porterait pas ne
// se reprend pas, et le dire vaut mieux que de rapporter des valeurs nulles qui passeraient pour
// une position.
foreach (['bataille' => $champ->battleDraws, 'rounds' => $champ->roundDraws] as $nom => $bande) {
    if (!$bande instanceof OGame\GameMissions\BattleEngine\Draws\SeededDraws) {
        fwrite(STDERR, 'La bande « ' . $nom . " » relue n est pas une suite a graine : sa position ne se lit pas.\n");
        exit(1);
    }
}

/** @var OGame\GameMissions\BattleEngine\Draws\SeededDraws $bataille */
$bataille = $champ->battleDraws;
/** @var OGame\GameMissions\BattleEngine\Draws\SeededDraws $rounds */
$rounds = $champ->roundDraws;

$etoiles = 0;

foreach ($champ->defenderUnits as $unite) {
    if ($unite->unitObject->machine_name === 'deathstar') {
        $etoiles++;
    }
}

echo json_encode([
    'processus' => getmypid(),
    'rounds_joues' => $champ->roundsPlayed,
    'unites_defenseuses' => count($champ->defenderUnits),
    'unites_attaquantes' => count($champ->attackerUnits),
    'etoiles_de_la_mort' => $etoiles,
    // Les trois memes champs a plat : c est par eux qu une bataille interrompue se compare a une
    // bataille menee d une traite, champ par champ. Un releve plus riche d un cote laisserait
    // passer ce qu il ne regarde pas.
    'mot_bataille' => $bataille->rawState(),
    'mot_rounds' => $rounds->rawState(),
    'tirages_rounds' => $rounds->journal()->rawCount(),
    'bande_bataille' => [
        'graine' => $bataille->seed(),
        'mot' => $bataille->rawState(),
        'tirages_bruts' => $bataille->journal()->rawCount(),
    ],
    'bande_rounds' => [
        'graine' => $rounds->seed(),
        'mot' => $rounds->rawState(),
        'tirages_bruts' => $rounds->journal()->rawCount(),
    ],
], JSON_THROW_ON_ERROR) . "\n";
