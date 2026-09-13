<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La manoeuvre de Hamill devient une regle versionnee, pour que la corriger ne change aucune bataille deja
 * engagee.
 *
 * ## Le defaut que la correction ferme
 *
 * Le moteur PHP retire l Etoile de la mort **des unites de la bataille** : elle ne tire pas, et elle est
 * comptee perdue. Le moteur Rust ne la retirait que de `defenderUnitsStart` — un decompte de rapport —,
 * alors que l entree envoyee a la bibliotheque se compose **des flottes** : l Etoile continuait donc de
 * tirer, et ne pouvait plus apparaitre dans les pertes. Le moteur par defaut etant `rust`, la manoeuvre ne
 * detruisait rien.
 *
 * ## Pourquoi une version, et pas seulement un correctif
 *
 * Un combat durable s ouvre, puis se calcule des heures plus tard. Corriger sans version ferait jouer a un
 * combat **deja ouvert** une bataille sous une regle que personne ne lui a donnee — une Etoile de la mort
 * qui tombe la ou l attaquant ne pouvait pas y compter. La colonne fixe la regle **a l ouverture** : `v1`
 * pour tout ce qui existe deja, `v2` pour ce qui s ouvre ensuite.
 *
 * ## Pourquoi une colonne, et pas une sixieme version dans l ensemble gele
 *
 * Meme raison que pour `unit_characteristics_version` : `FrozenCombatVersionSet` entre dans l identite des
 * resultats deja figes, et une clef de plus les rendrait illisibles. Cette regle ne gouverne que le calcul
 * de la bataille ; une fois celle-ci calculee, le resultat porte des nombres.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('combat_instances', function (Blueprint $table): void {
            $table->string('hamill_rule_version', 16)->nullable()->after('unit_characteristics_version');
        });

        DB::table('combat_instances')
            ->whereNull('hamill_rule_version')
            ->update(['hamill_rule_version' => 'v1']);
    }

    public function down(): void
    {
        Schema::table('combat_instances', function (Blueprint $table): void {
            $table->dropColumn('hamill_rule_version');
        });
    }
};
