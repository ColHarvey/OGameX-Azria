<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La classe de personnage de chaque participant, gelee a son admission : son identite, pas seulement
 * son bonus.
 *
 * ## Ce qui restait lu a la cloture
 *
 * `combat_entry_characteristics` gele depuis le 12 septembre 2026 ce qui arme les tirs : trois niveaux
 * et le bonus des classes. La classe elle-meme se lisait encore sur le compte quand la bataille se
 * calculait : manoeuvre de Hamill, fret des transporteurs du Collecteur et des vaisseaux du General,
 * champ d epaves du General, part de pillage du Decouvreur, classe nommee au rapport. Un changement de
 * classe entre l admission et la cloture les deplacait tous.
 *
 * ## Deux colonnes, parce que « aucune classe » est une valeur
 *
 * `character_class` nul veut dire « aucune classe » ; il ne peut pas dire en meme temps « jamais
 * enregistree ». `character_class_recorded` le dit : toute ligne ecrite par le registre apres cette
 * migration le pose a 1.
 *
 * ## Les lignes deja presentes ne sont pas remplies ici
 *
 * Leur marqueur vaut 0, et le registre relit leur classe **dans l historique, a leur instant
 * d admission** (`entered_at`) : la lecture meme qui a fixe leur bonus. Si l historique ne sait pas,
 * c est `UnknownAdmissionHistory`, et la cloture se suspend. **Jamais la classe courante du compte.**
 * Une migration ne ferait pas mieux : elle ne relit pas l historique dans l ordre du registre, et une
 * valeur qu elle ecrirait serait indiscernable d une valeur enregistree a l admission.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('combat_entry_characteristics', function (Blueprint $table): void {
            $table->unsignedTinyInteger('character_class')->nullable()->after('class_combat_bonus');
            $table->boolean('character_class_recorded')->default(false)->after('character_class');
        });
    }

    public function down(): void
    {
        Schema::table('combat_entry_characteristics', function (Blueprint $table): void {
            $table->dropColumn(['character_class', 'character_class_recorded']);
        });
    }
};
