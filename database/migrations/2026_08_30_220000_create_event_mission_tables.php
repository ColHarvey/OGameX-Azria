<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Missions reclamees. La date est stockee plutot qu'un numero de jour : les missions
        // tirees dependent de la date, et un evenement dont l'administrateur deplace les
        // bornes ne doit pas reattribuer les reclamations deja faites a d'autres missions.
        Schema::create('event_mission_claims', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->date('mission_date');
            $table->string('mission_key', 64);

            // Le tritium est fige a la reclamation. Si le catalogue change en cours
            // d'evenement, les gains deja acquis ne sont pas recalcules.
            $table->unsignedInteger('tritium');
            $table->timestamp('claimed_at');

            // Une mission ne peut etre reclamee qu'une fois par joueur et par jour. La
            // contrainte est en base et non seulement dans le code : deux onglets ouverts
            // ne peuvent donc pas crediter deux fois le meme gain.
            $table->unique(['user_id', 'mission_date', 'mission_key']);

            // Le total de tritium d'un joueur se calcule sur une plage de dates.
            $table->index(['user_id', 'mission_date']);
        });

        // Rangs reclames. La date de debut de l'evenement fait partie de la cle : un nouvel
        // evenement rouvre donc les cinq rangs sans qu'aucune ligne ne soit supprimee, et
        // l'historique des evenements passes reste consultable.
        Schema::create('event_rank_claims', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->date('event_start');
            $table->unsignedTinyInteger('rank');
            $table->string('reward_key', 64);
            $table->timestamp('claimed_at');

            $table->unique(['user_id', 'event_start', 'rank']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_rank_claims');
        Schema::dropIfExists('event_mission_claims');
    }
};
