<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * L identite durable d une patrouille (chantier « Patrouilles, stationnement attaquable et
 * surveillance », journal §114, revues 117 a 120 de Codex).
 *
 * ## Pourquoi une table a part, et non une mission de plus
 *
 * Une mission est un vol : un depart, une arrivee, un traitement. Une patrouille vit plus longtemps
 * que chacun de ses vols. Elle part, se pose, repart, se repose, rentre — et entre deux vols elle
 * possede un etat (posee ou immobilisee), une reserve de carburant qui se consomme au temps reel,
 * une version d ordre qui refuse les devis perimes, et une base d attache qui peut disparaitre.
 * Chaque vol reste une ligne de `fleet_missions` (genre 11) : c est elle qui porte les unites et la
 * cargaison, qui occupe le creneau de flotte, qui passe la porte des mouvements, qui s inscrit au
 * combat. Cette table-ci ne porte donc **jamais** une unite ni une cargaison : une seule propriete
 * a la fois, sur le segment courant.
 *
 * ## Le point courant
 *
 * `galaxy`, `system` et le couple (`x`, `y`) en unites spatiales de la geometrie de reference
 * (`SystemGeometry`) : le point ou la patrouille est posee. Nuls tant qu un segment vole — la
 * position d une flotte en vol se derive du segment, jamais d une colonne recopiee.
 *
 * ## La reserve
 *
 * `fuel_reserve` porte des fractions : le stationnement se facture au prorata de la seconde, et
 * `upkeep_paid_at` dit jusqu a quel instant il a ete paye. Aucune heure gratuite : un ordre ne
 * remet pas ce curseur a zero, il facture d abord ce qui est du.
 *
 * Purement additive ; inerte tant que `patrols_enabled` vaut 0.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('patrols', function (Blueprint $table) {
            $table->id();

            $table->integer('user_id', false, true);
            $table->foreign('user_id', 'patrols_user_fk')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();

            // La base d attache. Nulle si elle a disparu : la regle de repli (planete la plus proche
            // du proprietaire) se decide au moment de l ordre de retour, jamais recopiee ici.
            $table->integer('home_planet_id', false, true)->nullable();
            $table->foreign('home_planet_id', 'patrols_home_fk')
                ->references('id')
                ->on('planets')
                ->nullOnDelete();

            $table->string('state', 20);

            $table->integer('galaxy', false, true);
            $table->integer('system', false, true);
            $table->smallInteger('x')->nullable();
            $table->smallInteger('y')->nullable();

            // Le segment courant : l aller qui vole, ou la ligne posee qui porte les unites.
            $table->unsignedBigInteger('current_mission_id')->nullable();
            $table->foreign('current_mission_id', 'patrols_mission_fk')
                ->references('id')
                ->on('fleet_missions')
                ->nullOnDelete();

            $table->double('fuel_reserve')->default(0);
            $table->integer('upkeep_paid_at', false, true)->nullable();
            $table->integer('stationed_since', false, true)->nullable();

            // L entree dans le systeme courant : l horloge d acquisition des reseaux de surveillance
            // part d ici, et un deplacement a l interieur du systeme ne la relance pas.
            $table->integer('entered_system_at', false, true)->nullable();

            // Augmente a chaque ordre accepte. Un devis confirme avec une version anterieure est refuse.
            $table->integer('order_version', false, true)->default(1);

            $table->integer('finished_at', false, true)->nullable();
            $table->string('finish_reason', 40)->nullable();

            $table->timestamps();

            $table->index(['user_id', 'state'], 'patrols_owner_state_idx');
            $table->index(['galaxy', 'system', 'state'], 'patrols_system_state_idx');
            $table->unique('current_mission_id', 'patrols_mission_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patrols');
    }
};
