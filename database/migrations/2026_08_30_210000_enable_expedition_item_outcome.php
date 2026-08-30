<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    /**
     * Run the migrations.
     *
     * L'issue « objet trouve » d'une expedition etait ponderee a 0 : la migration d'origine
     * la desactivait faute d'objets a donner. Ils existent desormais, on retablit donc le
     * taux officiel de 0,5 %.
     */
    public function up(): void
    {
        // On ne touche qu'aux serveurs restes sur la valeur desactivee : si un administrateur
        // a deja regle ce poids a la main, son choix est conserve.
        DB::table('settings')
            ->where('key', 'expedition_weight_items')
            ->where('value', '0')
            ->update(['value' => '0.5']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('settings')
            ->where('key', 'expedition_weight_items')
            ->where('value', '0.5')
            ->update(['value' => '0']);
    }
};
