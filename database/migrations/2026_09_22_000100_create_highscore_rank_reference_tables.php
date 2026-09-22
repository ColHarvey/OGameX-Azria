<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La reference du classement : le rang publie la veille, pour dire de combien de places on a bouge.
 *
 * ## Pourquoi une reference publiee, et pas une colonne de plus
 *
 * Rien dans le jeu ne gardait un rang passe : `highscores` et `alliance_highscores` ne portent que le
 * rang COURANT par categorie. La variation ne se derive donc de rien d existant, il faut publier une
 * photographie et s y tenir.
 *
 * ## La cadence, tranchee par Keven le 22 septembre 2026
 *
 * Les rangs restent actualises toutes les cinq minutes. La REFERENCE, elle, ne tourne qu une fois par
 * jour : au **premier calcul reussi de la journee serveur**. Prise au pied de la lettre, une reference
 * vieille de cinq minutes aurait rendu la colonne grise en permanence.
 *
 * `published_day` porte la journee serveur de la derniere publication, et c est lui seul qui decide si
 * une rotation est due — jamais une periode glissante de vingt-quatre heures, qui decalerait la bascule
 * un peu plus chaque jour. `published_at` porte l instant REEL de la publication : un passage qui aboutit
 * a 00 h 05 affiche 00 h 05, pas 00 h 00.
 *
 * ## Une seule bascule, pour toutes les categories et les deux portees
 *
 * `highscore_rank_reference_state` n a qu une ligne, d identifiant 1, creee ici. Elle est verrouillee
 * (`for update`) le temps de la bascule : deux generateurs simultanes se serialisent, et le second
 * constate que la journee est deja publiee et ne fait rien. La reference entiere est remplacee dans
 * CETTE transaction — une panne en cours de publication conserve integralement la derniere reference
 * valide, jamais un melange de categories neuves et anciennes.
 *
 * `covered` dit quelles categories la publication a couvertes, par portee. Une categorie absente de
 * cette liste n a pas de reference : elle affiche « Reference indisponible », et surtout PAS un
 * classement entier de « Nouv. ». C est le cas des cumuls militaires tant qu ils ne sont pas actives.
 *
 * ## Pas de cle etrangere, et c est voulu
 *
 * `subject_id` designe un compte (`users.id`, `int unsigned`) ou une alliance (`alliances.id`,
 * `bigint unsigned`) selon `scope` : une cle etrangere ne peut pas etre polymorphe. Une ligne orpheline
 * laissee par un compte supprime n est jamais rapprochee de rien — le classement courant ne le contient
 * plus — et disparait a la rotation suivante, qui remplace toute la table.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('highscore_rank_references', function (Blueprint $table) {
            $table->id();
            // 'player' | 'alliance'
            $table->string('scope', 8);
            // Valeur de HighscoreTypeEnum.
            $table->unsignedTinyInteger('type');
            $table->unsignedBigInteger('subject_id');
            $table->unsignedBigInteger('rank');

            $table->unique(['scope', 'type', 'subject_id'], 'hs_rank_ref_unique');
            $table->index(['scope', 'type'], 'hs_rank_ref_scope_type_idx');
        });

        Schema::create('highscore_rank_reference_state', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            // Instant reel de la derniere publication reussie, null tant qu il n y en a eu aucune.
            $table->unsignedBigInteger('published_at')->nullable();
            // Journee serveur de cette publication, au format Y-m-d. Une rotation est due quand elle
            // differe de la journee courante.
            $table->string('published_day', 10)->nullable();
            // { "player": [0, 1, ...], "alliance": [...] } : les categories que la publication couvre.
            $table->json('covered')->nullable();
            // Compteur de publications, pour lire un rejeu sans ambiguite.
            $table->unsignedBigInteger('generation')->default(0);
            $table->timestamps();
        });

        // L unique ligne d etat existe des maintenant : la bascule la verrouille, elle ne la cree jamais.
        DB::table('highscore_rank_reference_state')->insert([
            'id' => 1,
            'published_at' => null,
            'published_day' => null,
            'covered' => null,
            'generation' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('highscore_rank_reference_state');
        Schema::dropIfExists('highscore_rank_references');
    }
};
