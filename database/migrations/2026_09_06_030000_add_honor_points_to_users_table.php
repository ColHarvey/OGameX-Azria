<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Les points d'honneur, enfin stockes.
 *
 * ## Ce qui existait, et ce qui manquait
 *
 * La vue generale affichait « Points d'honneur » depuis toujours, cablee sur la constante zero
 * (`OverviewController`, marque `@TODO`). La Galaxie gardait `isHonorableTarget()` et `isOutlaw()`
 * en commentaire, et `HonorPolicy` nommait le systeme pour dire qu'il etait **desactive**. Tout
 * etait pret sauf le moteur — et la colonne pour le retenir.
 *
 * ## Pourquoi un entier signe, et pourquoi pas de plancher ici
 *
 * L'honneur descend sous zero : c'est ce qui fait le bandit. La colonne est donc signee, et aucune
 * borne n'est posee par le schema — les seuils sont des **regles de jeu**, ils vivent dans les
 * reglages de l'univers et se changent sans migration. Une borne ecrite ici les figerait.
 *
 * L'index sert la Galaxie : elle lit le statut de chaque joueur affiche, quinze positions a la fois.
 *
 * Migration purement additive ; `down()` la defait entierement.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->integer('honor_points')->default(0)->after('id');
            $table->index('honor_points');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['honor_points']);
            $table->dropColumn('honor_points');
        });
    }
};
