<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Ce qui reste a dire aux joueurs, ecrit dans la meme transaction que le resultat.
 *
 * ## Le probleme des deux ecritures
 *
 * La resolution d'un combat fait deux choses : elle applique le resultat au monde, et elle en
 * informe les joueurs. Les faire separement laisse deux pannes possibles, et elles sont toutes deux
 * mauvaises :
 *
 *     resultat applique, message perdu   -> le joueur voit sa flotte disparaitre sans rapport
 *     message envoye, resultat annule    -> le joueur lit un combat qui n'a pas eu lieu
 *
 * Le message est donc **ecrit dans la meme transaction que le resultat**, dans cette table, et
 * envoye ensuite par un lecteur separe. Si la transaction est annulee, le message disparait avec ;
 * si elle passe, le message existe et finira par partir, meme apres un redemarrage.
 *
 * ## Une ligne par destinataire et par genre
 *
 * L'unicite porte sur le triplet combat / participant / genre. Un rejeu de la resolution ne peut
 * donc pas produire deux rapports de bataille pour le meme joueur — la base le refuse, plutot que de
 * compter sur le fait que la resolution ne sera jamais rejouee.
 *
 * `participant_key` reprend la forme de `combat_participants` : « fleet:1234 » ou « planet:567 ».
 * Elle est toujours renseignee, precisement parce que plusieurs valeurs nulles sont permises dans un
 * index unique — la garnison stationnaire, qui n'a pas de mission, aurait sinon pu recevoir deux
 * rapports.
 *
 * ## `attempts` et `last_error`
 *
 * Un message qui echoue est reessaye ; un message qui echoue toujours doit se voir. Le compteur et
 * la derniere erreur rendent visible un envoi qui tourne en boucle, au lieu de le laisser
 * disparaitre dans un journal.
 *
 * Migration purement additive ; `down()` la defait entierement.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('combat_outbox', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('combat_instance_id');
            $table->foreign('combat_instance_id', 'combat_outbox_instance_fk')
                ->references('id')
                ->on('combat_instances')
                ->restrictOnDelete();

            // « fleet:1234 » ou « planet:567 », comme dans `combat_participants`. Jamais nulle : un
            // index unique accepte plusieurs nulls, et la garnison en aurait profite pour recevoir
            // deux rapports.
            $table->string('participant_key', 32);

            // Le genre du message, tel que `CombatOutboxKind` le produit. La colonne n'est pas
            // contrainte a une liste : c'est l'enumeration PHP qui tient les valeurs, et un genre
            // n'y existe que s'il a un ecrivain. En premiere version, un seul en a un —
            // « rally_refused ». Le rapport de bataille n'y figure pas : il est ecrit dans la
            // meme base et la meme transaction que le debit, donc atomique sans depot differe.
            $table->string('kind', 32);

            // De quoi composer le message, fige au moment de la resolution. Le recomposer plus tard
            // le ferait dependre d'un monde qui a change depuis.
            $table->json('payload')->nullable();

            $table->integer('available_at', false, true);
            $table->integer('dispatched_at', false, true)->nullable();

            // Un envoi qui tourne en boucle doit se voir plutot que de disparaitre dans un journal.
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();

            $table->timestamps();

            // **Un rejeu de la resolution ne produit pas deux rapports.** La base le refuse.
            $table->unique(
                ['combat_instance_id', 'participant_key', 'kind'],
                'combat_outbox_unique'
            );

            // La requete du lecteur : ce qui reste a envoyer, dans l'ordre.
            $table->index(['dispatched_at', 'available_at'], 'combat_outbox_pending_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('combat_outbox');
    }
};
