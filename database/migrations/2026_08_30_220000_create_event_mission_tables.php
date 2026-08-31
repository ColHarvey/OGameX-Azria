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
        // Tirage du jour, fige. Le tirage se derive certes de l'identifiant du joueur et de
        // la date, mais le conserver le rend immuable : modifier le catalogue en cours
        // d'evenement ne doit pas reecrire les missions des jours deja passes, sans quoi un
        // joueur verrait son historique changer sous ses yeux.
        Schema::create('event_mission_draws', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            // La date de debut identifie l'evenement. Deux evenements successifs peuvent
            // couvrir la meme date du calendrier : sans elle, leurs tirages se melangeraient.
            $table->date('event_start');
            $table->date('mission_date');

            // Les missions tirees, dans l'ordre, encodees en JSON : chaque entree porte
            // sa cle ET sa valeur en tritium. Figer la seule cle ne suffirait pas — le
            // rattrapage des jours passes crediterait alors un ancien jour au tarif du
            // catalogue actuel.
            $table->text('missions');

            $table->unique(['user_id', 'event_start', 'mission_date']);
        });

        // Missions creditees.
        Schema::create('event_mission_claims', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->date('event_start');
            $table->date('mission_date');
            $table->string('mission_key', 64);

            // Le tritium est fige au credit. Si le catalogue change en cours d'evenement,
            // les gains deja acquis ne sont pas recalcules.
            $table->unsignedInteger('tritium');
            $table->timestamp('claimed_at');

            // La garantie centrale du systeme : une mission ne peut etre creditee qu'une
            // fois par joueur, par evenement et par jour. La contrainte est en base et non
            // seulement dans le code, car le credit se declenche a l'affichage : deux
            // onglets ouverts en meme temps doivent en voir un seul aboutir.
            $table->unique(['user_id', 'event_start', 'mission_date', 'mission_key']);

            // Le total d'un joueur se calcule sur un evenement entier.
            $table->index(['user_id', 'event_start']);
        });

        // Rangs reclames. La date de debut fait partie de la cle : un nouvel evenement
        // rouvre les sept rangs sans qu'aucune ligne ne soit supprimee, et l'historique des
        // evenements passes reste consultable.
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
        Schema::dropIfExists('event_mission_draws');
    }
};
