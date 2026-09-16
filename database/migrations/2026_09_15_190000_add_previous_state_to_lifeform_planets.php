<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * L etat demographique **au depart du dernier passage** (relance de Codex, journal §155.11).
 *
 * ## Ce qu il rend possible, et pourquoi trois colonnes suffisent
 *
 * Un emplacement de recherche ne compte que s il est **ouvert**, et c est la population qui l ouvre. Le gel
 * d un combat doit donc savoir quelle population la planete portait **a l instant de l arrivee** — sinon une
 * planete qui franchit le seuil entre l arrivee et le traitement arme retroactivement une flotte partie.
 *
 * L horloge demographique n a pas de marche arriere : on ne remonte pas une population. Mais elle est
 * **deterministe et composable** — `DemographicClockTest` l exige —, donc la rejouer en avant depuis un etat
 * connu donne exactement ce qu elle avait calcule. Il suffit de garder **l etat d ou le dernier passage est
 * parti** : tout instant que ce passage a traverse se rejoue depuis la.
 *
 * **Cette fenetre couvre un passage, et pas davantage.** Une arrivee differee pendant qu un second passage
 * avance la planete sort de la fenetre : son instant n est alors plus reconstituable, et le gel **suspend**
 * au lieu de choisir une valeur. Ni zero, ni la valeur courante — voir `LifeformHistoryUnavailable` et le
 * journal §155.12, ou l elargissement de cette conservation est propose sans etre applique.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('lifeform_planets', function (Blueprint $table) {
            $table->double('previous_population')->nullable()->after('calculated_at');
            $table->double('previous_food')->nullable()->after('previous_population');
            $table->unsignedBigInteger('previous_calculated_at')->nullable()->after('previous_food');
        });
    }

    public function down(): void
    {
        Schema::table('lifeform_planets', function (Blueprint $table) {
            $table->dropColumn(['previous_population', 'previous_food', 'previous_calculated_at']);
        });
    }
};
