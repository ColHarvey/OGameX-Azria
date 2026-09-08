<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Ce qu un segment de patrouille ajoute a une mission : sa patrouille, et ses deux bouts en
 * coordonnees de reference.
 *
 * ## Les bouts d un segment
 *
 * `galaxy/system/position` restent ecrits pour tous les lecteurs qui ne connaissent que des
 * positions (boite d evenements, messages, anciens rendus) : `position` est alors l orbite la plus
 * proche du point. Le point reel est (`x`, `y`) en unites spatiales de `SystemGeometry`, nul pour
 * une mission ordinaire. Une manoeuvre en vol (revue 121, R2) part de la position reellement
 * atteinte a la fin du delai : c est entre `x_from/y_from` et `x_to/y_to` que le serveur l interpole.
 *
 * ## `patrol_id` et `target_patrol_id`
 *
 * `patrol_id` : la patrouille dont ce segment est le vol courant (aller, manoeuvre, retour, ou
 * attaque lancee depuis son point). `target_patrol_id` : la patrouille visee par une attaque,
 * figee au lancement avec son emplacement (`x_to/y_to`) — identite **et** emplacement, revue 120 :
 * a l arrivee, si cette patrouille n est plus a ce point, position vide et retour, jamais une
 * autre patrouille a sa place.
 *
 * Pas de cle etrangere : comme `combat_instance_id`, la colonne est ajoutee a une table vivante et
 * SQLite ne pose pas de contrainte par `ALTER TABLE` ; le code et les index tiennent l integrite.
 *
 * Purement additive : nulle partout, une mission sans patrouille se comporte exactement comme avant.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('fleet_missions', function (Blueprint $table) {
            $table->unsignedBigInteger('patrol_id')->nullable()->after('combat_instance_id');
            $table->unsignedBigInteger('target_patrol_id')->nullable()->after('patrol_id');
            $table->smallInteger('x_from')->nullable()->after('position_from');
            $table->smallInteger('y_from')->nullable()->after('x_from');
            $table->smallInteger('x_to')->nullable()->after('position_to');
            $table->smallInteger('y_to')->nullable()->after('x_to');

            $table->index(['patrol_id', 'processed'], 'fleet_patrol_idx');
            $table->index(['target_patrol_id', 'processed'], 'fleet_target_patrol_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fleet_missions', function (Blueprint $table) {
            $table->dropIndex('fleet_patrol_idx');
            $table->dropIndex('fleet_target_patrol_idx');
            $table->dropColumn(['patrol_id', 'target_patrol_id', 'x_from', 'y_from', 'x_to', 'y_to']);
        });
    }
};
