<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les caracteristiques de combat de chaque participant, gelees a son entree, et la regle qui dit
 * comment une bataille les lit.
 *
 * ## Pourquoi une regle versionnee
 *
 * Depuis la decision de Keven du 12 septembre 2026, le bonus de combat des classes arme les tirs, et
 * les caracteristiques d un combat durable se gelent **a l arrivee de chaque flotte**. Un combat deja
 * ouvert au moment du deploiement a ete engage sous l ancienne regle : ses tirs lisaient les niveaux
 * vivants a la cloture, sans bonus. Il la garde. La colonne le dit, et la cloture la lit.
 *
 * ## Pourquoi une colonne a part, et pas une sixieme version dans l ensemble gele
 *
 * `FrozenCombatVersionSet` entre dans l empreinte des faits et dans l identite du resultat, avec des
 * clefs strictes : une sixieme clef rendrait illisibles les resultats deja figes. Cette regle ne
 * gouverne que la **composition** des unites a la cloture ; une fois la bataille calculee, le resultat
 * porte des nombres et n a plus besoin d elle.
 *
 * ## Tous les combats existants naissent sous la premiere regle
 *
 * La colonne est remplie a `v1` pour chaque ligne presente. Un combat ecrit apres le deploiement est
 * ouvert en `v2` par `CombatOpeningService`. Une colonne restee vide est refusee a la lecture — jamais
 * interpretee.
 *
 * ## Une ligne par participant, ecrite une fois
 *
 * La clef est celle de l inscription (`fleet:<id>`), unique dans un combat. La mission peut disparaitre :
 * le lien devient nul, le fait gele reste, comme pour `combat_participants`.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('combat_instances', function (Blueprint $table): void {
            $table->string('unit_characteristics_version', 16)->nullable()->after('presentation_version');
        });

        DB::table('combat_instances')
            ->whereNull('unit_characteristics_version')
            ->update(['unit_characteristics_version' => 'v1']);

        Schema::create('combat_entry_characteristics', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('combat_instance_id');
            $table->foreign('combat_instance_id', 'combat_entry_char_instance_fk')
                ->references('id')
                ->on('combat_instances')
                ->restrictOnDelete();

            $table->unsignedBigInteger('fleet_mission_id')->nullable();
            $table->foreign('fleet_mission_id', 'combat_entry_char_mission_fk')
                ->references('id')
                ->on('fleet_missions')
                ->nullOnDelete();

            $table->string('participant_key', 32);
            $table->integer('player_id', false, true);

            $table->unsignedInteger('weapon_level');
            $table->unsignedInteger('shield_level');
            $table->unsignedInteger('armor_level');
            $table->unsignedTinyInteger('class_combat_bonus');

            // L instant d admission que les regles fixent : l arrivee physique, ou l ouverture pour un
            // renfort deja pose. Jamais l instant ou une page ou un travailleur a traite la flotte.
            $table->unsignedInteger('entered_at');

            $table->timestamps();

            $table->unique(['combat_instance_id', 'participant_key'], 'combat_entry_char_unique_idx');
            $table->index('fleet_mission_id', 'combat_entry_char_mission_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('combat_entry_characteristics');

        Schema::table('combat_instances', function (Blueprint $table): void {
            $table->dropColumn('unit_characteristics_version');
        });
    }
};
