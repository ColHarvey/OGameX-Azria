<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Schema;
use OGame\History\ClassHistoryBaseline;

/**
 * Les historiques de la classe personnelle, de l appartenance a une alliance et de la classe d une
 * alliance.
 *
 * ## Pourquoi
 *
 * Une flotte gele a son admission dans un combat durable le bonus de combat de ses classes (decision de
 * Keven, 12 septembre 2026, precisee le 13). Le jeu ne gardait que le **dernier** instant de changement
 * — `character_class_changed_at`, `joined_at`, `alliance_left_at`, `alliance_class_selected_at` —, jamais
 * la valeur d avant : un travailleur en retard lisait la classe du moment du traitement.
 *
 * ## Ajout seul, sans cle etrangere
 *
 * Une ligne ne se modifie ni ne se supprime : un compte, une alliance peuvent disparaitre, ce qu ils
 * etaient a un instant passe reste vrai. Chaque changement et sa ligne s ecrivent dans la meme
 * transaction.
 *
 * ## La ligne de base, et ce qu elle ne pretend pas
 *
 * La migration inscrit l etat present de chaque compte et de chaque alliance, **date de son propre
 * instant**. Elle n invente rien d anterieur : avant cet instant, l historique est inconnu, et un combat
 * ouvert a cet instant ou avant garde la premiere regle (`UnitCharacteristicsRule`). Chaque compte cree
 * ensuite porte sa ligne de creation.
 *
 * La base des essais n est pas vide : `add_roles` y insere le compte Legor, qui recoit donc une ligne de
 * base datee de l heure reelle de la migration — bien apres le 1er janvier 2024 ou le banc arrete son
 * horloge. Sans correction, **tout** combat du banc s ouvrirait avant la ligne de base, donc sous la
 * premiere regle ; `AccountTestCase` etablit donc le monde que le banc exige, un serveur migre avant son
 * jour.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('character_class_history', function (Blueprint $table): void {
            $table->id();
            $table->integer('user_id', false, true);
            $table->unsignedTinyInteger('character_class')->nullable();
            $table->unsignedInteger('changed_at');
            $table->string('cause', 24);

            $table->index(['user_id', 'changed_at', 'id'], 'class_hist_user_idx');
            $table->index(['cause', 'changed_at'], 'class_hist_cause_idx');
        });

        Schema::create('alliance_membership_history', function (Blueprint $table): void {
            $table->id();
            $table->integer('user_id', false, true);
            $table->unsignedBigInteger('alliance_id')->nullable();
            $table->unsignedInteger('changed_at');
            $table->string('cause', 24);

            $table->index(['user_id', 'changed_at', 'id'], 'member_hist_user_idx');
        });

        Schema::create('alliance_class_history', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('alliance_id');
            $table->string('alliance_class', 20)->nullable();
            $table->unsignedInteger('changed_at');
            $table->string('cause', 24);

            $table->index(['alliance_id', 'changed_at', 'id'], 'ally_class_hist_idx');
        });

        (new ClassHistoryBaseline())->writeAt((int)Date::now()->timestamp);
    }

    public function down(): void
    {
        Schema::dropIfExists('alliance_class_history');
        Schema::dropIfExists('alliance_membership_history');
        Schema::dropIfExists('character_class_history');
    }
};
