<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Les cotes d artefacts des vols de decouverte deviennent un reglage d administration (journal §159).
 *
 * Deux choses a garder pour que le reglage soit auditable et qu un vol lance garde ses regles :
 *
 * - `lifeform_discoveries.odds` : la photographie des cotes sous lesquelles **ce vol** a ete tire
 *   (chance, trois trouvailles, repartition). Nulle pour les vols anciens, tires sous les valeurs de
 *   depart : rien n est reecrit. Le reglement ne la relit pas — il credite l issue scellee —, elle dit
 *   seulement d ou vient l issue.
 * - `lifeform_discovery_odds_revisions` : chaque enregistrement d administration qui change une cote,
 *   date, avec son auteur, sur le patron de `lifeform_rule_revisions`. Les vitesses gardent leur table :
 *   une cote n est pas une vitesse, et la demographie n a pas a couper un intervalle pour elle.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('lifeform_discoveries', function (Blueprint $table) {
            $table->json('odds')->nullable()->after('rules_version');
        });

        Schema::create('lifeform_discovery_odds_revisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('effective_at');
            $table->unsignedSmallInteger('artifact_chance');
            $table->unsignedSmallInteger('small');
            $table->unsignedSmallInteger('medium');
            $table->unsignedSmallInteger('large');
            $table->unsignedSmallInteger('medium_chance');
            $table->unsignedSmallInteger('large_chance');
            $table->integer('changed_by', false, true)->nullable();
            $table->string('note', 120)->nullable();
            $table->timestamps();

            $table->index('effective_at', 'lf_odds_revisions_effective_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lifeform_discovery_odds_revisions');
        Schema::table('lifeform_discoveries', function (Blueprint $table) {
            $table->dropColumn('odds');
        });
    }
};
