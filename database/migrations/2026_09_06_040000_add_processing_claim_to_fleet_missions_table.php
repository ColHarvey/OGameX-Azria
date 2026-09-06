<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * La reservation qui interdit de traiter deux fois la meme mission.
 *
 * `processed` ne protegeait rien tout seul : le controle etait un « je lis, puis j agis ». Le
 * planificateur et le chargement de page d un joueur pouvaient le lire nul tous les deux, passer
 * tous les deux, et livrer deux fois la meme arrivee ou le meme retour — vaisseaux, cargaison et
 * points d honneur credites en double. Le defaut s est vu en production : un joueur a recu deux
 * fois le message « Retour d une flotte ».
 *
 * Cette colonne porte le jeton. Une seule ecriture conditionnelle le pose, donc c est la base qui
 * arbitre : le second appelant compte zero ligne et repart.
 *
 * **Elle est datee, pas booleenne**, parce qu un processus tue ne libere rien. Un jeton plus vieux
 * que le delai de reprise se reprend, comme le fait deja le bail du diffuseur de combat.
 *
 * Purement additive : la colonne est nulle partout, et une ligne dont le jeton est nul se comporte
 * exactement comme avant.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('fleet_missions', function (Blueprint $table) {
            $table->timestamp('processing_claimed_at')->nullable()->after('processed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fleet_missions', function (Blueprint $table) {
            $table->dropColumn('processing_claimed_at');
        });
    }
};
