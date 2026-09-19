<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La trace d une colonie de formes de vie supprimee definitivement (journal §167).
 *
 * La purge de 3 h efface en cascade tout ce qu une colonie portait — niveaux, emplacements, historique des
 * emplacements, file. Sans trace, une lecture des bonus a un instant ou elle existait encore rendait **en silence**
 * un bonus reduit. La trace dit seulement : « ce compte avait cette colonie de A a B, et ses faits ont ete purges ».
 * Le resolveur en deduit qu il ne peut plus reconstruire ce passe, et le dit (`LifeformHistoryUnavailable`).
 *
 * **Aucune cle etrangere, a dessein.** La trace doit survivre a la suppression du corps qu elle decrit — c est sa
 * raison d etre — et garder le proprietaire historique meme si le compte change ensuite. Une cascade l effacerait
 * exactement quand elle sert.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('lifeform_purged_bodies', function (Blueprint $table) {
            $table->id();
            // Le proprietaire historique du corps, au moment de sa suppression.
            $table->integer('user_id', false, true);
            // L identifiant du corps disparu : plus aucune ligne de `planets` ne le porte.
            $table->integer('planet_id', false, true);
            // A : debut de l existence, lu sur `planets.created_at`. Nul quand la date manque — jamais inventee.
            $table->unsignedBigInteger('existed_from')->nullable();
            // B : l instant ou le corps a cesse de compter — l abandon, ou la suppression directe sans abandon.
            $table->unsignedBigInteger('existed_until');
            // L instant de la suppression definitive, pour l exploitation : la lecture ne s en sert pas.
            $table->unsignedBigInteger('purged_at');
            $table->timestamps();

            $table->index(['user_id', 'existed_until'], 'lf_purged_user_until_idx');
            // Un index, pas une unicite : SQLite reutilise l identifiant d une ligne supprimee (le depot l a paye sur les
            // missions), et une seconde purge du meme identifiant ferait echouer la transaction de suppression.
            $table->index('planet_id', 'lf_purged_planet_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lifeform_purged_bodies');
    }
};
