<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le bail du diffuseur continu : un seul diffuseur effectif, et une releve quand il meurt.
 *
 * ## Pourquoi un bail en base, et pas le verrou du planificateur
 *
 * L'entrypoint du serveur enchaine `schedule:run` puis `sleep 60` : la periode reelle d'un tick
 * vaut soixante secondes **plus** ce qui s'execute en ligne, et un tick peut sauter une minute
 * entiere. Un diffuseur borne a sa minute laisserait donc des creux d'une minute. Le diffuseur
 * tourne sans fin, et le planificateur ne fait que tenter d'en lancer un autre a chaque tick.
 *
 * Le bail tranche entre eux : une seule ligne, un detenteur, un battement. Un nouveau venu qui
 * trouve un battement recent s'efface ; un battement vieux de plus de quelques secondes vaut
 * mort, et le nouveau venu prend la releve. Le verrou `withoutOverlapping()` du planificateur ne
 * convient pas : son expiration est un delai fixe qui, trop court, lance deux diffuseurs, et trop
 * long, laisse une panne sans releve pendant des heures.
 *
 * MariaDB et SQLite executent tous deux la prise de bail comme une seule mise a jour
 * conditionnelle : c'est elle qui rend la prise atomique, pas une lecture suivie d'une ecriture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('combat_broadcaster_leases', function (Blueprint $table) {
            $table->string('name', 40)->primary();
            $table->string('holder', 80);
            $table->integer('heartbeat_at', false, true);
            $table->integer('started_at', false, true);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('combat_broadcaster_leases');
    }
};
