<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Ce qu'un combat fige a son ouverture, et ne relit jamais.
 *
 * ## Le principe
 *
 * Un combat qui dure deux heures traverse des changements : un administrateur ajuste un reglage, un
 * joueur quitte son alliance, une regle est versionnee. **Rien de tout cela ne doit changer l'issue
 * d'une bataille deja engagee.** Ce qui gouverne un combat est donc ecrit avec lui, et relu depuis
 * la ligne — jamais depuis la configuration courante.
 *
 * La table portait deja le modele de duree pour cette raison. Ces colonnes etendent le principe a
 * tout le reste.
 *
 * ## Les versions de regle
 *
 * Cinq mecanismes sont versionnes, et chacun peut evoluer entre l'ouverture d'un combat et sa
 * resolution : l'ordre causal des evenements, l'allocateur de butin, la politique de taux, la regle
 * de destruction de lune, et le schema d'empreinte. Un combat resolu sous une autre version que
 * celle qui l'a ouvert donnerait un resultat que personne ne peut reproduire.
 *
 * ## L'alliance qui gouverne, et le createur
 *
 * `FleetUnionService::handleFleetRecall()` transfere la propriete de l'union au nouveau slot 1.
 * Cette propriete-la est mouvante ; **l'alliance qui gouverne le combat ne l'est pas**. Elle est
 * figee ici, a partir du createur d'alors, et un transfert de slot ne la change jamais.
 *
 * ## `authoritative_arrival_at`
 *
 * L'heure d'arrivee qui fait foi, figee a l'ouverture. Les heures des missions peuvent encore
 * bouger — une jointure d'union aligne les membres sur la plus tardive — mais la fenetre de
 * ralliement, elle, a ete calculee sur celle-ci.
 *
 * ## Les budgets
 *
 * Plafonds **et** consommation. Les plafonds viennent de l'union de l'ouvreur, ou des valeurs
 * canoniques du jeu ; les consommations disent ou en est le camp. Les relire dans la configuration
 * laisserait un administrateur modifier l'issue d'un combat en cours.
 *
 * ## `frozen_facts_fingerprint`
 *
 * L'empreinte de tout ce qui precede. Elle rend verifiable qu'aucun de ces faits n'a bouge entre
 * l'ouverture et la resolution — un `update` accidentel se voit au lieu de se fondre.
 *
 * Migration purement additive : aucune colonne existante n'est touchee, aucune valeur par defaut ne
 * change le comportement des lignes deja ecrites. `down()` retire exactement ce qu'elle ajoute.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('combat_instances', function (Blueprint $table) {
            // Les cinq versions de regle, figees a l'ouverture.
            $table->string('causal_order_version', 48)->nullable();
            $table->string('loot_allocator_version', 48)->nullable();
            $table->string('loot_policy_version', 48)->nullable();
            $table->string('moon_destruction_rule_version', 48)->nullable();
            $table->string('fingerprint_schema_version', 48)->nullable();

            // L'identite de l'engagement qui a ouvert ce combat. Elle survit meme si la mission est
            // purgee : c'est elle qui distingue l'initiateur de tout le reste.
            $table->string('opener_identity', 120)->nullable();

            // Le createur au moment de l'ouverture, et son alliance d'alors. Un transfert de slot
            // ne les change jamais.
            $table->integer('founding_creator_id', false, true)->nullable();
            $table->integer('governing_alliance_id', false, true)->nullable();

            // L'heure d'arrivee qui fait foi, et la version du calendrier de rounds.
            $table->integer('authoritative_arrival_at', false, true)->nullable();
            $table->string('schedule_version', 32)->nullable();

            // Les budgets : plafonds figes, et consommation courante.
            $table->unsignedSmallInteger('max_fleets')->default(16);
            $table->unsignedSmallInteger('max_players')->default(5);
            $table->unsignedSmallInteger('fleets_admitted')->default(0);
            $table->unsignedSmallInteger('players_admitted')->default(0);

            // Les reglages qui gouvernent ce combat — champs d'epave, taux, seuils — tels qu'ils
            // etaient. Six reglages de champ d'epave y figurent, et le `space_dock` causal.
            $table->json('frozen_settings')->nullable();

            // La lune visee et le plan de destruction, figes : les tirages effectifs sont ecrits,
            // pas une graine. PHP et Rust ne consomment pas le hasard de la meme facon.
            $table->json('frozen_moon_identity')->nullable();
            $table->json('moon_destruction_plan')->nullable();

            // L'empreinte de tout ce qui precede, pour que rien ne bouge en silence.
            $table->string('frozen_facts_fingerprint', 128)->nullable();

            // Vrai une fois le resultat rendu visible aux joueurs. Il est calcule a l'arrivee et
            // cache jusque-la ; ce drapeau separe « calcule » de « publie ».
            $table->boolean('result_published')->default(false);
        });

        Schema::table('combat_instances', function (Blueprint $table) {
            // La tache de reconciliation cherche les combats resolus mais non publies.
            $table->index(['status', 'result_published'], 'combat_status_published_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('combat_instances', function (Blueprint $table) {
            $table->dropIndex('combat_status_published_idx');
        });

        Schema::table('combat_instances', function (Blueprint $table) {
            $table->dropColumn([
                'causal_order_version',
                'loot_allocator_version',
                'loot_policy_version',
                'moon_destruction_rule_version',
                'fingerprint_schema_version',
                'opener_identity',
                'founding_creator_id',
                'governing_alliance_id',
                'authoritative_arrival_at',
                'schedule_version',
                'max_fleets',
                'max_players',
                'fleets_admitted',
                'players_admitted',
                'frozen_settings',
                'frozen_moon_identity',
                'moon_destruction_plan',
                'frozen_facts_fingerprint',
                'result_published',
            ]);
        });
    }
};
