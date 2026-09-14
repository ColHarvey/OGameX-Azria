<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le classement par points d'honneur.
 *
 * ## Pourquoi deux tables
 *
 * `GenerateHighscoreRanks` parcourt **toutes** les valeurs du type de classement et écrit
 * `<type>_rank` pour les joueurs **et** pour les alliances. Ajouter une valeur sans sa colonne
 * des deux côtés ferait tomber la tâche planifiée au premier passage.
 *
 * ## Pourquoi une colonne, alors que la valeur vit déjà sur le joueur
 *
 * `users.honor_points` porte la valeur courante ; le classement, lui, est une photographie
 * régénérée périodiquement, avec son rang. Lire le joueur vivant à l'affichage donnerait un
 * classement qui bouge pendant qu'on le lit, et un rang qui ne correspondrait à rien.
 *
 * L'honneur peut être **négatif** — c'est le sens même de la mécanique : un combat déshonorant
 * en retire. La colonne est donc signée, contrairement aux autres scores.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('highscores', function (Blueprint $table) {
            $table->bigInteger('honor')->default(0)->after('military');
            $table->bigInteger('honor_rank')->nullable()->default(null)->after('military_rank');
            $table->index('honor');
        });

        Schema::table('alliance_highscores', function (Blueprint $table) {
            $table->bigInteger('honor')->default(0)->after('military');
            $table->integer('honor_rank')->nullable()->after('military_rank');
            $table->index('honor');
        });
    }

    public function down(): void
    {
        Schema::table('highscores', function (Blueprint $table) {
            $table->dropIndex(['honor']);
            $table->dropColumn(['honor', 'honor_rank']);
        });

        Schema::table('alliance_highscores', function (Blueprint $table) {
            $table->dropIndex(['honor']);
            $table->dropColumn(['honor', 'honor_rank']);
        });
    }
};
