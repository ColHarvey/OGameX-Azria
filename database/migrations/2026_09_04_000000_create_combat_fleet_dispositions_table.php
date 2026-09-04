<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Ce qu'une flotte doit faire, decide par le combat et conserve jusqu'a ce qu'elle le fasse.
 *
 * ## Le trou que cette table ferme
 *
 * La fermeture du ralliement prononce des refus. Jusqu'ici, la flotte refusee apprenait la nouvelle
 * par la boite d'envoi, et son demi-tour etait **rededuit** au moment ou le travailleur la touchait :
 * il cherchait la barriere du corps, retrouvait le combat, constatait qu'elle n'y etait pas inscrite,
 * et la renvoyait.
 *
 * Cette deduction cesse de fonctionner des que le combat est termine : la barriere est levee au
 * reglement, et il ne reste plus rien a interroger. Une Defense ACS refusee dont le stationnement
 * s'acheve longtemps apres la bataille suivait alors son chemin ordinaire — elle stationnait hors
 * photographie, exactement ce que la regle interdit, et la boite d'envoi annoncait un refus dont le
 * mouvement n'arrivait jamais.
 *
 * La decision est donc **ecrite**, dans la meme transaction que la photographie qui la produit. Elle
 * survit a la fin du combat, a la levee de la barriere, et a n'importe quel retard du travailleur.
 *
 * ## Une ligne par mission, consommee une seule fois
 *
 * L'unicite porte sur `fleet_mission_id` : une mission n'a qu'un seul mouvement a faire, et une
 * fermeture rejouee ne peut pas en ecrire deux. `consumed_at` dit si le mouvement a eu lieu ; c'est
 * lui qu'un second passage relit pour ne rien refaire, plutot que de se fier a un drapeau porte par
 * la mission.
 *
 * ## La boite d'envoi devient une consequence
 *
 * L'avis au joueur s'ecrit depuis cette disposition, jamais l'inverse. Un message est une chose que
 * l'on affiche ; une disposition est une chose que l'on doit faire, et les deux ne se remplacent
 * pas.
 *
 * Migration purement additive ; `down()` la defait entierement.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('combat_fleet_dispositions', function (Blueprint $table) {
            $table->id();

            // La mission concernee. **Unique** : un seul mouvement a faire par flotte.
            $table->unsignedBigInteger('fleet_mission_id');
            $table->unique('fleet_mission_id', 'combat_disposition_mission_unique');
            $table->foreign('fleet_mission_id', 'combat_disposition_mission_fk')
                ->references('id')
                ->on('fleet_missions')
                ->onDelete('cascade');

            // Le combat qui a decide. Il peut etre termine depuis longtemps quand on lit la ligne.
            $table->unsignedBigInteger('combat_instance_id');
            $table->foreign('combat_instance_id', 'combat_disposition_combat_fk')
                ->references('id')
                ->on('combat_instances')
                ->onDelete('cascade');

            // Le mouvement, et la raison qui se raconte au joueur.
            $table->string('movement', 32);
            $table->string('reason', 64);

            $table->integer('decided_at', false, true);
            $table->integer('consumed_at', false, true)->nullable();

            $table->timestamps();

            // Le travailleur cherche les dispositions non consommees d'une mission donnee.
            $table->index(['consumed_at'], 'combat_disposition_pending_idx');
            $table->index('combat_instance_id', 'combat_disposition_combat_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('combat_fleet_dispositions');
    }
};
