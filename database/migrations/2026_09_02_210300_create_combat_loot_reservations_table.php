<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use OGame\Combat\Enums\LootReservationState;

/*
 * Ce que le defenseur ne pourrait plus mettre a l'abri, si ce mecanisme etait actif.
 *
 * ⚠ **MECANISME EXPLORATOIRE — INACTIF EN PREMIERE VERSION.**
 *
 * Rien dans le chemin de jeu n'ecrit ni ne lit cette table. Le texte qui suit decrit un mecanisme
 * qui **n'est pas celui du jeu** : il a ete concu, raccorde, puis retire.
 *
 * La regle de premiere version est l'inverse : **aucune ressource n'est immobilisee**. Le defenseur
 * depense librement pendant le combat, et le reglement se fait a la resolution, composante par
 * composante :
 *
 *     butin applique = min(butin potentiel gele, ressources reellement restantes)
 *
 * `LootReservationHasNoWriterTest` interdit tout appelant. Si ce mecanisme est repris un jour, ce
 * sera **une nouvelle decision de jeu**, pas la reprise d'une decision deja prise.
 * * La table est creee et reste vide : la branche n'est pas candidate au deploiement, et une
 * migration de retrait attendrait de savoir si le mecanisme revient. Aucun ecran, message,
 * solde disponible, verrou ni ordre transactionnel de V1 n'en depend.
 *
 * ## Ce que le mecanisme decrivait
 *
 * ## Le probleme
 *
 * Un combat dure deux heures. Le butin est calcule sur les ressources presentes **a la
 * photographie** — c'est la garantie centrale. Sans rien d'autre, le defenseur passe ces deux
 * heures a vider ses caisses : il construit, il envoie des transports, et l'attaquant repart avec un
 * butin calcule sur des ressources qui n'existent plus.
 *
 * A l'inverse, geler toutes les ressources de la planete punirait le defenseur d'etre attaque : il
 * ne pourrait plus rien construire pendant deux heures, y compris des defenses.
 *
 * La reservation est la voie du milieu : **seule la part pillable est immobilisee**, et le reste
 * continue de vivre. Ce qui est produit pendant la bataille appartient au defenseur.
 *
 * ## Une seule reservation par combat
 *
 * `combat_instance_id` est unique. Une seconde reservation sur le meme combat immobiliserait deux
 * fois les memes ressources, et la resolution en distribuerait le double. La base le refuse plutot
 * que de compter sur le fait que la resolution ne sera jamais rejouee.
 *
 * ## Les etats, et le passage qui n'existe pas
 *
 *     OPEN ──→ SEALED ──→ SETTLED
 *       │
 *       └────→ CANCELLED
 *
 * **`CANCELLED` n'est accessible que depuis `OPEN`**, et c'est une correction qui compte : le placer
 * apres `SETTLED` aurait laissé entendre qu'une reservation deja reglee pouvait etre annulee — le
 * butin preleve **puis** les fonds liberes, c'est-a-dire verses deux fois.
 *
 * Cela decoule d'une regle deja arretee : un combat ne s'annule que pendant le ralliement. Une
 * defaillance survenue apres le scellement ne doit pas annuler le combat ; elle doit conserver le
 * verrou et reprendre la resolution la ou elle s'est arretee.
 *
 * Les trois horodatages sont separes plutot que compresses en un seul champ « derniere transition » :
 * une reservation qui a ete scellee garde la trace de son ouverture, et une enquete sur un combat
 * bloque a besoin des deux.
 *
 * ## La borne monte, elle ne descend jamais
 *
 * Une livraison admise avant la fermeture ajoute des ressources pillables : la borne est **relevee**.
 * Elle ne baisse pas — un defenseur ne doit pas pouvoir la faire redescendre en depensant. Le motif
 * de la derniere hausse est ecrit, parce qu'une reservation qui grandit sans raison lisible ne se
 * distingue pas d'un defaut.
 *
 * Les montants sont des entiers, comme partout ailleurs : `planets.metal` est un `integer`, et
 * melanger les types ferait apparaitre des fractions de ressource que rien n'affiche.
 *
 * Migration purement additive ; `down()` la defait entierement.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('combat_loot_reservations', function (Blueprint $table) {
            $table->id();

            // **Une seule reservation par combat.** Deux immobiliseraient deux fois les memes
            // ressources, et la resolution en distribuerait le double.
            $table->unsignedBigInteger('combat_instance_id')->unique('combat_loot_instance_unique');
            $table->foreign('combat_instance_id', 'combat_loot_instance_fk')
                ->references('id')
                ->on('combat_instances')
                ->restrictOnDelete();

            // Le corps dont les ressources sont immobilisees. Ecrit ici plutot que deduit du
            // combat : la tache de reconciliation doit pouvoir agir sans relire l'instance.
            $table->integer('target_body_id', false, true);

            // La part pillable, immobilisee. Le reste de la planete continue de vivre.
            $table->integer('metal', false, true)->default(0);
            $table->integer('crystal', false, true)->default(0);
            $table->integer('deuterium', false, true)->default(0);

            // open / sealed / settled / cancelled — voir LootReservationState.
            $table->string('state', 16)->default(LootReservationState::Open->value);

            // Les trois moments, separes : une enquete sur un combat bloque a besoin de savoir
            // quand la reservation a ete ouverte **et** quand elle a ete scellee.
            $table->integer('opened_at', false, true);
            $table->integer('sealed_at', false, true)->nullable();
            $table->integer('settled_at', false, true)->nullable();

            // Pourquoi la borne a ete relevee la derniere fois. Elle ne descend jamais.
            $table->string('last_raise_reason', 32)->nullable();
            $table->integer('last_raise_at', false, true)->nullable();

            $table->timestamps();

            // La requete de la tache de reconciliation : quelles reservations sont encore tenues.
            $table->index(['state', 'opened_at'], 'combat_loot_state_idx');

            // « Ce corps a-t-il des ressources immobilisees ? », posee a chaque depense.
            $table->index(['target_body_id', 'state'], 'combat_loot_body_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('combat_loot_reservations');
    }
};
