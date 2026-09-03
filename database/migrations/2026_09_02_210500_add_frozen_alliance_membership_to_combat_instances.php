<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Qui appartenait a l'alliance gouvernante a l'ouverture.
 *
 * ## Pourquoi une colonne, et non une requete
 *
 * L'appartenance se reconstruisait depuis `alliance_members`, en filtrant sur
 * `joined_at <= ouverture`. Le raisonnement paraissait juste et il etait faux : **une sortie
 * supprime la ligne**. Un allie admissible a l'ouverture devenait donc inadmissible a la fermeture
 * s'il quittait l'alliance dans l'intervalle — ce qui contredit une regle deja arretee : un
 * changement d'alliance apres l'ouverture ne change rien.
 *
 * Aucune interrogation de l'etat courant ne peut repondre a une question sur le passe quand
 * l'historique n'existe pas. La seule reponse correcte est de photographier a l'ouverture ce dont la
 * fermeture aura besoin.
 *
 * ## Pourquoi une migration separee
 *
 * `add_frozen_facts_to_combat_instances` est deja ecrite et executee. La regle du depot est claire :
 * une nouvelle migration, jamais l'edition d'une migration existante. La brancher WIP ne change rien
 * a la regle — une base deja migree ne rejouerait pas la premiere.
 *
 * Migration purement additive ; `down()` retire exactement ce qu'elle ajoute.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('combat_instances', function (Blueprint $table) {
            // `{"alliance_id": 12, "members": [3, 7, 41]}` — l'alliance qui gouverne, et les
            // proprietaires qui en etaient membres a la seconde de l'ouverture.
            //
            // Nul quand l'ouvreur n'a pas d'alliance : personne d'autre que lui ne rejoint alors,
            // et une liste vide laisserait croire qu'on a cherche.
            $table->json('frozen_alliance_membership')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('combat_instances', function (Blueprint $table) {
            $table->dropColumn('frozen_alliance_membership');
        });
    }
};
