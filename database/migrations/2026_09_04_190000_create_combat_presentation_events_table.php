<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * La chronologie de presentation d'un combat : ce qu'un joueur verra apparaitre, et quand.
 *
 * ## Ce que cette table est, et ce qu'elle n'est pas
 *
 * Pendant une bataille durable, chaque joueur doit voir ses propres pertes apparaitre au fil du
 * temps dans sa Vue generale — « trois chasseurs legers detruits », puis plus tard « trois
 * croiseurs perdus » — sans attendre le rapport final, et sans jamais apprendre a l'avance ce que
 * la suite lui reserve. Ces evenements sont **derives du resultat gele** a la cloture, jamais des
 * modeles vivants ni de l'heure de lecture : un rejeu du meme resultat produit exactement les
 * memes lignes, avec les memes numeros de sequence.
 *
 * Elle n'est pas le rapport de bataille. Le rapport reste issu du resultat gele et n'est jamais
 * reconstruit depuis ce fil ; ce fil est une facon de le devoiler dans la duree.
 *
 * ## Ce qu'une ligne dit
 *
 * Un participant (la clef des inscriptions : la garnison est le corps, chaque flotte sa mission),
 * un camp, un type de vaisseau, une quantite agregee, et l'instant ou cette perte devient visible —
 * la fin de la periode du round qui l'a produite, comptee depuis le debut de la bataille. Aucun
 * numero de round n'est montre : ce sont des evenements repartis dans la duree autoritative.
 *
 * ## Idempotence par la base
 *
 * L'unicite porte sur combat / version / sequence : une cloture rejouee ne peut pas ecrire deux fois
 * le meme evenement, et deux versions de la regle de presentation peuvent coexister sans se
 * confondre. La version que le combat a reellement utilisee est inscrite sur l'instance.
 *
 * Migration purement additive ; `down()` la defait entierement.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('combat_presentation_events', function (Blueprint $table) {
            $table->id();

            // **Le fil suit son combat.** Il est derive du resultat gele et se reprojette a l'identique
            // depuis lui : rien n'y est une trace d'audit qu'une suppression devrait retenir, a la
            // difference de la boite d'envoi ou des inscriptions.
            $table->unsignedBigInteger('combat_instance_id');
            $table->foreign('combat_instance_id', 'combat_presentation_instance_fk')
                ->references('id')
                ->on('combat_instances')
                ->cascadeOnDelete();

            // La version de la regle qui a produit cet evenement (`CombatPresentationTimelineV1`).
            $table->string('version', 16);

            // Le rang de l'evenement dans le fil, stable pour un meme resultat : c'est lui qu'un
            // lecteur incremental retient pour ne rien rendre deux fois.
            $table->unsignedInteger('sequence');

            // L'instant, en secondes, ou l'evenement devient visible. Le lecteur ne rend jamais
            // une ligne dont cet instant n'est pas atteint.
            $table->integer('visible_at', false, true);

            // « fleet:1234 » ou « planet:567 », comme dans `combat_participants` : c'est par cette
            // clef que le lecteur sait a qui appartient la perte.
            $table->string('participant_key', 32);
            $table->string('side', 16);

            // Le type de vaisseau ou de defense, par son nom machine, et la quantite perdue —
            // agregee par type pour l'affichage, attribuee exactement a son participant.
            $table->string('unit', 64);
            $table->unsignedInteger('amount');

            $table->timestamps();

            $table->unique(['combat_instance_id', 'version', 'sequence'], 'combat_presentation_unique');

            // La requete du lecteur : ce qui est devenu visible, dans l'ordre.
            $table->index(['combat_instance_id', 'visible_at', 'sequence'], 'combat_presentation_visible_idx');
        });

        Schema::table('combat_instances', function (Blueprint $table) {
            // La version de la regle de presentation sous laquelle ce combat a ete devoile.
            $table->string('presentation_version', 16)->nullable()->after('projection_version');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('combat_instances', function (Blueprint $table) {
            $table->dropColumn('presentation_version');
        });

        Schema::dropIfExists('combat_presentation_events');
    }
};
