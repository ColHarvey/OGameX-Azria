<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La classe d'une alliance, et l'instant ou elle l'a prise.
 *
 * ## Pourquoi deux colonnes et pas une
 *
 * La classe seule dirait ce que l'alliance est ; l'instant dit **depuis quand**. Il sert au joueur
 * (la page peut afficher la date), a l'exploitation (un remboursement se juge sur une date) et a
 * toute regle future de delai entre deux changements. Une colonne qu'on n'ecrit pas ne coute rien ;
 * une date qu'on n'a pas ne se retrouve jamais.
 *
 * ## Nulle par defaut, et c'est l'etat de toutes les alliances existantes
 *
 * Aucune alliance n'a de classe aujourd'hui — la page etait un decor. La colonne naît donc nulle
 * partout, ce qui est exactement l'etat du serveur, et aucune alliance ne gagne de bonus du seul
 * fait de cette migration.
 *
 * ## Une chaine, pas un entier
 *
 * L'enum porte des entiers (1, 2, 3), ceux que la vue publiait deja. La colonne, elle, stocke le
 * **nom** de la classe : une base qu'on lit a la main doit se lire sans table de correspondance, et
 * un `warriors` dans une ligne vaut mieux qu'un `1` dont personne ne se souvient. La conversion est
 * au seul endroit qui connait les deux formes.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('alliances', function (Blueprint $table): void {
            $table->string('alliance_class', 20)->nullable()->after('is_open');
            $table->unsignedInteger('alliance_class_selected_at')->nullable()->after('alliance_class');
        });
    }

    public function down(): void
    {
        Schema::table('alliances', function (Blueprint $table): void {
            $table->dropColumn(['alliance_class', 'alliance_class_selected_at']);
        });
    }
};
