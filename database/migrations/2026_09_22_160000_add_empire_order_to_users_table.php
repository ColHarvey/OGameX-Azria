<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **L ordre des colonnes de la vue Empire.**
 *
 * Deux listes d identifiants — les planetes et les lunes — dans une seule colonne JSON du compte. C est une
 * preference d affichage : elle n est jamais lue comme une source de verite sur ce que le joueur possede, et un
 * identifiant qui n a plus de corps est ignore a la lecture.
 *
 * Colonne de texte plutot que `json` : SQLite et MariaDB la portent de la meme facon, et rien ici n est requete par
 * son contenu — elle est lue en entier, decodee, et reecrite en entier.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('empire_order')->nullable()->after('lang');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('empire_order');
        });
    }
};
