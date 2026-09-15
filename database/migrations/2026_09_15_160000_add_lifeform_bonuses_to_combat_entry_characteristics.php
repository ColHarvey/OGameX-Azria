<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Les bonus de formes de vie d une flotte, geles a son admission dans un combat (journal §155.6).
 *
 * Une colonne JSON nullable : les lignes ecrites avant elle n en portent aucun, et « aucun » est la
 * valeur juste — les formes de vie n existaient pas quand elles ont ete gelees.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('combat_entry_characteristics', function (Blueprint $table) {
            $table->json('lifeform_bonuses')->nullable()->after('class_combat_bonus');
        });
    }

    public function down(): void
    {
        Schema::table('combat_entry_characteristics', function (Blueprint $table) {
            $table->dropColumn('lifeform_bonuses');
        });
    }
};
