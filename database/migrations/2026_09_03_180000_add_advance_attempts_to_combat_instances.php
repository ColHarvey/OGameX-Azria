<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Ce qu'un reglement rate laisse derriere lui : un compteur, et la derniere raison.
 *
 * ## Le probleme que ces deux colonnes ferment
 *
 * Le travail planifie regle les combats echus, une minute apres l'autre. Un combat dont le
 * reglement leve — des faits geles corrompus, un montant que la machine ne represente pas, une
 * mission disparue — serait repris a chaque passage, indefiniment : une boucle qui remplit les
 * journaux et ne guerit jamais.
 *
 * Le compteur donne une **sortie d'exploitation**. Chaque echec l'incremente ; passe un seuil, le
 * travail cesse de reprendre ce combat et le signale comme mis de cote. Un humain lit la derniere
 * raison, corrige, remet le compteur a zero, et le combat repart. Rien n'est perdu : le combat
 * reste `Active`, avec sa bataille figee et ses participants.
 *
 * ## Pourquoi ces colonnes vivent hors de la transaction de reglement
 *
 * Un reglement qui echoue annule tout ce qu'il a ecrit. Si le compteur etait incremente dedans, il
 * serait annule avec le reste et ne compterait jamais rien. Il s'ecrit donc **apres** l'echec, dans
 * sa propre ecriture — c'est le meme motif que `attempts` et `last_error` de la boite d'envoi.
 *
 * ## Nul veut dire « jamais echoue »
 *
 * `advance_attempts` vaut zero pour tout combat qui n'a jamais rate, et `advance_last_error`
 * reste nulle. Migration purement additive ; `down()` retire exactement ce qu'elle ajoute.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('combat_instances', function (Blueprint $table) {
            // Combien de fois le reglement de ce combat a leve. Zero pour l'immense majorite.
            $table->unsignedSmallInteger('advance_attempts')->default(0);

            // La derniere raison, telle qu'elle a ete levee : c'est elle que l'exploitation lit.
            $table->text('advance_last_error')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('combat_instances', function (Blueprint $table) {
            $table->dropColumn([
                'advance_attempts',
                'advance_last_error',
            ]);
        });
    }
};
