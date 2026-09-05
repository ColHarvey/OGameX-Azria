<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L'etat protege de l'ouverture, conserve durablement sur l'instance.
 *
 * ## Pourquoi il est persiste, et pas relu
 *
 * La photographie d'un combat se construit a partir de l'etat du corps **a l'ouverture** — ses
 * ressources, ce que cet etat reflete deja (la provenance, effet par effet) — puis des seuls
 * effets admissibles, dans l'ordre versionne. Relire le corps a la fermeture donnerait le monde
 * vivant : la production, les livraisons decidees apres l'ouverture, tout ce qui ne doit pas
 * entrer dans ce combat. Et un etat qui ne survivrait pas a la transaction d'ouverture ne pourrait
 * pas etre rejoue par un travailleur en retard.
 *
 * `opening_state` porte le document (version, instant, ressources, provenance) ;
 * `opening_state_fingerprint` son empreinte, pour qu'une relecture constate une divergence ;
 * `opening_captured_at` l'instant de la capture, qui est celui de l'ouverture.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('combat_instances', function (Blueprint $table) {
            $table->json('opening_state')->nullable()->after('frozen_facts_fingerprint');
            $table->string('opening_state_fingerprint', 128)->nullable()->after('opening_state');
            $table->integer('opening_captured_at', false, true)->nullable()->after('opening_state_fingerprint');
        });
    }

    public function down(): void
    {
        Schema::table('combat_instances', function (Blueprint $table) {
            $table->dropColumn(['opening_state', 'opening_state_fingerprint', 'opening_captured_at']);
        });
    }
};
