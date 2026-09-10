<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Le proprietaire de la patrouille visee, fige au lancement.
 *
 * ------------------------------------------------------------------------------------
 * POURQUOI L IDENTITE NE SE REDUIT PAS A UN IDENTIFIANT
 *
 * `target_patrol_id` dit **quelle** patrouille est visee ; `x_to`/`y_to` disent **ou** elle etait.
 * Il manquait le troisieme fait : **a qui** elle appartenait.
 *
 * `PatrolTargetLock::decide()` compare les trois, et refuse la cible si l un a change — c est la
 * revue 120 : « identite **et** emplacement ». Sans cette colonne, une patrouille qui aurait change
 * de mains pendant le vol resterait « la meme cible », et la flotte attaquerait un joueur qu elle
 * n avait pas vise. Le cas n est pas theorique : un compte se supprime, une patrouille se cede.
 *
 * ------------------------------------------------------------------------------------
 * AUCUNE CLE ETRANGERE, POUR LA MEME RAISON QUE PARTOUT AILLEURS
 *
 * C est un **fait gele**, pas un lien vivant : le proprietaire peut disparaitre, et l attaque doit
 * pouvoir constater que la cible n est plus celle qu elle visait. Une cle etrangere effacerait
 * justement la trace qui permet ce constat.
 *
 * Purement additive et nullable : une attaque ordinaire la laisse vide, et le chemin spatial ne
 * s ouvre que derriere `patrols_enabled`.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('fleet_missions', function (Blueprint $table) {
            $table->integer('target_patrol_owner_id', false, true)->nullable()->after('target_patrol_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fleet_missions', function (Blueprint $table) {
            $table->dropColumn('target_patrol_owner_id');
        });
    }
};
