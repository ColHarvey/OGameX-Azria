<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * L accueil des formes de vie, reporte par un compte (tranche 2, journal §155).
 *
 * L invitation « Les formes de vie arrivent sur Azria » se montre a la premiere page apres
 * l ouverture, et **une seule fois par version d accueil** : « Plus tard » ecrit ici, et la fenetre ne
 * revient plus. Elle ne vit pas dans `lifeform_accounts`, dont la ligne ne nait qu au choix de
 * l espece — un compte peut reporter sans jamais choisir. Purement additive.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('lifeform_welcomes', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id', false, true);
            $table->unsignedSmallInteger('dismissed_version');
            $table->unsignedBigInteger('dismissed_at');
            $table->timestamps();

            $table->unique('user_id', 'lf_welcomes_user_unique');
            $table->foreign('user_id', 'lf_welcomes_user_fk')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lifeform_welcomes');
    }
};
