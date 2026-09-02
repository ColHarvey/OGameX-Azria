<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Une bataille qui dure.
 *
 * Jusqu'ici, un combat n'existait pas : il se resolvait a l'instant ou la flotte arrivait, et
 * il ne restait qu'un rapport. Cette table lui donne une existence dans le temps — un debut,
 * une fin, et un etat entre les deux.
 *
 * **Le resultat est calcule a l'arrivee et conserve ici sans etre applique.** C'est la
 * garantie centrale du systeme : recalculer a la fin laisserait le defenseur changer
 * retroactivement l'issue d'une bataille deja engagee, en envoyant sa flotte ou en recevant des
 * renforts. Le resultat est donc fige des le premier instant, et cache jusqu'au dernier.
 *
 * **Le modele de duree est conserve avec chaque combat**, pas seulement lu dans les reglages.
 * Rythme, amortissement et plancher sont ecrits ici au demarrage : ajuster le reglage plus tard
 * ne doit toucher que les combats suivants. Un combat garde le modele sous lequel il a commence,
 * sans quoi une bataille de deux heures pourrait s'allonger ou se raccourcir en cours de route.
 *
 * **Les coordonnees sont conservees en plus de l'identifiant du corps celeste.** Une planete
 * peut etre detruite, abandonnee ou deplacee ; l'identifiant ne dirait alors plus ou la bataille
 * a eu lieu. Les coordonnees, elles, restent lisibles quoi qu'il arrive.
 *
 * Migration purement additive : rien d'existant n'est modifie, et `down()` la defait entierement.
 * Le systeme reste inerte tant que le reglage `combat_duration_enabled` vaut 0 — cette table
 * peut donc exister en production sans qu'aucun combat ne l'utilise.
 *
 * Les index sont nommes explicitement et courts : MariaDB refuse un identifiant de plus de
 * 64 caracteres la ou SQLite l'accepte, ce qui a deja fait echouer une migration en production
 * alors que la suite passait en local.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('combat_instances', function (Blueprint $table) {
            $table->id();

            // pending / active / resolving / resolved / cancelled — voir CombatState.
            $table->string('status', 16)->default('pending');

            // La cause d'annulation, quand il y en a une. Aucune n'est a la main d'un joueur.
            $table->string('cancellation_cause', 32)->nullable();

            // La mission initiatrice. Pour une union ACS, c'est celle du createur : un seul
            // combat par union, les autres flottes sont des participants.
            //
            // `bigint`, parce que `fleet_missions.id` et `fleet_unions.id` en sont : un `int`
            // aurait suffi en pratique, mais il aurait empeche d'ajouter un jour la cle
            // etrangere — MariaDB exige que les types correspondent exactement. SQLite, lui,
            // aurait accepte les deux sans rien dire.
            $table->unsignedBigInteger('mission_id');
            $table->unsignedBigInteger('union_id')->nullable();

            // Le corps celeste vise. L'identifiant sert aux jointures ; les coordonnees et le
            // type disent ou la bataille a eu lieu meme si la planete disparait ensuite.
            $table->integer('target_planet_id', false, true)->nullable();
            $table->unsignedTinyInteger('target_type');
            $table->integer('galaxy');
            $table->integer('system');
            $table->integer('position');

            // Le calendrier. `duration_seconds` est conserve tel qu'il a ete calcule : le
            // deduire de `ends_at - started_at` le rendrait sensible a toute correction d'horloge.
            $table->integer('started_at', false, true)->nullable();
            $table->integer('ends_at', false, true)->nullable();
            $table->integer('duration_seconds', false, true)->default(0);

            // Le modele qui a produit cette duree, fige avec elle.
            $table->double('duration_rate')->default(0);
            $table->double('duration_damping')->default(1);
            $table->integer('duration_minimum_seconds', false, true)->default(0);

            // Vrai si la duree calculee depassait le seuil d'alerte technique. Aucune duree
            // n'est jamais rabotee : ce drapeau existe pour qu'un rythme mal calibre se voie.
            $table->boolean('duration_implausible')->default(false);

            // Debut, fin et intensite de chaque round.
            $table->json('round_schedule')->nullable();

            // L'etat des deux camps au moment de l'arrivee, et le resultat calcule sur lui.
            // `battle_result` n'est jamais expose au joueur avant la resolution.
            $table->json('battle_snapshot')->nullable();
            $table->json('battle_result')->nullable();

            // Rempli a la resolution seulement. `bigint` comme `battle_reports.id`.
            $table->unsignedBigInteger('battle_report_id')->nullable();

            $table->timestamps();

            // Retrouver les combats a demarrer ou a resoudre : c'est la requete de la tache de
            // reconciliation, qui tourne regulierement.
            $table->index(['status', 'ends_at'], 'combat_status_ends_idx');

            // Le verrou : « ce corps celeste est-il en combat ? ». Requete la plus frequente du
            // systeme, appelee par chaque validation de depart.
            $table->index(['target_planet_id', 'status'], 'combat_target_status_idx');
            $table->index(['galaxy', 'system', 'position', 'status'], 'combat_coords_status_idx');

            $table->index('mission_id', 'combat_mission_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('combat_instances');
    }
};
