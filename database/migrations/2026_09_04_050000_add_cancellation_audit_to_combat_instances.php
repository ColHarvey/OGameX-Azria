<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * L'audit d'une annulation : qui, quand, pourquoi — et non seulement « annule ».
 *
 * La cause etait persistee ; rien d'autre. Une annulation qu'on ne retrouve pas apres coup n'est
 * pas une sortie d'exploitation, c'est une disparition : la note de l'administrateur et l'instant
 * de l'annulation rejoignent la cause sur l'instance, a cote de l'empreinte des faits geles que
 * cette annulation abandonne.
 *
 * Migration purement additive ; `down()` la defait entierement.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('combat_instances', function (Blueprint $table) {
            // Ce que l'administrateur a ecrit en annulant. Jamais vide pour une annulation.
            $table->text('cancellation_note')->nullable();

            // L'instant de l'annulation, tel que le service l'a recu : c'est de lui que les retours
            // partent, et c'est lui que l'audit relit.
            $table->integer('cancelled_at', false, true)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('combat_instances', function (Blueprint $table) {
            $table->dropColumn([
                'cancellation_note',
                'cancelled_at',
            ]);
        });
    }
};
