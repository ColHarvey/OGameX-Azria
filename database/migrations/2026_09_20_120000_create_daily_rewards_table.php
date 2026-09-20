<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La recompense quotidienne de connexion : une ligne par compte et par journee.
 *
 * ## Pourquoi une table, et pas une colonne sur le compte
 *
 * Une colonne `daily_reward_last_claim` ne saurait pas dire **quel jour** a ete reclame : deux demandes
 * simultanees la liraient toutes deux « pas encore », et la compareraient a la meme seconde. C est la
 * **contrainte unique (compte, journee)** qui rend le double credit impossible, pas une lecture suivie d une
 * ecriture — la base refuse la seconde insertion, quel que soit l ordre des processus.
 *
 * `reward_date` est une DATE, jamais un instant : la journee est decidee par le serveur, dans le fuseau configure
 * du jeu (`config('app.timezone')`), et le renouvellement est **minuit**, pas « vingt-quatre heures apres la
 * derniere reclamation » (decision de Keven, 20 septembre 2026).
 *
 * `amount` garde le montant **credite ce jour-la**, pas le reglage courant : si l administration change le
 * montant, l historique reste lisible.
 *
 * ## Ce que cette table ne touche pas
 *
 * Ni la regeneration periodique de matiere noire (`dark_matter_regen_*`, colonne `dark_matter_last_regen`), ni les
 * recompenses d evenement (`EVENT_REWARD`), ni la matiere noire achetee. Trois mecaniques distinctes, trois jeux
 * de reglages, trois types de transaction.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('daily_rewards', function (Blueprint $table) {
            $table->id();
            // **`users.id` est un `increments`**, donc `int unsigned` — pas `bigint`. Une colonne de reference
            // d un autre type fait echouer la creation de la table sous MariaDB (erreur 1005, errno 150,
            // « Foreign key constraint is incorrectly formed »), la ou SQLite l accepte en silence. Mesure faite
            // au bac le 20 septembre 2026 ; dix tables voisines declarent deja `unsignedInteger`.
            $table->unsignedInteger('user_id');
            // La journee du serveur, pas un instant : c est elle qui porte l unicite.
            $table->date('reward_date');
            $table->unsignedBigInteger('amount');
            $table->timestamp('claimed_at');
            $table->timestamps();

            // **La garantie du « jamais deux fois »**, tenue par le moteur et non par une lecture prealable.
            $table->unique(['user_id', 'reward_date'], 'daily_rewards_user_day_unique');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_rewards');
    }
};
