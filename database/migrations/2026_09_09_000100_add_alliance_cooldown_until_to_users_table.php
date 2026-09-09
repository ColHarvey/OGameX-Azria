<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * L echeance du delai d alliance, persistee au lieu d etre recalculee.
 *
 * ## Pourquoi une colonne, et pas `alliance_left_at + reglage`
 *
 * Le delai se lisait en additionnant le nombre de jours du reglage a l instant du depart. Un
 * administrateur qui change ce reglage deplace donc **retroactivement** l echeance de tous les
 * joueurs deja partis : celui qui devait etre libre demain se retrouve retenu quatre jours de plus,
 * sans qu aucun evenement le concernant n ait eu lieu. Le plan approuve du 9 septembre 2026 dit
 * « persister une echeance autoritative » ; c est cette colonne.
 *
 * `alliance_left_at` reste : elle dit **quand** on est parti, ce qui s affiche et se lit ; la
 * nouvelle dit **jusqu a quand** on attend, ce qui se decide.
 *
 * ## Le rattrapage, une fois
 *
 * Les joueurs deja partis ont une date de depart et pas d echeance. La migration la calcule une
 * seule fois, avec le reglage tel qu il est a cet instant — c est exactement la valeur qu ils
 * auraient lue juste avant. Personne n est ni libere ni retenu par la conversion.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('alliance_cooldown_until')->nullable()->after('alliance_left_at');
        });

        // **Trois jours, et non sept, pour ceux qui sont deja partis.** La regle approuvee porte
        // desormais a sept jours, mais l appliquer ici retiendrait retroactivement des joueurs qui
        // avaient quitte sous l ancienne. Le rattrapage emploie donc la valeur qui avait cours ;
        // seuls les departs posterieurs comptent sept.
        $jours = (int)(DB::table('settings')->where('key', 'alliance_cooldown_days')->value('value') ?? 3);

        DB::table('users')
            ->whereNotNull('alliance_left_at')
            ->update([
                'alliance_cooldown_until' => DB::raw(
                    match (DB::connection()->getDriverName()) {
                        'sqlite' => "datetime(alliance_left_at, '+' || " . $jours . " || ' days')",
                        default => 'DATE_ADD(alliance_left_at, INTERVAL ' . $jours . ' DAY)',
                    }
                ),
            ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('alliance_cooldown_until');
        });
    }
};
