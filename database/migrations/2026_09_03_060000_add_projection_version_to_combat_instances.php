<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Sous quelle projection ce combat ecrit ses inclusions.
 *
 * ## Le trou que cette colonne ferme
 *
 * `combat_snapshot_inclusions.projection_version` existe depuis la premiere livraison, et l'unicite
 * porte sur le triplet combat / evenement / version. Mais **rien ne disait quelle version un combat
 * donne devait ecrire** : seuls des essais posaient « v1 » a la main.
 *
 * Sans source autoritaire, la fermeture aurait lu la version courante au moment ou le worker s'est
 * reveille. Deux heures apres l'ouverture, une bascule de projection aurait donc fait entrer le meme
 * evenement une seconde fois dans le meme combat — l'unicite ne l'aurait pas vu, puisqu'elle separe
 * justement les versions. La garnison aurait ete comptee deux fois.
 *
 * ## Pourquoi une version de plus, et non l'une des quatre
 *
 * Les quatre versions gelees disent comment un combat *decide*. Celle-ci dit comment une inclusion
 * se *lit*. Elles bougent pour des raisons independantes ; les confondre obligerait a faire avancer
 * les quatre pour un changement qui n'en concerne aucune.
 *
 * Nulle pour les instances anterieures : elles n'ont aucune inclusion, la question ne s'est jamais
 * posee pour elles. Migration purement additive ; `down()` retire exactement ce qu'elle ajoute.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('combat_instances', function (Blueprint $table) {
            // `v1` — voir SnapshotProjection. Choisie a l'ouverture, relue partout ensuite.
            $table->string('projection_version', 20)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('combat_instances', function (Blueprint $table) {
            $table->dropColumn('projection_version');
        });
    }
};
