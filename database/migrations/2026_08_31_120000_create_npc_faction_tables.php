<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Factions hostiles pilotees par le serveur. Une seule existe a ce jour : les pirates.
 *
 * Le parti pris est qu'un PNJ est un joueur particulier et non un systeme parallele. Il
 * possede donc un vrai compte dans users, de vraies planetes, de vraies flottes, et il
 * emprunte le moteur de combat, les files de construction et les protections existantes.
 * Deux colonnes suffisent a le distinguer d'un humain.
 *
 * La seule table ajoutee porte la rancune que chaque joueur humain s'est attiree. Tout le
 * reste — puissance des bases, nombre de bases, position — se lit dans les tables du jeu.
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
        Schema::table('users', function (Blueprint $table) {
            // Un compte pilote par le serveur. Jamais connectable : son mot de passe est
            // aleatoire et n'est stocke nulle part.
            $table->boolean('is_npc')->default(false)->after('vacation_mode_until');

            // La faction. Une seule valeur existe a ce jour : 'pirate'. La colonne n'est pas
            // pour autant de l'anticipation gratuite — elle est deja porteuse, puisque le
            // recensement des bases et le classement filtrent dessus. Une seconde faction
            // s'y ajoutera sans migration, mais rien de ce qui la concerne n'est ecrit
            // d'avance : une branche jamais exercee est une dette, pas une avance.
            $table->string('npc_type', 16)->nullable()->after('is_npc');

            // Retrouver les PNJ est une operation frequente : classement, galaxie, tick.
            $table->index('is_npc', 'users_is_npc_idx');
        });

        // Rancune accumulee par un joueur humain envers les factions. Une ligne par joueur,
        // creee a la premiere provocation seulement : un joueur qui n'a jamais touche a un
        // pirate n'a pas de ligne, et cette absence vaut zero.
        Schema::create('npc_threats', function (Blueprint $table) {
            $table->id();

            $table->unsignedInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            // 0 a 100. Le plafond reellement atteignable depend de l'exposition du joueur
            // et se recalcule a la volee : il n'est pas stocke ici.
            $table->unsignedSmallInteger('threat')->default(0);

            // Dernier moment ou la decroissance a ete appliquee. La decroissance n'est pas
            // une tache de fond : elle se rattrape au moment ou la menace est lue, ce qui
            // evite de balayer tous les joueurs a chaque tick.
            $table->timestamp('last_decay_at')->nullable();

            // Dernier raid subi, pour le delai minimal entre deux attaques.
            $table->timestamp('last_raid_at')->nullable();

            // Motif du dernier raid decide. Il est repris dans le rapport de combat pour
            // expliquer au joueur pourquoi la flotte est venue.
            $table->string('last_motive', 32)->nullable();

            $table->timestamps();

            $table->unique('user_id', 'npc_threats_user_uniq');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('npc_threats');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_is_npc_idx');
            $table->dropColumn(['is_npc', 'npc_type']);
        });
    }
};
