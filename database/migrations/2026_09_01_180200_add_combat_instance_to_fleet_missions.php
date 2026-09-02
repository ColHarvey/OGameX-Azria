<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Le lien entre une flotte et le combat qui la retient.
 *
 * Une flotte engagee n'est plus une flotte comme les autres : elle ne se rappelle pas, elle ne
 * cree pas encore sa mission retour, et elle ne doit surtout pas etre traitee une seconde fois
 * par le chemin de secours de `PlayerService::updateFleetMissions()`.
 *
 * Cette colonne est ce qui permet de le savoir sans interroger les participants a chaque
 * passage : nulle, la flotte suit son cycle habituel ; renseignee, elle appartient a un combat.
 *
 * Migration purement additive : la colonne est nullable, toutes les flottes existantes restent
 * valides avec leur `null`, et rien ne change tant que le systeme est desactive. `down()`
 * retire la colonne sans toucher au reste.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('fleet_missions', function (Blueprint $table) {
            $table->unsignedBigInteger('combat_instance_id')->nullable()->after('id');

            // Retrouver toutes les flottes d'un combat, et distinguer d'un coup celles qui n'en
            // ont aucun — c'est cette seconde requete que le traitement des arrivees fera le
            // plus souvent.
            $table->index('combat_instance_id', 'fleet_combat_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fleet_missions', function (Blueprint $table) {
            $table->dropIndex('fleet_combat_idx');
            $table->dropColumn('combat_instance_id');
        });
    }
};
