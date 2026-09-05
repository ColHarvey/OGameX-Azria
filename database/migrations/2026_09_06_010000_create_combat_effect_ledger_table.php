<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le registre des effets que le monde applique a un corps pendant qu'un combat le tient.
 *
 * ## Le defaut qu'il ferme
 *
 * La fermeture mesure le delta d'un effet admissible en lisant l'effectif du corps avant et apres son
 * gestionnaire. Mais un effet que le travailleur a deja livre pendant le ralliement — un missile qui
 * a frappe, un retour qui s'est pose — rejoue a vide : le gestionnaire est idempotent, la mesure donne
 * zero, et la bataille se joue contre des defenses qu'un missile a detruites ou sans des vaisseaux qui
 * sont pourtant la. Le reglement retirerait ensuite ce qui n'existe pas.
 *
 * ## Ce que le registre porte
 *
 * Une ligne par effet applique **sous une barriere ouverte**, ecrite par la porte des mouvements dans
 * la transaction meme de l'application : le combat que la barriere designe, l'identite de
 * l'evenement, le delta d'unites mesure autour du gestionnaire, l'instant. La fermeture lit ce delta
 * au lieu de rejouer. Une ligne n'existe que pour un effet applique pendant la vie de ce combat : un
 * effet anterieur a l'ouverture n'en a pas, parce que l'etat d'ouverture le reflete deja.
 *
 * La clef etrangere est en cascade : le registre est derive d'un combat et ne lui survit pas.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('combat_effect_ledger', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('combat_instance_id');
            $table->foreign('combat_instance_id', 'combat_effect_ledger_instance_fk')
                ->references('id')
                ->on('combat_instances')
                ->cascadeOnDelete();
            $table->string('event_identity', 191);
            $table->json('unit_delta');
            $table->integer('applied_at', false, true);
            $table->timestamps();

            $table->unique(['combat_instance_id', 'event_identity'], 'combat_effect_ledger_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('combat_effect_ledger');
    }
};
