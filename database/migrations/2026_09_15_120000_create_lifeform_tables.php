<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Les tables des formes de vie (tranche 1, journal §155).
 *
 * ------------------------------------------------------------------------------------
 * PUREMENT ADDITIVE, ET INERTE
 *
 * Aucune table existante n est touchee. Rien n est ecrit ici tant que `lifeforms_enabled` vaut 0 et
 * qu aucun compte n a choisi son espece : un deploiement ne change rien au jeu.
 *
 * ------------------------------------------------------------------------------------
 * L ESPECE EST AU COMPTE, LA POPULATION A LA PLANETE
 *
 * Regle Azria (Keven, 15 septembre 2026) : un compte choisit son espece **une seule fois**, parmi
 * les quatre, et toutes ses planetes la portent — d ou `lifeform_accounts.user_id` unique et sans
 * colonne de changement. Chaque planete a sa propre population, sa nourriture et ses batiments ;
 * `lifeform_planets.species` recopie l espece du compte pour que les requetes par planete se
 * passent d une jointure.
 *
 * ------------------------------------------------------------------------------------
 * LES NIVEAUX SONT DES LIGNES, PAS DES COLONNES
 *
 * Les batiments classiques ont une colonne par batiment sur `planets` ; ici 48 batiments et 72
 * technologies auraient donne 120 colonnes. Une ligne (planete, objet, niveau) par niveau non nul,
 * unique par planete et objet, suffit : l absence de ligne vaut zero.
 *
 * ------------------------------------------------------------------------------------
 * UNE SEULE FILE, DEUX GENRES
 *
 * `lifeform_queues.kind` distingue batiment et technologie ; il y a un seul travail en cours par
 * planete et par genre, comme le jeu officiel. Le prix paye, l energie et la version du catalogue
 * sont figes au depart : un changement de vitesse ou de catalogue ne reecrit jamais un travail lance.
 *
 * ------------------------------------------------------------------------------------
 * LES REVISIONS DE REGLES DATENT CHAQUE CHANGEMENT DE VITESSE
 *
 * La demographie s integre par intervalles ou les taux sont constants ; un compte absent est avance
 * jusqu a chaque revision avec l ancienne vitesse, puis avec la nouvelle. Une ligne par
 * enregistrement de l administration qui change une vitesse (economie, recherche, coefficients des
 * formes de vie).
 *
 * `int unsigned` pour `planet_id` et `user_id` : `planets.id` et `users.id` sont declares par
 * `increments()`, et MariaDB refuse une cle etrangere dont les types different (errno 150) la ou
 * SQLite l accepte sans rien dire. Les index sont nommes court, pour la limite de 64 caracteres.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('lifeform_accounts', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id', false, true);
            $table->unsignedTinyInteger('species');
            $table->unsignedBigInteger('chosen_at');
            $table->unsignedInteger('artifacts')->default(0);
            $table->unsignedInteger('discoveries_available')->default(0);
            $table->unsignedBigInteger('discoveries_started_at')->nullable();
            $table->unsignedBigInteger('discoveries_credited_until')->nullable();
            $table->unsignedSmallInteger('welcome_dismissed_version')->default(0);
            $table->timestamps();

            $table->unique('user_id', 'lf_accounts_user_unique');
            $table->foreign('user_id', 'lf_accounts_user_fk')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::create('lifeform_species_progress', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id', false, true);
            $table->unsignedTinyInteger('species');
            $table->unsignedInteger('experience')->default(0);
            $table->unsignedBigInteger('discovered_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'species'], 'lf_species_progress_unique');
            $table->foreign('user_id', 'lf_species_progress_user_fk')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::create('lifeform_planets', function (Blueprint $table) {
            $table->id();
            $table->integer('planet_id', false, true);
            $table->unsignedTinyInteger('species');
            $table->double('population')->default(0);
            $table->double('food')->default(0);
            $table->unsignedBigInteger('calculated_at');
            $table->unsignedBigInteger('installed_at');
            $table->unsignedSmallInteger('rules_version');
            $table->timestamps();

            $table->unique('planet_id', 'lf_planets_planet_unique');
            $table->foreign('planet_id', 'lf_planets_planet_fk')->references('id')->on('planets')->onDelete('cascade');
        });

        Schema::create('lifeform_building_levels', function (Blueprint $table) {
            $table->id();
            $table->integer('planet_id', false, true);
            $table->unsignedInteger('object_id');
            $table->unsignedInteger('level')->default(0);
            $table->timestamps();

            $table->unique(['planet_id', 'object_id'], 'lf_building_levels_unique');
            $table->foreign('planet_id', 'lf_building_levels_planet_fk')->references('id')->on('planets')->onDelete('cascade');
        });

        Schema::create('lifeform_technology_levels', function (Blueprint $table) {
            $table->id();
            $table->integer('planet_id', false, true);
            $table->unsignedInteger('object_id');
            $table->unsignedInteger('level')->default(0);
            $table->timestamps();

            $table->unique(['planet_id', 'object_id'], 'lf_technology_levels_unique');
            $table->foreign('planet_id', 'lf_technology_levels_planet_fk')->references('id')->on('planets')->onDelete('cascade');
        });

        Schema::create('lifeform_slots', function (Blueprint $table) {
            $table->id();
            $table->integer('planet_id', false, true);
            $table->unsignedTinyInteger('slot');
            $table->unsignedInteger('object_id')->nullable();
            $table->unsignedBigInteger('selected_at')->nullable();
            $table->timestamps();

            $table->unique(['planet_id', 'slot'], 'lf_slots_unique');
            $table->foreign('planet_id', 'lf_slots_planet_fk')->references('id')->on('planets')->onDelete('cascade');
        });

        Schema::create('lifeform_queues', function (Blueprint $table) {
            $table->id();
            $table->integer('planet_id', false, true);
            $table->integer('user_id', false, true);
            // 'building' | 'technology'
            $table->string('kind', 12);
            $table->unsignedInteger('object_id');
            $table->unsignedInteger('target_level');
            $table->unsignedBigInteger('metal')->default(0);
            $table->unsignedBigInteger('crystal')->default(0);
            $table->unsignedBigInteger('deuterium')->default(0);
            $table->unsignedInteger('energy')->default(0);
            $table->unsignedBigInteger('time_start')->nullable();
            $table->unsignedBigInteger('time_end')->nullable();
            // 'waiting' | 'running' | 'done' | 'canceled'
            $table->string('status', 12)->default('waiting');
            $table->unsignedSmallInteger('catalogue_version');
            $table->timestamps();

            $table->index(['planet_id', 'kind', 'status'], 'lf_queues_planet_kind_status_idx');
            $table->index('time_end', 'lf_queues_time_end_idx');
            $table->foreign('planet_id', 'lf_queues_planet_fk')->references('id')->on('planets')->onDelete('cascade');
        });

        Schema::create('lifeform_rule_revisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('effective_at');
            $table->double('economy_speed');
            $table->double('research_speed');
            $table->double('build_multiplier');
            $table->double('research_multiplier');
            $table->double('discovery_multiplier');
            $table->integer('changed_by', false, true)->nullable();
            $table->string('note', 120)->nullable();
            $table->timestamps();

            $table->index('effective_at', 'lf_rule_revisions_effective_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lifeform_rule_revisions');
        Schema::dropIfExists('lifeform_queues');
        Schema::dropIfExists('lifeform_slots');
        Schema::dropIfExists('lifeform_technology_levels');
        Schema::dropIfExists('lifeform_building_levels');
        Schema::dropIfExists('lifeform_planets');
        Schema::dropIfExists('lifeform_species_progress');
        Schema::dropIfExists('lifeform_accounts');
    }
};
