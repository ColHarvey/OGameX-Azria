<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Les debris d un combat en espace libre, a l adresse ou il a eu lieu.
 *
 * La revue 120 de Codex l exige : un combat autour d une patrouille laisse ses debris **a son
 * point**, jamais fondus dans le champ de la position planetaire voisine (`debris_fields`, cle
 * galaxie/systeme/position), et jamais soumis aux regles de la position 16. L adresse est donc
 * celle de la geometrie de reference : galaxie, systeme, `x`, `y` en unites spatiales, sur la
 * grille. Deux combats au meme point s additionnent ; deux points voisins restent deux champs.
 *
 * Les colonnes de ressources sont des `double`, comme `debris_fields` depuis sa conversion : un
 * champ peut recevoir des fractions de recyclage.
 *
 * Purement additive ; inerte tant que `patrols_enabled` vaut 0.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('space_debris_fields', function (Blueprint $table) {
            $table->id();

            $table->integer('galaxy', false, true);
            $table->integer('system', false, true);
            $table->smallInteger('x');
            $table->smallInteger('y');

            $table->double('metal')->default(0);
            $table->double('crystal')->default(0);
            $table->double('deuterium')->default(0);

            $table->timestamps();

            $table->unique(['galaxy', 'system', 'x', 'y'], 'space_debris_point_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('space_debris_fields');
    }
};
