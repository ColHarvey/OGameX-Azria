<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Deux idempotences, et il faut les deux.
 *
 * ## Elles ne repondent pas a la meme question
 *
 *     un effet a-t-il deja ete applique au monde ?   -> combat_effect_receipts
 *     un effet figure-t-il deja dans cette photo ?   -> combat_snapshot_inclusions
 *
 * Les confondre coute cher dans les deux sens. Une livraison appliquee une fois mais absente de la
 * photographie serait perdue pour la bataille ; une livraison comptee dans la photographie sans
 * avoir ete appliquee ferait combattre des vaisseaux qui ne sont pas la.
 *
 * Un meme effet peut d'ailleurs figurer legitimement dans **plusieurs** photographies — deux combats
 * successifs sur la meme planete lisent tous deux la garnison. L'unicite d'inclusion porte donc sur
 * le triplet combat / evenement / version de projection, jamais sur l'evenement seul.
 *
 * ## L'identite ne suffit pas, il faut la provenance
 *
 * Un identifiant d'evenement dit « c'est le meme evenement ». Il ne dit pas « c'est le meme effet ».
 * Un evenement rejoue apres qu'une regle a change produirait un effet different sous la meme
 * identite, et le recu le laisserait passer pour deja applique.
 *
 * Le recu porte donc, en plus de l'identite :
 *
 *     kind_version        la version de la regle qui a produit l'effet
 *     effect_fingerprint  l'empreinte canonique de l'effet lui-meme
 *     aggregate_key       ce sur quoi il a porte
 *     applied_at          quand il a ete applique
 *     receipt_id          l'identifiant du recu, pour le citer dans un journal
 *
 * Deux effets de meme identite mais d'empreinte differente sont un defaut, pas un doublon : la
 * comparaison le rend visible au lieu de l'absorber.
 *
 * ## Les longueurs sont choisies, pas laissees par defaut
 *
 * MariaDB limite la taille d'une cle d'index. `varchar(255)` en utf8mb4 pese 1020 octets a lui seul,
 * et un index composite les depasse vite — la migration passe alors en SQLite et echoue en
 * production. Les colonnes indexees sont donc dimensionnees court et explicitement.
 *
 * Migration purement additive ; `down()` la defait entierement.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('combat_effect_receipts', function (Blueprint $table) {
            $table->id();

            // **L'idempotence du monde.** Un effet applique une fois ne l'est jamais deux.
            $table->string('event_identity', 120)->unique('combat_receipt_event_unique');

            // Nul pour un effet qui ne releve d'aucun combat — une livraison ordinaire hors
            // bataille en produit aussi.
            $table->unsignedBigInteger('combat_instance_id')->nullable();

            // La version de la regle qui a produit cet effet. Deux effets de meme identite sous
            // deux versions differentes ne sont pas le meme effet.
            $table->string('kind_version', 48);

            // L'empreinte canonique de l'effet, et ce sur quoi il a porte.
            $table->string('effect_fingerprint', 128);
            $table->string('aggregate_key', 120);

            $table->integer('applied_at', false, true);

            // Citable dans un journal : « applique, recu 0f3a… ».
            $table->string('receipt_id', 36)->unique('combat_receipt_id_unique');

            $table->timestamps();

            $table->index('combat_instance_id', 'combat_receipt_instance_idx');
            $table->index(['aggregate_key', 'applied_at'], 'combat_receipt_aggregate_idx');
        });

        Schema::create('combat_snapshot_inclusions', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('combat_instance_id');
            $table->foreign('combat_instance_id', 'combat_incl_instance_fk')
                ->references('id')
                ->on('combat_instances')
                ->restrictOnDelete();

            $table->string('event_identity', 120);

            // La version de projection : ce qu'une inclusion **signifie** peut changer sans que
            // l'evenement change. Deux versions coexistent le temps d'une bascule, et l'unicite les
            // separe au lieu de les confondre.
            $table->string('projection_version', 20);

            // Ce que cette inclusion apporte — voir SnapshotContribution.
            $table->string('contribution', 32);

            $table->integer('included_at', false, true);

            $table->timestamps();

            // **L'idempotence de la photographie.** Le meme evenement peut entrer dans plusieurs
            // combats ; il n'entre qu'une fois dans chacun, par version de projection.
            $table->unique(
                ['combat_instance_id', 'event_identity', 'projection_version'],
                'combat_incl_unique'
            );

            $table->index('event_identity', 'combat_incl_event_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('combat_snapshot_inclusions');
        Schema::dropIfExists('combat_effect_receipts');
    }
};
