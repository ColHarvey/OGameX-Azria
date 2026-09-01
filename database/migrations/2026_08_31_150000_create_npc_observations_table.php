<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Journal d'observation des factions hostiles.
 *
 * C'est de la telemetrie, pas de l'etat de jeu. Aucune mecanique ne la lit : le systeme
 * fonctionnerait exactement pareil si cette table etait vide. Elle existe pour repondre aux
 * questions qu'on ne peut pas trancher autrement — combien de joueurs deviennent ciblables,
 * combien de raids seraient partis, et surtout ce qui les a empeches de partir.
 *
 * Sans elle, le mode simulation n'ecrit que sur la sortie standard d'une commande planifiee,
 * que personne ne lit : au bout de trois jours d'observation il n'y aurait rien a analyser.
 *
 * La conception annoncait une seule table ajoutee, et celle-ci est la seconde. La difference
 * est assumee : npc_threats porte de l'etat de jeu, celle-ci porte des mesures, et elle est
 * purgeable a tout moment sans consequence.
 *
 * Les index sont nommes explicitement et courts : MariaDB refuse un identifiant de plus de
 * 64 caracteres la ou SQLite l'accepte, ce qui a deja fait echouer une migration en
 * production alors que la suite de tests passait en local.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('npc_observations', function (Blueprint $table) {
            $table->id();

            // Le joueur evalue. Pas de cle etrangere : un compte supprime ne doit pas
            // emporter les mesures deja faites, qui restent vraies pour la periode couverte.
            $table->unsignedInteger('user_id');

            // 'raid' ou 'declined'.
            $table->string('outcome', 16);

            // Pour un refus, ce qui l'a motive. Nul pour un raid.
            $table->string('reason', 32)->nullable();

            // L'etat du joueur au moment de l'evaluation.
            $table->unsignedSmallInteger('threat')->default(0);
            $table->string('band', 24)->nullable();

            // Pour un raid : ce qui serait parti, et vers quoi.
            $table->string('motive', 32)->nullable();
            $table->unsignedInteger('power')->nullable();
            $table->unsignedInteger('fleet_size')->nullable();
            $table->unsignedBigInteger('estimated_loot')->nullable();
            $table->string('base_coordinate', 24)->nullable();
            $table->string('target_coordinate', 24)->nullable();

            // Faux tant que le mode simulation est actif : la decision a ete prise, la
            // flotte n'est pas partie.
            $table->boolean('executed')->default(false);

            // L'environnement du serveur au moment de la mesure. Sans lui, un chiffre
            // aberrant serait indebogable : on ne saurait pas distinguer une regle qui a mal
            // fonctionne d'un serveur qui a bouge sous elle.
            $table->unsignedInteger('active_players')->default(0);
            $table->unsignedInteger('median_score')->default(0);
            $table->unsignedInteger('threshold')->default(0);
            $table->unsignedInteger('living_bases')->default(0);

            $table->timestamp('observed_at');

            $table->index('observed_at', 'npc_obs_time_idx');
            $table->index(['user_id', 'observed_at'], 'npc_obs_user_time_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('npc_observations');
    }
};
