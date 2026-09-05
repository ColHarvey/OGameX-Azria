<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quel etat de la bataille a deja ete annonce aux joueurs ?
 *
 * ## Pourquoi
 *
 * Un canal qui n'annoncerait que des pertes laisserait le debut d'une bataille — et sa fin, avec
 * son rapport — attendre le rafraichissement de secours. Le diffuseur compare donc l'etat courant a
 * celui qu'il a annonce en dernier, et envoie la difference a chaque partie.
 *
 * La colonne est ecrite **apres** l'envoi, comme `broadcast_at` sur les pertes : une annonce perdue
 * repart, et une annonce d'etat repetee est sans effet pour le navigateur, qui ne fait que relire.
 * Nulle au depart pour toute bataille existante : son etat courant sera annonce au premier passage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('combat_instances', function (Blueprint $table) {
            $table->string('broadcast_status', 20)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('combat_instances', function (Blueprint $table) {
            $table->dropColumn('broadcast_status');
        });
    }
};
