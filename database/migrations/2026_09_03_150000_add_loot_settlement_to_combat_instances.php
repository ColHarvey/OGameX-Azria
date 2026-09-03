<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Le butin potentiel et le butin applique, en entiers, sur l'instance de combat.
 *
 * ## La regle que ces colonnes servent
 *
 *     butin applique = min(butin potentiel gele, ressources reellement restantes)
 *
 * Le potentiel se fige une fois, apres l'issue du moteur, depuis la photographie et les faits geles.
 * L'applique se calcule a la resolution, sous verrou de la cible, contre le solde relu. Les deux sont
 * persistes **avant le commit** qui debite : une reprise apres panne doit savoir ce qui etait dû et ce
 * qui a ete pris, sans rien recalculer depuis un solde qui a bouge depuis.
 *
 * Le rapport distingue les deux quand ils divergent : « la cible n'avait plus tout » n'est pas
 * « le calcul s'est trompe ».
 *
 * ## Pourquoi des `bigint` signes, et non des flottants ni des `unsigned`
 *
 * Ces montants sont debites, charges en soute et ecrits au rapport : ils doivent etre le meme nombre
 * aux trois endroits. Une colonne flottante perdrait l'unite au-dela de deux puissance
 * cinquante-trois. Un `bigint` signe couvre exactement le domaine entier de PHP ; un `unsigned`
 * promettrait des valeurs que PHP ne peut pas lire en entier, et la non-negativite est deja garantie
 * par le type qui ecrit et verifiee par celui qui relit.
 *
 * Le pilote rend ces colonnes en entiers natifs : Laravel desactive l'emulation des requetes
 * preparees et la conversion des resultats en chaines. Une chaine qui arriverait malgre tout serait
 * refusee a la relecture, jamais convertie.
 *
 * ## Nul tant que le combat n'est pas resolu
 *
 * `potential_loot_frozen_at` nul veut dire « pas encore fige » — un etat normal, distinct d'une
 * corruption. Migration purement additive ; `down()` retire exactement ce qu'elle ajoute.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('combat_instances', function (Blueprint $table) {
            // Ce que l'attaquant aurait pris si la cible avait encore tout ce qu'elle portait a la
            // photographie. Fige une fois, jamais recalcule.
            $table->bigInteger('potential_loot_metal')->nullable();
            $table->bigInteger('potential_loot_crystal')->nullable();
            $table->bigInteger('potential_loot_deuterium')->nullable();

            // Le taux gele qui a produit ces montants, en centiemes de pour-cent, pour l'audit.
            $table->unsignedSmallInteger('potential_loot_rate_in_basis_points')->nullable();
            $table->integer('potential_loot_frozen_at', false, true)->nullable();

            // L'empreinte de la photographie de butin, pour rattacher le potentiel a ce qui l'a produit.
            $table->string('loot_snapshot_fingerprint', 128)->nullable();

            // Ce qui a ete effectivement pris, composante par composante : le minimum ci-dessus.
            $table->bigInteger('applied_loot_metal')->nullable();
            $table->bigInteger('applied_loot_crystal')->nullable();
            $table->bigInteger('applied_loot_deuterium')->nullable();
            $table->integer('loot_settled_at', false, true)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('combat_instances', function (Blueprint $table) {
            $table->dropColumn([
                'potential_loot_metal',
                'potential_loot_crystal',
                'potential_loot_deuterium',
                'potential_loot_rate_in_basis_points',
                'potential_loot_frozen_at',
                'loot_snapshot_fingerprint',
                'applied_loot_metal',
                'applied_loot_crystal',
                'applied_loot_deuterium',
                'loot_settled_at',
            ]);
        });
    }
};
