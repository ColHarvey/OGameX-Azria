<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les missiles qu'un combat a annules et que personne n'a encore pu rendre.
 *
 * ## Pourquoi une creance, et pas une ligne de journal
 *
 * Un missile lance par une course serveur est **rendu**, jamais detruit (decision de Keven). Quand la
 * restitution est possible tout de suite, elle a lieu et rien n'est ecrit ici. Quand elle ne l'est pas
 * — le corps de depart a disparu et le protocole canonique de destination ne designe rien a cet
 * instant —, l'annulation doit rester **definitive** sans que les actifs disparaissent : un
 * avertissement au journal ne se recupere pas, une creance si.
 *
 * ## Ce qu'elle porte, et ce qu'elle garantit
 *
 * L'identite de la mission annulee, son proprietaire, la quantite due, le motif et l'instant. La
 * mission est **unique** : une annulation rejouee ne cree pas une seconde creance, et `credited_at`
 * rend le credit impossible deux fois. La creance survit a la fin du combat — c'est tout son objet :
 * elle se regle par `ogamex:combat:rembourser-missiles`, quand le proprietaire a de nouveau un corps.
 *
 * La clef etrangere vers le combat est en cascade ; celle vers la mission ne l'est pas — le lien
 * `fleet_mission_id` est l'identite de la creance, et une mission effacee ne doit pas l'emporter.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('combat_missile_refunds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('fleet_mission_id')->unique('combat_missile_refund_unique');
            $table->unsignedBigInteger('combat_instance_id');
            $table->foreign('combat_instance_id', 'combat_missile_refund_instance_fk')
                ->references('id')
                ->on('combat_instances')
                ->cascadeOnDelete();
            $table->integer('owner_id', false, true);
            $table->integer('missiles', false, true);
            $table->string('reason', 64);
            $table->integer('claimed_at', false, true);
            $table->integer('credited_at', false, true)->nullable();
            $table->integer('credited_body_id', false, true)->nullable();
            $table->timestamps();

            $table->index(['owner_id', 'credited_at'], 'combat_missile_refund_owner_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('combat_missile_refunds');
    }
};
