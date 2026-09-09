<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Un contact : ce qu un Reseau de surveillance sait d une patrouille etrangere dans son systeme.
 *
 * ## Derive des faits, et rejouable
 *
 * Une ligne nait quand une patrouille entre dans le systeme d une planete equipee, avec l instant
 * d entree (`entered_system_at`) et l instant a partir duquel le contact est visible
 * (`visible_from` = entree + delai du niveau du reseau a ce moment). Un deplacement a l interieur
 * du systeme ne cree ni ne relance rien : la revue 120 interdit qu une acquisition de quinze
 * minutes soit remise a zero par de petits sauts. La ligne est revoquee (`revoked_at`) quand la
 * patrouille quitte le systeme, est detruite, rentre, ou quand le reseau est demoli ou la planete
 * perdue. Un retour dans le systeme cree une nouvelle ligne : nouvelle acquisition.
 *
 * ## Ce que la ligne ne porte pas
 *
 * Ni composition, ni cargaison, ni carburant, ni niveau d information : le niveau se lit sur la
 * planete observatrice au moment de la lecture, et le lecteur ne projette que ce que ce niveau
 * autorise. Masquer cote client des donnees deja envoyees n est pas une protection.
 *
 * Purement additive ; inerte tant que `patrols_enabled` vaut 0.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('surveillance_contacts', function (Blueprint $table) {
            $table->id();

            $table->integer('observer_planet_id', false, true);
            $table->foreign('observer_planet_id', 'contacts_planet_fk')
                ->references('id')
                ->on('planets')
                ->cascadeOnDelete();

            // Le proprietaire de la planete observatrice au moment de l acquisition : c est lui que
            // le canal prive sert. Une planete qui change de main revoque ses contacts.
            $table->integer('observer_user_id', false, true);

            $table->unsignedBigInteger('patrol_id');
            $table->foreign('patrol_id', 'contacts_patrol_fk')
                ->references('id')
                ->on('patrols')
                ->cascadeOnDelete();

            $table->integer('entered_system_at', false, true);

            // **L instant ou l acquisition commence, qui n est pas toujours l entree.** Un reseau
            // construit apres l arrivee d une patrouille ne peut pas avoir observe ce qui l a
            // precede : son acquisition part de sa mise en service. Une amelioration, elle, ne
            // relance rien — elle raccourcit le delai applique a ce meme depart, et peut donc
            // reveler aussitot. Les deux regles tiennent par cette colonne.
            $table->integer('acquisition_from', false, true);
            $table->integer('visible_from', false, true);
            $table->integer('revoked_at', false, true)->nullable();

            $table->timestamps();

            $table->index(['observer_user_id', 'revoked_at'], 'contacts_observer_idx');
            $table->index(['patrol_id', 'revoked_at'], 'contacts_patrol_idx');
            $table->index(['observer_planet_id', 'revoked_at'], 'contacts_planet_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('surveillance_contacts');
    }
};
