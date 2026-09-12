<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ou la flotte a fait demi-tour, quand un joueur la rappelle.
 *
 * ## Le defaut que cette colonne ferme
 *
 * `GameMission::cancel()` tronque l'heure d'arrivee de l'aller a maintenant, puis `startReturn()`
 * cree le retour **depuis la cible** — c'est le modele du jeu : un retour part de la ou l'aller
 * allait. Sur la carte tactique, la flotte saute donc au bout du trajet puis revient, au lieu de
 * rebrousser chemin depuis sa position. Keven, 12 septembre 2026 : « c'est comme s'il sortait de
 * l'hyperespace alors qu'il n'y est jamais entre ».
 *
 * ## Pourquoi une colonne, et pas un calcul
 *
 * La fraction parcourue vaut `(instant du rappel − depart) / (arrivee prevue − depart)`. Elle n'est
 * plus calculable **apres** le rappel : `cancel()` a ecrase l'arrivee prevue de l'aller par
 * l'instant du rappel. Elle doit donc etre ecrite pendant le rappel, ou perdue pour toujours.
 *
 * ## Une fraction, pas un point
 *
 * Un point aurait exige que le serveur adopte la convention geometrique de la carte — et cette
 * convention n'existe pas entre deux systemes, ou la flotte est « en hyperespace ». Une fraction
 * ne dit que ce que le serveur sait : quelle part du trajet etait faite. La carte applique ensuite
 * sa propre geometrie, celle qu'elle emploie deja pour placer une flotte en vol.
 *
 * ## Un entier, en dix-milliemes
 *
 * `ExpectedReturn::differenceOn()` refuse toute valeur imposee qui n est pas entiere, et PDO rend
 * les `REAL` de SQLite en chaines degradees a quinze chiffres. La fraction est donc stockee en
 * **dix-milliemes** : 0 a 10 000. La projection la rend au client en fraction.
 *
 * Nulle partout ailleurs : seul le retour d'un rappel la porte. Un retour cree par un combat, un
 * refus ou une annulation la laisse nulle — et `ExpectedReturn` l'**impose a nul**, faute de quoi sa
 * projection fermee refuserait tout retour de flotte renvoyee par un combat.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('fleet_missions', function (Blueprint $table): void {
            $table->unsignedInteger('recall_progress')->nullable()->after('parent_id');
        });
    }

    public function down(): void
    {
        Schema::table('fleet_missions', function (Blueprint $table): void {
            $table->dropColumn('recall_progress');
        });
    }
};
