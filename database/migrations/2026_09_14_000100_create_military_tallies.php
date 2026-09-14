<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les trois cumuls militaires : construits, détruits, perdus.
 *
 * ## Ce qu'ils mesurent
 *
 * Le score militaire dit ce qu'un joueur **possède aujourd'hui** ; ces trois-là disent ce qu'il a **fait** :
 * la valeur de ce qu'il a construit, celle des pertes qu'il a infligées, celle de ses propres pertes. On peut
 * donc avoir beaucoup construit, beaucoup perdu, et n'avoir qu'une petite flotte.
 *
 * Règle d'Azria inspirée d'OGame, **pas une reproduction** : les pondérations et le partage en combat groupé
 * restent à vérifier séparément.
 *
 * ## Pourquoi des demi-unités de ressources
 *
 * Le score militaire compte les défenses et les vaisseaux militaires à 100 %, les vaisseaux civils à 50 %, puis
 * divise par mille. Arrondir **à chaque événement** perdrait la contribution d'une sonde, qui vaut moins d'un
 * point. Les valeurs sont donc **pondérées une seule fois, en demi-unités** : deux pour une ressource de défense
 * ou de vaisseau militaire, une pour une ressource de vaisseau civil. La conversion en points n'a lieu qu'à la
 * publication du classement : `points = valeur / 2000`.
 *
 * ## Le registre : la seule écriture des cumuls dans le jeu
 *
 * Un crédit est **une ligne du registre**, écrite dans la même transaction que l'effet — une tranche de file de
 * chantier livrée, plus tard un participant d'un combat. Sa clef d'événement est sa **clef primaire** : une
 * reprise, deux travailleurs, un rejeu la retrouvent, et rien n'est compté deux fois. Elle porte le joueur au
 * moment du fait : supprimer, abandonner ou transférer un corps ne touche aucun cumul, et un événement sans
 * corps n'en invente pas.
 *
 * **Une valeur qu'on ne sait pas calculer ne s'invente pas.** Si une unité n'appartient à aucune famille connue,
 * l'événement est écrit `en_attente` avec ses faits et la version de pondération, et ne crédite rien. Tant qu'il
 * en reste, le classement dit que ses données sont temporairement incomplètes.
 *
 * ## Les compteurs des comptes : une projection du registre
 *
 * `military_tallies` garde une ligne par compte. Elle n'est **jamais** écrite par une transaction du jeu : une
 * agrégation idempotente (`MilitaryTallyAggregator`) y ajoute les événements appliqués pas encore comptés, et
 * marque chacun (`aggregated_at`) **dans la même transaction** que l'ajout. Elle tourne avant chaque publication
 * du classement (`MilitaryTallyPublisher`), qui écrit valeurs, rangs et date d'actualisation d'un seul état.
 *
 * Un compteur partagé par tous les corps d'un joueur, écrit au milieu d'une transaction du jeu, fermait des
 * cycles de verrous : une page (corps puis compte) contre un lancement de flotte (compte puis corps), un missile
 * sur une lune (lune, crédit, planète mère) contre la page de la planète mère.
 *
 * ## Pourquoi la clef d'événement est la clef primaire
 *
 * Sous InnoDB, une insertion en double sur un index **unique secondaire** pose un verrou sur l'intervalle voisin ;
 * sur la clef primaire, un verrou sur la ligne seulement. Ce sont des comportements du moteur : les courses du bac
 * MariaDB les éprouvent sur ce schéma-ci, ses index compris.
 *
 * ## Pourquoi aucune clef étrangère
 *
 * Une clef étrangère fait vérifier la ligne parente à chaque insertion. Sur `player_id`, chaque tranche livrée
 * prendrait un verrou partagé sur la ligne du compte, depuis une transaction qui tient déjà un corps : l'arête
 * corps → compte que ce registre existe pour éviter. Un compte supprimé garde ses événements ; sa ligne de
 * compteur est effacée avec lui.
 *
 * ## Le passé ne se reconstitue pas
 *
 * Aucun rattrapage : les compteurs partent de zéro pour tout le monde. **Cette migration ne fixe aucune date.** La
 * collecte commence à son **activation** (`ogamex:military:demarrer-cumuls`), quand tous les chemins de crédit
 * sont raccordés : le réglage `military_tallies_since` garde cet instant-là. Tant qu'il est absent, ou tant
 * qu'aucune publication n'a eu lieu, les trois classements se disent indisponibles.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('military_tally_events', function (Blueprint $table): void {
            // La clef de l'événement, clef primaire : ce qui rend un crédit rejouable sans effet.
            $table->string('event_key', 191)->primary();

            // Le joueur au moment du fait. Aucune clef étrangère : voir le commentaire de la classe.
            $table->integer('player_id', false, true);

            // `applique` : les trois valeurs attendent ou ont reçu leur agrégation.
            // `en_attente` : rien n'est crédité, faute de savoir évaluer une unité. Voir `reason` et `payload`.
            $table->string('status', 16)->default('applique');
            $table->string('reason', 48)->nullable();

            // Ce qu'il faut pour recalculer exactement, plus tard : unités, ligne de file, tranche.
            $table->json('payload')->nullable();

            // La version de la règle de pondération qui a produit — ou produira — ces valeurs.
            $table->string('weighting_version', 16);

            $table->unsignedBigInteger('built_value')->default(0);
            $table->unsignedBigInteger('destroyed_value')->default(0);
            $table->unsignedBigInteger('lost_value')->default(0);

            $table->unsignedInteger('recorded_at');

            // L'instant où l'agrégation a ajouté cet événement au compteur de son compte ; nul tant qu'il ne l'est pas.
            $table->unsignedInteger('aggregated_at')->nullable();

            // L'instant où un événement en attente a été repris et évalué, avec sa propre version ; nul sinon. La raison
            // de l'attente reste écrite : l'événement dit pourquoi il a attendu, et quand il a été repris.
            $table->unsignedInteger('resolved_at')->nullable();

            // La recherche des candidats de l'agrégation, et le compte des événements en attente.
            $table->index(['status', 'aggregated_at', 'player_id'], 'military_tally_candidates_idx');

            $table->timestamps();
        });

        Schema::create('military_tallies', function (Blueprint $table): void {
            $table->integer('player_id', false, true)->primary();

            $table->unsignedBigInteger('built_value')->default(0);
            $table->unsignedBigInteger('destroyed_value')->default(0);
            $table->unsignedBigInteger('lost_value')->default(0);

            $table->timestamps();
        });

        foreach (['highscores', 'alliance_highscores'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->bigInteger('military_built')->default(0)->after('military');
                $blueprint->bigInteger('military_destroyed')->default(0)->after('military_built');
                $blueprint->bigInteger('military_lost')->default(0)->after('military_destroyed');

                $blueprint->bigInteger('military_built_rank')->nullable()->default(null)->after('military_rank');
                $blueprint->bigInteger('military_destroyed_rank')->nullable()->default(null)->after('military_built_rank');
                $blueprint->bigInteger('military_lost_rank')->nullable()->default(null)->after('military_destroyed_rank');

                $blueprint->index('military_built');
                $blueprint->index('military_destroyed');
                $blueprint->index('military_lost');
            });
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', ['military_tallies_since', 'military_tallies_published_at'])->delete();

        foreach (['highscores', 'alliance_highscores'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropIndex(['military_built']);
                $blueprint->dropIndex(['military_destroyed']);
                $blueprint->dropIndex(['military_lost']);
                $blueprint->dropColumn([
                    'military_built',
                    'military_destroyed',
                    'military_lost',
                    'military_built_rank',
                    'military_destroyed_rank',
                    'military_lost_rank',
                ]);
            });
        }

        Schema::dropIfExists('military_tallies');
        Schema::dropIfExists('military_tally_events');
    }
};
