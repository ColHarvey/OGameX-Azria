<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La bulle d annonce de la vue generale : un brouillon, des versions publiees, et des fermetures.
 *
 * ## Pourquoi trois tables et non une
 *
 * **Le brouillon n est jamais visible des joueurs.** Enregistrer ne publie pas : la table `announcement_bubble`
 * porte ce que l administrateur est en train d ecrire, et rien de ce qu elle contient n atteint le jeu.
 *
 * **Une publication est une version, et elle se garde.** `announcement_bubble_versions` recoit une ligne a
 * chaque « Publier une nouvelle annonce ». Garder l historique n est pas un confort : une fermeture vise une
 * version precise, et le serveur doit pouvoir lire le drapeau « masquable » **de cette version-la** pour dire
 * si la fermeture est recevable (exigence de Keven, 20 septembre 2026). Avec des colonnes figees sur une seule
 * ligne, cette question n aurait plus de reponse des la publication suivante.
 *
 * **Une fermeture appartient au compte et a la version**, jamais a la planete : elle vaut donc sur tous les
 * corps et apres reconnexion, et une nouvelle publication reapparait d elle-meme.
 *
 * ## Ce qui ne change pas la version
 *
 * `enabled` vit sur le brouillon, pas sur la version : desactiver puis reactiver retire et remet la bulle
 * **sans** rien publier, donc sans effacer une seule fermeture. L apercu, lui, n ecrit nulle part.
 *
 * ## Le singleton est tenu par la base, pas par la discipline
 *
 * `singleton` porte un index unique et vaut toujours 1 : une seconde ligne est **impossible**, meme si deux
 * requetes concurrentes tentent de creer la configuration en meme temps. Un `firstOrCreate` seul ne l aurait
 * pas garanti.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('announcement_bubble', function (Blueprint $table) {
            $table->id();

            // **La garantie du singleton.** Toujours 1, et la base refuse le second.
            //
            // L index est declare a part, avec un nom court : passe a `unique()` sur une colonne fluide,
            // l argument n est PAS le nom de l index — Laravel prefixe la table et suffixe `_unique`, ce qui
            // donnait ici soixante-douze caracteres la ou MariaDB en accepte soixante-quatre. SQLite, lui,
            // l acceptait : la suite passait et le deploiement aurait echoue.
            $table->unsignedTinyInteger('singleton')->default(1);

            // Le brouillon : ce que l administrateur ecrit, et que personne d autre ne voit.
            $table->string('draft_title', 120)->default('');
            $table->text('draft_body')->nullable();
            $table->string('draft_link_url', 500)->nullable();
            $table->string('draft_link_label', 60)->nullable();
            $table->boolean('draft_dismissible')->default(true);

            // L interrupteur d affichage. Il ne publie rien et ne consomme aucune version.
            $table->boolean('enabled')->default(false);

            $table->timestamps();

            $table->unique('singleton', 'ann_bubble_singleton_unique');
        });

        Schema::create('announcement_bubble_versions', function (Blueprint $table) {
            $table->id();

            // **Le numero de version est l identite d une publication.** C est lui que porte une fermeture.
            // Son index unique est declare plus bas, avec un nom court : voir la note du singleton.
            $table->unsignedInteger('version');

            $table->string('title', 120);
            $table->text('body')->nullable();
            $table->string('link_url', 500)->nullable();
            $table->string('link_label', 60)->nullable();
            $table->boolean('dismissible')->default(true);

            $table->timestamp('published_at');
            $table->timestamps();

            $table->unique('version', 'ann_bubble_version_unique');
        });

        Schema::create('announcement_bubble_dismissals', function (Blueprint $table) {
            $table->id();

            // `users.id` est un `increments`, donc `int unsigned` — jamais `bigint`. MariaDB refuserait la
            // table avec l erreur 1005 (errno 150) la ou SQLite l accepterait en silence.
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('version');

            $table->timestamps();

            // **Le couple est unique** : deux clics, deux onglets ou deux requetes simultanees ne posent
            // qu une fermeture, et le moteur le garantit au lieu d une lecture prealable.
            $table->unique(['user_id', 'version'], 'announcement_bubble_dismissals_user_version');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('version')->references('version')->on('announcement_bubble_versions')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_bubble_dismissals');
        Schema::dropIfExists('announcement_bubble_versions');
        Schema::dropIfExists('announcement_bubble');
    }
};
