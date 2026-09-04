<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les cargaisons des missions en DOUBLE, explicitement.
 *
 * `2025_01_18_230622` les a passees en `float()`. Sous la grammaire MySQL de Laravel 11 et suivants,
 * `float()` sans precision ecrit `float(53)`, que MariaDB range en DOUBLE ; sous Laravel 10, le meme
 * appel ecrivait `double(8, 2)`. Ce que porte reellement la colonne deployee depend donc de la
 * version du cadriciel au moment ou cette migration a tourne — et un vrai FLOAT ne distingue les
 * entiers que jusqu'a 2^24, seize millions, une cargaison ordinaire.
 *
 * Cette migration ne modifie pas l'historique : elle declare le type que le reglement durable
 * suppose (`ResourceBoundary::EXACT_INTEGER_LIMIT`, 2^53), comme `2026_01_20_000001` l'a fait pour
 * les planetes et les champs de debris. Sur une colonne deja DOUBLE elle ne change rien. La mesure
 * sur la base deployee (`information_schema.columns`) reste due avant toute activation.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('fleet_missions', function (Blueprint $table) {
            $table->double('metal')->default(0)->change();
            $table->double('crystal')->default(0)->change();
            $table->double('deuterium')->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('fleet_missions', function (Blueprint $table) {
            $table->float('metal')->default(0)->change();
            $table->float('crystal')->default(0)->change();
            $table->float('deuterium')->default(0)->change();
        });
    }
};
