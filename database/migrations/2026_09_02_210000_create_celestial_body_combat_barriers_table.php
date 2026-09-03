<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Un corps celeste, un combat a la fois.
 *
 * ## Pourquoi une table plutot qu'une requete
 *
 * « Ce corps est-il en combat ? » se repondait par un `select` sur `combat_instances` filtre par
 * statut. Une lecture ne verrouille rien : deux flottes arrivant a la meme seconde lisaient toutes
 * les deux « libre », et ouvraient deux combats sur la meme planete. Le second effacait la
 * photographie du premier.
 *
 * Une **contrainte d'unicite** ne se contourne pas. `target_body_id` est unique : la base refuse la
 * seconde insertion, et le perdant de la course apprend qu'il rejoint au lieu d'ouvrir. C'est la
 * base qui arbitre, pas l'ordre dans lequel deux workers ont eu la main.
 *
 * ## La premiere prise du verrou global
 *
 * Plusieurs verrous sont pris pendant une fermeture de ralliement : cette barriere, l'instance,
 * l'union, les missions candidates. **Deux transactions qui les prennent dans un ordre different se
 * bloquent mutuellement.** L'ordre est donc fixe, et celui-ci vient en premier :
 *
 *     1. barriere du corps celeste
 *     2. instance de combat
 *     3. union, puis missions par identifiant croissant
 *     4. la cible, quand la resolution debite son solde
 *
 * Cet ordre est une decision, pas une observation. Il est ecrit ici parce que c'est le premier
 * maillon, et tout code qui prend ces verrous doit le suivre.
 *
 * **Le quatrieme maillon a change de nature.** Il designait une reservation de butin, mecanisme
 * retire de la premiere version : aucune ressource n'est immobilisee, et le reglement se fait a la
 * resolution par `min(butin potentiel, ressources restantes)`. C'est donc la cible elle-meme qui se
 * verrouille, au moment du debit, et rien avant.
 *
 * Un ordre de verrous qui nomme un maillon inexistant est pire qu'un ordre incomplet : il invite a
 * verrouiller quelque chose qui n'est jamais pris, et donc a s'attendre pour rien.
 *
 * ## `owned_through_effect_at`
 *
 * Jusqu'a quel instant d'effet cette barriere possede le corps. Un evenement planifie **avant**
 * cette borne appartient a ce combat-ci, meme traite plus tard par un worker en retard ; un
 * evenement planifie apres appartient a la suite. Sans cette borne, un worker en retard rendrait
 * l'appartenance dependante de son propre retard.
 *
 * ## `revision`
 *
 * Elle augmente a chaque modification du combat qui change ce que la barriere protege — echeance
 * raccourcie par un rappel, admission prononcee. Un lecteur qui a decide sur la revision 3 et
 * ecrit alors que la ligne est en revision 4 doit recommencer plutot qu'ecraser.
 *
 * Migration purement additive ; `down()` la defait entierement. Le systeme reste inerte tant que
 * `combat_duration_enabled` vaut 0.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('celestial_body_combat_barriers', function (Blueprint $table) {
            $table->id();

            // **La garantie de la table.** Un seul combat actif par corps celeste, arbitre par la
            // base et non par l'ordre d'arrivee de deux workers.
            $table->integer('target_body_id', false, true)->unique('combat_barrier_body_unique');

            $table->unsignedBigInteger('combat_instance_id');
            $table->foreign('combat_instance_id', 'combat_barrier_instance_fk')
                ->references('id')
                ->on('combat_instances')
                ->restrictOnDelete();

            // L'instant d'ouverture, fige. Il ne bouge jamais : c'est lui qui separe ce qui etait
            // deja engage de ce qui a ete decide apres.
            $table->integer('opened_at', false, true);

            // Jusqu'a quel instant d'effet ce combat possede le corps. Un evenement planifie avant
            // cette borne lui appartient, quel que soit le retard du worker qui le traite.
            $table->integer('owned_through_effect_at', false, true);

            // Augmente a chaque changement de ce que la barriere protege. Un ecrivain qui a decide
            // sur une revision anterieure recommence au lieu d'ecraser.
            $table->integer('revision', false, true)->default(0);

            $table->timestamps();

            // La requete de la tache de reconciliation : quelles barrieres sont echues.
            $table->index('owned_through_effect_at', 'combat_barrier_through_idx');

            $table->index('combat_instance_id', 'combat_barrier_instance_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('celestial_body_combat_barriers');
    }
};
