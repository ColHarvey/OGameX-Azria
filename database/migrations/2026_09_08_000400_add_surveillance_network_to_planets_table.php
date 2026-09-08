<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Le niveau du Reseau de surveillance, installation de planete propre au jeu d Azria.
 *
 * Meme convention que toute installation : la colonne porte le nom machine de l objet
 * (`surveillance_network`) et `PlanetService::getObjectLevel()` la lit dynamiquement. Rien d autre
 * a recenser : score, cases occupees et arbre technologique iterent sur `ObjectService`.
 *
 * Purement additive ; le batiment n est propose a la construction que si `patrols_enabled` vaut 1.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('planets', function (Blueprint $table) {
            $table->integer('surveillance_network')->default(0)->after('missile_silo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('planets', function (Blueprint $table) {
            $table->dropColumn('surveillance_network');
        });
    }
};
