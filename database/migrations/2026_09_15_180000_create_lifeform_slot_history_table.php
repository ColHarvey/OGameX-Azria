<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * L historique d occupation des emplacements de recherche (revue de Codex, journal §155.10).
 *
 * ## Pourquoi une table, et non trois colonnes de plus
 *
 * Le gel d un combat demande ce que la planete portait **a un instant** : un travailleur traite une
 * arrivee bien apres l avoir datee, et ce que le joueur change entre les deux ne doit ni armer ni
 * desarmer cette flotte. Les niveaux se ramenent deja par la file des travaux
 * (`LifeformLevels::levelsAt()`) ; l occupation des emplacements, elle, ne se deduisait pas.
 *
 * Les colonnes de `lifeform_slots` (`object_id`, `previous_object_id`, `selected_at`, `reset_at`) ne
 * suffisent pas : apres une remise a zero suivie d un nouveau choix, `selected_at` decrit la nouvelle
 * technologie et l instant du choix de l ancienne est perdu. Une reconstruction par cas serait fausse
 * dans ce scenario-la, atteignable en une minute. Une ligne par changement, elle, est exacte par
 * construction : l occupation a un instant est la derniere ligne dont `from_at` le precede.
 *
 * `object_id` nul dit « l emplacement etait vide » — une remise a zero en ecrit une.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('lifeform_slot_history', function (Blueprint $table) {
            $table->id();
            $table->integer('planet_id', false, true);
            $table->unsignedTinyInteger('slot');
            $table->unsignedInteger('object_id')->nullable();
            $table->unsignedBigInteger('from_at');
            $table->timestamps();

            $table->index(['planet_id', 'from_at'], 'lf_slot_hist_planete');
            $table->index(['planet_id', 'slot', 'from_at'], 'lf_slot_hist_emplacement');
            $table->foreign('planet_id', 'lf_slot_hist_planet_fk')->references('id')->on('planets')->onDelete('cascade');
        });

        // Les emplacements deja occupes recoivent leur premiere ligne, datee de leur choix : sans elle,
        // un compte existant paraitrait n avoir jamais rien pose. La branche n a jamais tourne en
        // production, donc cette reprise ne concerne qu une base de developpement.
        $maintenant = now();
        foreach (DB::table('lifeform_slots')->whereNotNull('object_id')->get() as $ligne) {
            DB::table('lifeform_slot_history')->insert([
                'planet_id' => $ligne->planet_id,
                'slot' => $ligne->slot,
                'object_id' => $ligne->object_id,
                'from_at' => (int)($ligne->selected_at ?? 0),
                'created_at' => $maintenant,
                'updated_at' => $maintenant,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lifeform_slot_history');
    }
};
