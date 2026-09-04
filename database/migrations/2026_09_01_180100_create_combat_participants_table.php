<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Qui prend part a une bataille, de quel cote, et avec quoi.
 *
 * Une attaque simple compte deux participants : la flotte attaquante et les forces stationnees
 * du defenseur. Une union ACS en compte autant que de flottes engagees, plus les flottes
 * ACS Defendre presentes a l'arrivee.
 *
 * **`units_snapshot` conserve ce qui etait la au moment de la photo**, et c'est ce qui rend une
 * table de reservation par vaisseau inutile pour le prototype : le verrou porte sur tout le
 * corps celeste, donc rien n'en part, et la photo suffit a dire qui combattait. Une table de
 * reservations n'aurait de sens que le jour ou une partie des vaisseaux resterait disponible
 * pendant un combat — ce qui n'est pas la regle choisie.
 *
 * Les vaisseaux construits pendant la bataille ne figurent pas dans la photo : ils n'y
 * participent pas, et deviennent disponibles a la fin.
 *
 * Migration purement additive.
 *
 * **La cle etrangere vers `combat_instances` est en RESTRICT**, ni absente ni en cascade. Sans
 * cle du tout, un participant pourrait exister sans combat — un orphelin qui ne dit plus a quoi
 * il a pris part, ce qui est pire que le probleme qu'on croyait eviter. En cascade, supprimer un
 * combat effacerait silencieusement qui y etait.
 *
 * RESTRICT donne les deux garanties a la fois : aucun participant sans combat, et aucun combat
 * emportant ses participants sans qu'on l'ait voulu. Une purge doit etre explicite et ordonnee.
 *
 * Si la trace historique doit etre conservee, il vaut mieux marquer un combat comme purge que
 * le supprimer en laissant ses participants sans contexte.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('combat_participants', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('combat_instance_id');
            $table->foreign('combat_instance_id', 'combat_part_instance_fk')
                ->references('id')
                ->on('combat_instances')
                ->restrictOnDelete();

            $table->integer('player_id', false, true);

            // Nul pour les forces stationnees du defenseur : elles n'ont pas de mission.
            // `bigint` comme `fleet_missions.id`.
            $table->unsignedBigInteger('fleet_mission_id')->nullable();

            // **Une inscription survit a sa mission, mais pas son lien.** Sans contrainte, une ligne
            // restait inscrite sous un identifiant de mission disparu ; quand SQLite reutilisait cet
            // identifiant pour une mission neuve, celle-ci se retrouvait « inscrite » a un combat
            // qu'elle n'avait jamais vu. La photographie est immuable — cle de participant,
            // proprietaire, instantane restent —, et le lien vers la mission devient nul quand la
            // mission est supprimee : un identifiant reutilise ne peut plus devenir ce participant.
            // Supprimer une mission encore engagee dans un combat actif est refuse par le service,
            // pas par la base : c'est lui qui sait annuler le combat d'abord.
            $table->foreign('fleet_mission_id', 'combat_part_mission_fk')
                ->references('id')
                ->on('fleet_missions')
                ->nullOnDelete();

            // L'identite du participant, toujours renseignee : « fleet:1234 » pour une flotte,
            // « planet:567 » pour la garnison stationnaire.
            //
            // Elle existe parce qu'une unicite portant sur `fleet_mission_id` aurait un trou :
            // en SQL, plusieurs valeurs nulles sont permises dans un index unique. La garnison
            // du defenseur, qui n'a pas de mission, aurait donc pu etre inscrite deux fois par
            // un traitement rejoue — et la resolution aurait compte ses vaisseaux, ses pertes et
            // ses defenses en double.
            $table->string('participant_key', 32);

            // attacker / defender
            $table->string('side', 16);

            // attack_fleet / acs_attack / planet_fleet / acs_defend / defense
            $table->string('participant_type', 16);

            // Les unites engagees, telles qu'elles etaient a l'arrivee.
            $table->json('units_snapshot')->nullable();

            $table->timestamps();

            $table->index(['combat_instance_id', 'side'], 'combat_part_side_idx');
            $table->index('player_id', 'combat_part_player_idx');
            $table->index('fleet_mission_id', 'combat_part_mission_idx');

            // Aucun participant ne peut figurer deux fois dans un combat donne — flotte comme
            // garnison. L'unicite porte sur `participant_key`, jamais nulle, precisement pour
            // que la garnison stationnaire soit couverte elle aussi.
            $table->unique(['combat_instance_id', 'participant_key'], 'combat_part_unique_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('combat_participants');
    }
};
