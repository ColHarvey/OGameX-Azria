<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quand une perte a-t-elle ete diffusee au joueur ?
 *
 * ## Pourquoi une colonne, et pas une file
 *
 * Les pertes deviennent visibles a un **instant** — `visible_at` —, pas a un evenement du serveur.
 * Un diffuseur passe donc regulierement, prend ce qui vient de devenir visible, l'envoie, et doit
 * savoir ce qu'il a deja envoye : sinon un joueur reverrait la meme perte a chaque passage, et une
 * reprise apres panne rejouerait toute la bataille.
 *
 * L'instant est ecrit **apres** la diffusion, dans la meme transaction : une diffusion perdue reste
 * a refaire, et une diffusion faite n'est jamais refaite. La colonne est nulle pour tout ce qui
 * n'est pas encore parti, y compris les evenements ecrits avant cette migration — ils partiront au
 * premier passage, ce qui est le comportement voulu pour une bataille en cours.
 *
 * L'index porte sur `visible_at` et `broadcast_at` ensemble : la question du diffuseur est toujours
 * « qu'est-ce qui est devenu visible et n'est pas encore parti ».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('combat_presentation_events', function (Blueprint $table) {
            $table->integer('broadcast_at', false, true)->nullable()->after('visible_at');

            $table->index(['broadcast_at', 'visible_at'], 'combat_presentation_broadcast_idx');
        });
    }

    public function down(): void
    {
        Schema::table('combat_presentation_events', function (Blueprint $table) {
            $table->dropIndex('combat_presentation_broadcast_idx');
            $table->dropColumn('broadcast_at');
        });
    }
};
