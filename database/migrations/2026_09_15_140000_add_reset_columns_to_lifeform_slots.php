<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * La remise a zero d un palier de recherches (tranche 3, journal §155).
 *
 * Remettre un palier a zero vide ses six emplacements sans toucher aux niveaux des technologies
 * (`lifeform_technology_levels` reste) ; la selection precedente est gardee dans
 * `previous_object_id` pour la restauration, permise pendant une heure. `reset_at` porte l instant
 * de la derniere remise a zero, d ou le delai d un jour entre deux. `chosen_via` dit comment la
 * technologie est entree dans l emplacement : locale, tirage, artefacts.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('lifeform_slots', function (Blueprint $table) {
            $table->unsignedInteger('previous_object_id')->nullable()->after('object_id');
            $table->unsignedBigInteger('reset_at')->nullable()->after('selected_at');
            $table->string('chosen_via', 12)->nullable()->after('reset_at');
        });
    }

    public function down(): void
    {
        Schema::table('lifeform_slots', function (Blueprint $table) {
            $table->dropColumn(['previous_object_id', 'reset_at', 'chosen_via']);
        });
    }
};
