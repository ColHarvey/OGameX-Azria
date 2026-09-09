<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Un point de coordination par joueur, pris avant tout autre verrou.
 *
 * ## Le defaut qu il ferme
 *
 * L adhesion a une alliance lit « aucun combat entre eux » puis inscrit le joueur ; l arrivee d une
 * flotte lit « pas allies » puis ouvre le combat. Concurrentes, les deux commitent — et laissent un
 * combat actif entre deux membres d une meme alliance, ce que la regle interdit.
 *
 * ## Pourquoi une table neuve, et non les lignes `users`
 *
 * Une tentative precedente verrouillait `users`. Mesure faite : `PlayerService::update()` prend le
 * compte **puis** les corps, a presque chaque page ; `updateFleetMissions()` prend les corps **puis**
 * les missions. Ajouter le compte a la fin des arrivees fermait un cycle des que le meme joueur avait
 * deux requetes en vol — deux onglets suffisent.
 *
 * Une table que **rien d autre ne verrouille** peut, elle, se placer en tete de l ordre global sans
 * entrer en conflit avec quoi que ce soit. Encore faut-il que chaque chemin qui la prend la prenne
 * **avant** ses autres verrous, et toujours par identifiant croissant : une table neuve ne garantit
 * rien a elle seule, c est la discipline d acquisition qui garantit.
 *
 * ## Ce que la ligne porte, et ce qu elle ne porte pas
 *
 * Rien d autre que l identite du joueur. Elle n a pas d etat : ce n est pas un drapeau, c est un
 * rendez-vous. Une ligne absente se cree avant d etre prise — verrouiller une ligne qui n existe pas
 * ne verrouille rien, et le depot connait deja ce fantome.
 *
 * Purement additive, et inerte tant que `alliance_offensive_protection_enabled` vaut 0.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('player_coordination_barriers', function (Blueprint $table) {
            $table->id();

            // **Le nom de l index est court, et ce n est pas une coquetterie.** Pose en fluent sur
            // la colonne, il devenait `player_coordination_barriers_player_coordination_user_unique_unique`
            // — soixante-sept caracteres, que SQLite accepte et que MariaDB refuse a la migration.
            // La suite serait passee ici et le deploiement aurait echoue ; `MigrationIndexNamingTest`
            // l a dit avant.
            $table->integer('user_id', false, true);
            $table->unique('user_id', 'pcb_user_unique');

            $table->foreign('user_id', 'pcb_user_fk')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_coordination_barriers');
    }
};
