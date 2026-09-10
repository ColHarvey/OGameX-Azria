<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Un ordre de reparation de survivants au chantier spatial.
 *
 * ------------------------------------------------------------------------------------
 * CE QUE CETTE TABLE N EST PAS
 *
 * **Ce n est pas le champ d epaves.** Le dock rend deux services que la consigne demande de garder
 * « clairement separes » : (A) recuperer des epaves — gratuit, inchange, dans `wreck_fields` ; (B)
 * reparer des survivants endommages — payant, ici. Les deux ont leur propre emplacement et ne se
 * bloquent pas l un l autre : bloquer les epaves aurait modifie un systeme que la consigne interdit
 * de toucher.
 *
 * ------------------------------------------------------------------------------------
 * `active_on_planet_id` EST LE VERROU, ET LA BASE LE TIENT
 *
 * Decision 6 du 10 septembre : **un ordre de survivants a la fois par planete**. Ce n est pas une
 * verification applicative — deux requetes simultanees la passeraient toutes les deux. C est une
 * colonne **unique**, posee a l identifiant de la planete tant que l ordre court et remise a `null`
 * a sa terminaison : la base refuse alors physiquement le second ordre.
 *
 * C est le motif deja employe par `patrol_combat_barriers.patrol_id` et
 * `celestial_body_combat_barriers.target_body_id`. MariaDB n a pas d index partiel ; une colonne
 * nullable unique en tient lieu, plusieurs `null` ne se genant pas entre eux.
 *
 * ------------------------------------------------------------------------------------
 * TOUT CE QUI DECIDE EST GELE ICI
 *
 * `dock_level`, le cout paye et les deux instants sont **figes a la confirmation**. Une amelioration
 * du dock pendant un ordre ne change donc rien a cet ordre (decision 7) : il n y a pas de valeur
 * vivante a relire, et le devis ne peut pas deriver entre l affichage et le paiement.
 *
 * `units` porte les unites **avec leur niveau de degats de depart** : `{type: {degats: nombre}}`.
 * C est ce qui permet de calculer la coque du moment sans rien relire, et de rendre exactement ce
 * qui avait ete confie.
 *
 * ------------------------------------------------------------------------------------
 * LA PROGRESSION NE S ECRIT JAMAIS
 *
 * Decision 5 : la reparation est **progressive, interpolee dans le temps et calculee a la lecture**.
 * Il n y a donc ni colonne d avancement, ni travailleur qui la mette a jour : la part reparee a
 * l instant t est une **fonction pure** de `started_at`, `completed_at` et t. Relire deux fois donne
 * deux fois la meme valeur — l idempotence est structurelle, pas surveillee.
 *
 * `settled_at` marque le seul effet qui, lui, ne doit avoir lieu qu une fois : rendre les unites.
 *
 * Inerte tant que `hull_damage_enabled` vaut 0.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('hull_repair_orders', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('planet_id');
            $table->unsignedBigInteger('player_id');

            // Le verrou : un seul ordre actif par planete, tenu par la base elle-meme.
            $table->unsignedBigInteger('active_on_planet_id')->nullable()->unique();

            // Les unites confiees, avec leur niveau de degats de depart.
            $table->json('units');

            // Le devis, gele a la confirmation.
            $table->unsignedBigInteger('cost_metal')->default(0);
            $table->unsignedBigInteger('cost_crystal')->default(0);
            $table->unsignedBigInteger('cost_deuterium')->default(0);
            $table->unsignedInteger('dock_level');

            $table->unsignedBigInteger('started_at');
            $table->unsignedBigInteger('completed_at');

            // 'repairing' | 'settled' | 'cancelled'
            $table->string('status', 20)->default('repairing');

            // Pose une seule fois, dans la transaction qui rend les unites.
            $table->unsignedBigInteger('settled_at')->nullable();

            // La raison d une fin anticipee : 'player', 'dock_lost', 'combat'.
            $table->string('ended_because', 20)->nullable();

            $table->timestamps();

            $table->foreign('planet_id')->references('id')->on('planets')->onDelete('cascade');
            $table->index(['planet_id', 'status']);
            $table->index('completed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hull_repair_orders');
    }
};
