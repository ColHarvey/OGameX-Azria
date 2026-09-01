<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Releve periodique de l'etat des bases hostiles.
 *
 * La question « est-ce que les pirates evoluent bien » ne se repond pas en regardant une
 * base : elle se repond en comparant deux instants. Or rien ne conservait cette trace. Le
 * tick affichait bien une ligne de croissance par base, mais sur la sortie standard d'une
 * commande planifiee que personne ne lit, et npc_observations ne consigne que les decisions
 * de raid — la croissance n'y figure pas.
 *
 * Comme npc_observations, c'est de la telemetrie et non de l'etat de jeu : aucune mecanique
 * ne la lit, le systeme fonctionnerait a l'identique si la table etait vide, et elle est
 * purgeable a tout moment.
 *
 * Un releve par base et par heure, pas par tick. Le tick tourne au quart d'heure, ce qui
 * ferait quatre fois plus de lignes sans rien apprendre de plus : une base ne change pas
 * d'allure en quinze minutes, ses batiments se comptent en heures.
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
        Schema::create('npc_base_snapshots', function (Blueprint $table) {
            $table->id();

            // Le compte et la planete. Pas de cle etrangere : la destruction d'une base ne
            // doit pas emporter l'historique qui explique comment elle en est arrivee la.
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('planet_id');

            // Ce qui dit si la base progresse.
            $table->unsignedInteger('score')->default(0);
            $table->unsignedTinyInteger('maturity')->default(0);
            $table->unsignedInteger('buildings')->default(0);
            $table->unsignedInteger('ships')->default(0);
            $table->unsignedInteger('defences')->default(0);

            // Ce qui dit pourquoi elle ne progresse pas. Une base qui repete « rien
            // d'abordable » n'est pas bloquee par une regle mais par ses caisses vides, et
            // seul le stock permet de faire la difference.
            $table->unsignedBigInteger('metal')->default(0);
            $table->unsignedBigInteger('crystal')->default(0);
            $table->unsignedBigInteger('deuterium')->default(0);

            // La decision du tick, telle qu'elle a ete prise.
            $table->string('action', 24)->nullable();
            $table->string('detail', 64)->nullable();

            $table->timestamp('observed_at');

            $table->index('observed_at', 'npc_snap_time_idx');
            $table->index(['user_id', 'observed_at'], 'npc_snap_user_time_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('npc_base_snapshots');
    }
};
