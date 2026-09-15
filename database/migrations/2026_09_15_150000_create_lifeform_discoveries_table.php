<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Les vols de decouverte des formes de vie (tranche 4, journal §155).
 *
 * Un vol part d une planete du compte vers des coordonnees, sans vaisseau, contre des ressources et
 * un point de quota. **Son issue est scellee au lancement** (`outcome`, JSON : genre, espece,
 * artefacts, experience) : le hasard appartient au serveur et ne change pas en rafraichissant la
 * page. Elle est **creditee une fois** a l echeance, dans la transaction qui pose `settled_at` ; deux
 * travailleurs ou une reprise ne creditent pas deux fois.
 *
 * Les coordonnees restent en clair pour la regle de reexploration (sept jours par compte et par
 * position) et pour le rapport. Aucune information sur les flottes ou les patrouilles n en sort.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('lifeform_discoveries', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id', false, true);
            $table->integer('planet_id', false, true);
            $table->unsignedSmallInteger('galaxy');
            $table->unsignedSmallInteger('system');
            $table->unsignedTinyInteger('position');
            $table->unsignedBigInteger('started_at');
            $table->unsignedBigInteger('ends_at');
            $table->json('outcome');
            // 'running' | 'settled'
            $table->string('status', 12)->default('running');
            $table->unsignedBigInteger('settled_at')->nullable();
            $table->unsignedSmallInteger('rules_version');
            $table->timestamps();

            $table->index(['user_id', 'status', 'ends_at'], 'lf_discoveries_user_status_idx');
            $table->index(['user_id', 'galaxy', 'system', 'position'], 'lf_discoveries_target_idx');
            $table->foreign('user_id', 'lf_discoveries_user_fk')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lifeform_discoveries');
    }
};
