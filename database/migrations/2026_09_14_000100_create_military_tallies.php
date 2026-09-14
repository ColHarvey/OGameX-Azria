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
 * point. Les compteurs gardent donc la valeur **pondérée une seule fois, en demi-unités** : deux pour une
 * ressource de défense ou de vaisseau militaire, une pour une ressource de vaisseau civil. La conversion en
 * points n'a lieu qu'à la photographie du classement : `points = valeur / 2000`.
 *
 * ## Le registre : idempotence, et rien d'inventé
 *
 * Un crédit porte une **clef d'événement** unique — une tranche de file de chantier, un participant d'un combat —
 * écrite dans la **même transaction** que l'effet. Une reprise, deux travailleurs, un rejeu : la clef existe
 * déjà, et rien n'est compté deux fois. La clef seule ne suffit pas pour les tranches de construction, qui se
 * chevauchent : l'appelant relit l'avancement **sous verrou** dans cette même transaction.
 *
 * **Une valeur qu'on ne sait pas calculer ne s'invente pas.** Si une unité n'appartient à aucune famille connue,
 * l'événement est écrit `en_attente` avec ses unités et la version de pondération, et ne crédite rien. Après
 * correction du catalogue, une reprise idempotente applique le poids exact, une seule fois. Tant qu'il reste des
 * événements en attente, le classement dit que ses données sont temporairement incomplètes.
 *
 * ## Le passé ne se reconstitue pas
 *
 * Aucun rattrapage : les trois compteurs partent de zéro pour tout le monde. **Cette migration ne fixe aucune
 * date.** La collecte commence à son **activation** (`ogamex:military:demarrer-cumuls`), quand tous les chemins
 * de crédit sont raccordés : le réglage `military_tallies_since` garde cet instant-là. Une colonne créée n'est
 * pas une collecte commencée, et le premier crédit n'est pas davantage le début — si personne ne construit
 * pendant deux jours, ces deux jours comptent quand même, à zéro. Tant que la date est absente, les trois
 * classements se disent indisponibles.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('military_value_built')->default(0)->after('honor_points');
            $table->unsignedBigInteger('military_value_destroyed')->default(0)->after('military_value_built');
            $table->unsignedBigInteger('military_value_lost')->default(0)->after('military_value_destroyed');
        });

        Schema::create('military_tally_events', function (Blueprint $table): void {
            $table->id();

            // La clef de l'événement : ce qui rend un crédit rejouable sans effet.
            $table->string('event_key', 191)->unique('military_tally_event_key_unique');

            $table->integer('player_id', false, true);
            $table->index('player_id', 'military_tally_player_idx');

            // `applique` : les trois valeurs sont dans les compteurs du compte.
            // `en_attente` : rien n'a été crédité, faute de savoir évaluer une unité. Voir `reason` et `payload`.
            $table->string('status', 16)->default('applique');
            $table->string('reason', 48)->nullable();

            // Ce qu'il faut pour recalculer exactement, plus tard : unités, camp, contexte.
            $table->json('payload')->nullable();

            // La version de la règle de pondération qui a produit — ou produira — ces valeurs.
            $table->string('weighting_version', 16);

            $table->unsignedBigInteger('built_value')->default(0);
            $table->unsignedBigInteger('destroyed_value')->default(0);
            $table->unsignedBigInteger('lost_value')->default(0);

            $table->unsignedInteger('recorded_at');
            $table->index(['status', 'recorded_at'], 'military_tally_status_idx');

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
        DB::table('settings')->where('key', 'military_tallies_since')->delete();

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

        Schema::dropIfExists('military_tally_events');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['military_value_built', 'military_value_destroyed', 'military_value_lost']);
        });
    }
};
