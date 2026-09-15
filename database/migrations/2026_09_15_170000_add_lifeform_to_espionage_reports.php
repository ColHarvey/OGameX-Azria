<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * La section « formes de vie » du rapport d espionnage (journal §155.7).
 *
 * Nullable : un rapport d avant, ou une sonde qui n en a pas assez vu, n en porte pas — et « pas vu »
 * n est pas « rien ». La colonne garde des faits (nom machine de l espece, population, part protegee) ;
 * la phrase est traduite a la lecture.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('espionage_reports', function (Blueprint $table) {
            $table->json('lifeform')->nullable()->after('research');
        });
    }

    public function down(): void
    {
        Schema::table('espionage_reports', function (Blueprint $table) {
            $table->dropColumn('lifeform');
        });
    }
};
