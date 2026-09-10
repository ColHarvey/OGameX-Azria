<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Un combat peut avoir lieu la ou il n y a pas de corps celeste.
 *
 * ------------------------------------------------------------------------------------
 * POURQUOI TROIS COLONNES ET PAS UN CHAMP DE FAITS
 *
 * `combat_instances` porte deja `galaxy`, `system` et `position` : de quoi dire ou une
 * bataille a eu lieu meme si la planete disparait ensuite. Un point libre n occupe aucune
 * des quinze positions — `position` y vaut zero — et ces trois colonnes ne suffisent donc
 * plus a le retrouver.
 *
 * Les faits geles de cette table sont des **colonnes**, pas un document libre : il n y a
 * pas de « champ de faits » ou glisser deux coordonnees. Les ajouter ici les met au meme
 * rang que le reste de ce qu une ouverture fige, et les rend lisibles au reglement sans
 * relire la patrouille — dont le point aura pu changer entre-temps.
 *
 * ------------------------------------------------------------------------------------
 * CE QUE `target_patrol_id` N EST PAS
 *
 * Ce n est pas le verrou. C est `patrol_combat_barriers.patrol_id`, unique, qui arbitre
 * « cette patrouille est-elle deja en combat ». Cette colonne-ci est la trace lisible du
 * cote de l instance : elle dit qui etait vise, y compris apres que la barriere a ete
 * levee et la patrouille effacee.
 *
 * Elle ne porte donc **aucune cle etrangere** : une patrouille finie doit pouvoir
 * disparaitre sans emporter l histoire de la bataille qui l a tuee. C est la meme regle
 * que pour `target_planet_id`, qui survit a la planete.
 *
 * Purement additive, toutes nullables : une instance de combat ordinaire les laisse vides.
 * Inerte tant que `patrols_enabled` vaut 0.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('combat_instances', function (Blueprint $table) {
            $table->unsignedBigInteger('target_patrol_id')->nullable()->after('target_planet_id');
            $table->integer('point_x')->nullable()->after('position');
            $table->integer('point_y')->nullable()->after('point_x');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('combat_instances', function (Blueprint $table) {
            $table->dropColumn(['target_patrol_id', 'point_x', 'point_y']);
        });
    }
};
