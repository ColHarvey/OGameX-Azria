<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le chat general n'a besoin d'aucune colonne — seulement d'un index.
 *
 * Un message general se reconnait a ce qu'il ne vise personne : `recipient_id` et `alliance_id`
 * tous deux nuls. Les deux colonnes sont deja nullables depuis la creation de la table ; rien a
 * ajouter.
 *
 * Ce qui manque, c'est de quoi lire l'historique sans parcourir la table. Les trois index existants
 * sont `(sender_id, recipient_id)`, `(recipient_id, sender_id)` et `(alliance_id, created_at)` :
 * aucun ne sert `WHERE recipient_id IS NULL AND alliance_id IS NULL ORDER BY id DESC`. Et **le
 * general sera la conversation la plus fournie du serveur**, puisque tout le monde y ecrit.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->index(['recipient_id', 'alliance_id', 'id'], 'chat_messages_general_index');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropIndex('chat_messages_general_index');
        });
    }
};
