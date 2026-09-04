<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * La suppression d'un compte devient un etat, au lieu d'etre un instant.
 *
 * ## Pourquoi une attente, et pas un refus
 *
 * Un compte qui **renforce** le combat d'un autre joueur ne peut pas disparaitre tout de suite :
 * retirer son renfort changerait une issue deja gelee, et annuler la bataille entiere changerait ce
 * que voient plusieurs tiers. Regle arretee par Keven : le compte passe **en suppression en
 * attente**, ne lance plus rien, et sa suppression reprend d'elle-meme des que les combats qu'il
 * renforce sont finaux. Aucun combat d'un tiers n'est annule, aucun resultat n'est recalcule.
 *
 * L'attente doit se voir — d'ou la raison persistee a cote de l'instant : un administrateur qui
 * cherche pourquoi un compte est toujours la lit la ligne, au lieu de relire un journal.
 *
 * Cet etat ferme aussi une course. Sans lui, un combat pouvait s'ouvrir entre l'inventaire des
 * combats du compte et l'effacement de ses lignes, et ce combat-la n'aurait ete annule par
 * personne. Le drapeau est pose **avant** l'inventaire, et le lancement de flotte le lit.
 *
 * Migration purement additive ; `down()` la defait entierement.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // L'instant ou la suppression a ete demandee. Non nul = le compte ne lance plus rien.
            $table->integer('deletion_pending_since', false, true)->nullable();

            // Ce qui retient la suppression, en clair, pour qui la cherche.
            $table->text('deletion_deferred_reason')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'deletion_pending_since',
                'deletion_deferred_reason',
            ]);
        });
    }
};
