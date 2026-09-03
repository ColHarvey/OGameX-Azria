<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Une inclusion : un evenement, un ensemble de contributions, une ligne.
 *
 * ## Les deux defauts que cette migration ferme
 *
 * **La colonne ne portait qu'une contribution.** Or elles se cumulent : un retour charge apporte des
 * vaisseaux *et* une cargaison, l'etat d'une cible apporte son solde, ses defenses *et* sa garnison.
 * Avec une seule valeur par ligne, ces evenements auraient exige plusieurs lignes — et la ligne
 * serait devenue l'unite d'unicite a la place de l'evenement.
 *
 * **L'unicite portait sur la projection.** Elle separait donc ce qu'elle aurait du confondre : une
 * instance a **une** projection gelee, et deux projections ne doivent jamais coexister dans une meme
 * photographie. Avec l'ancienne clef, un defaut qui aurait ecrit V2 dans un combat V1 aurait insere
 * l'evenement une seconde fois sans que rien ne s'y oppose.
 *
 * Les versions coexistent **entre** deux combats, grace a `combat_instance_id` — pas a l'interieur
 * d'un seul. La colonne de projection reste, comme donnee d'audit : elle est verifiee egale a celle
 * de l'instance avant toute ecriture, mais elle sort de la clef.
 *
 * ## Pourquoi une migration de plus
 *
 * `create_combat_effect_receipts_table` est deja executee. La regle du depot est claire : une
 * nouvelle migration, jamais l'edition d'une migration existante — meme sur une branche de travail,
 * puisqu'une base deja migree ne rejouerait pas la premiere.
 *
 * Les lignes existantes sont converties : une contribution devient une liste d'un element.
 * `down()` fait le chemin inverse et **refuse** de degrader une ligne qui porte plusieurs
 * contributions, plutot que d'en perdre en silence.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('combat_snapshot_inclusions', function (Blueprint $table) {
            // `["attacking_fleet"]`, `["defending_fleet","target_defences","target_resources"]` —
            // trie, sans doublon, non vide. Voir SnapshotContributionSet.
            $table->json('contributions')->nullable();
        });

        // Une contribution unique devient une liste d'un element.
        foreach (DB::table('combat_snapshot_inclusions')->select('id', 'contribution')->get() as $ligne) {
            DB::table('combat_snapshot_inclusions')
                ->where('id', $ligne->id)
                ->update(['contributions' => json_encode([$ligne->contribution])]);
        }

        Schema::table('combat_snapshot_inclusions', function (Blueprint $table) {
            $table->dropUnique('combat_incl_unique');
            $table->dropColumn('contribution');

            // **Un evenement n'entre qu'une fois dans une photographie.** La projection n'y est
            // plus : l'instance n'en a qu'une, et l'y laisser aurait permis a un defaut d'ecrire le
            // meme evenement deux fois sous deux versions.
            $table->unique(['combat_instance_id', 'event_identity'], 'combat_incl_event_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // **Refuse plutot que de perdre.** Une inclusion a plusieurs contributions n'a pas de forme
        // dans l'ancien schema : la degrader en choisirait une et jetterait les autres.
        foreach (DB::table('combat_snapshot_inclusions')->select('id', 'contributions')->get() as $ligne) {
            $liste = json_decode((string)$ligne->contributions, true);

            if (is_array($liste) && count($liste) > 1) {
                throw new RuntimeException(
                    'L inclusion ' . $ligne->id . ' porte ' . count($liste) . ' contributions : les '
                    . 'ramener a une seule en perdrait. Reglez ces lignes avant de defaire cette migration.'
                );
            }
        }

        Schema::table('combat_snapshot_inclusions', function (Blueprint $table) {
            $table->string('contribution', 32)->nullable();
        });

        foreach (DB::table('combat_snapshot_inclusions')->select('id', 'contributions')->get() as $ligne) {
            $liste = json_decode((string)$ligne->contributions, true);

            DB::table('combat_snapshot_inclusions')
                ->where('id', $ligne->id)
                ->update(['contribution' => is_array($liste) && $liste !== [] ? (string)$liste[0] : null]);
        }

        Schema::table('combat_snapshot_inclusions', function (Blueprint $table) {
            $table->dropUnique('combat_incl_event_unique');
            $table->dropColumn('contributions');

            $table->unique(
                ['combat_instance_id', 'event_identity', 'projection_version'],
                'combat_incl_unique'
            );
        });
    }
};
