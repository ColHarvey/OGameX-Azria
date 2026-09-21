<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le curseur de lecture du canal d alliance : une verite serveur, par membre.
 *
 * ## Pourquoi une table, et pas une colonne sur le message
 *
 * Un message direct porte `read_at` : un destinataire, une lecture. Un message d alliance porte
 * `recipient_id = null` — une ligne, tous les lecteurs — et rien ne disait qui l avait lu.
 * `ChatService::markAllianceAsRead()` avait un corps vide, et le non-lu d alliance ne vivait que dans
 * un objet JavaScript en memoire, remis a zero a chaque page (mesure du 21 septembre 2026).
 *
 * ## La regle, tranchee par Keven le 21 septembre 2026
 *
 * Dans le canal d alliance, **afficher un message marque aussi les precedents comme lus** : un curseur
 * unique, `last_read_message_id`, et « non lu » veut dire « d identifiant superieur au curseur, et
 * ecrit par un autre ». Le curseur **n avance que** (`ChatService::markAllianceSeenUpTo()` ecrit sous
 * `last_read_message_id < :vu`) : deux onglets qui repondent dans le desordre ne le font jamais reculer.
 *
 * ## La borne d initialisation
 *
 * **Un seul** `max(chat_messages.id)`, capture une fois au debut de cette migration, est ecrit pour tous
 * les membres actuels. Tout message publie apres cette borne — y compris pendant que la migration
 * tourne — porte un identifiant superieur et **reste non lu**. Rien de l historique existant ne devient
 * un tas de non-lus, et rien de ce qui arrive ensuite n est avale.
 *
 * ## Les types, comme l identite visee
 *
 * `users.id` est un `increments`, donc `int unsigned` ; `alliances.id` et `chat_messages.id` sont des
 * `id()`, donc `bigint unsigned`. MariaDB refuse une cle etrangere dont la famille differe (erreur 150),
 * la ou SQLite l accepte en silence — `MigrationForeignKeyTypesTest` le garde.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('chat_alliance_reads', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->unsignedBigInteger('alliance_id');
            $table->unsignedBigInteger('last_read_message_id')->default(0);
            $table->timestamps();
        });

        // Les index a part, avec des noms courts : un nom compose depasse la limite de MariaDB.
        Schema::table('chat_alliance_reads', function (Blueprint $table): void {
            $table->unique(['user_id', 'alliance_id'], 'chat_ally_reads_unique');
            $table->foreign('user_id', 'chat_ally_reads_user_fk')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('alliance_id', 'chat_ally_reads_ally_fk')->references('id')->on('alliances')->onDelete('cascade');
        });

        // **La borne, une fois.** Lue avant la premiere ecriture, jamais recalculee par membre.
        $borne = (int)(DB::table('chat_messages')->max('id') ?? 0);
        $maintenant = now();

        $lignes = DB::table('alliance_members')
            ->select(['user_id', 'alliance_id'])
            ->orderBy('id')
            ->get()
            ->map(static fn (object $membre): array => [
                'user_id' => (int)$membre->user_id,
                'alliance_id' => (int)$membre->alliance_id,
                'last_read_message_id' => $borne,
                'created_at' => $maintenant,
                'updated_at' => $maintenant,
            ])
            ->all();

        foreach (array_chunk($lignes, 500) as $paquet) {
            DB::table('chat_alliance_reads')->insertOrIgnore($paquet);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_alliance_reads');
    }
};
