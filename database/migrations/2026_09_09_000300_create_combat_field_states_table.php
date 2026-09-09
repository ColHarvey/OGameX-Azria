<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * L etat d une bataille entre deux rounds, une ligne par etape.
 *
 * ## Ce que cette table rend possible
 *
 * Aujourd hui une bataille se calcule d un seul tenant a la fermeture du ralliement. Le moteur
 * progressif la joue round par round, et entre deux rounds le processus peut mourir : l etat doit
 * donc vivre en base, pas seulement en memoire.
 *
 * ## Pourquoi une ligne par etape, et non une colonne mise a jour
 *
 * **L unicite fait l idempotence.** Une etape s ecrit par une insertion, et une seconde insertion du
 * meme index echoue : deux travailleurs qui joueraient le meme round ne peuvent pas tous deux
 * ecrire. Une colonne mise a jour aurait demande de compter les lignes affectees pour savoir qui a
 * gagne — et le depot a deja paye cette lecon : **MariaDB compte les lignes changees, SQLite les
 * lignes trouvees**, si bien qu une ecriture identique rend zero d un cote et un de l autre.
 *
 * La suite des lignes est en outre l historique des etats, ce qui rend une reprise verifiable apres
 * coup : on peut relire ou en etait la bataille a chaque round.
 *
 * ## Ce que la ligne porte, et ce qu elle ne porte pas
 *
 * L etat du champ tel que `BattleFieldStateCodec` l ecrit : unites vivantes avec leur coque, les
 * deux bandes de tirages, les cumuls. **Pas la chronologie de presentation** : celle-la vit dans
 * `combat_presentation_events`, et les deux s ecrivent dans la meme transaction pour qu une panne ne
 * fasse ni perdre ni rejouer un round.
 *
 * ## Inerte
 *
 * Aucun chemin du jeu n ecrit encore dans cette table : le moteur progressif n est pas raccorde, et
 * `persistent_combat_enabled` ne l active pas. Purement additive.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('combat_field_states', function (Blueprint $table) {
            $table->id();

            // L etat suit son combat : efface avec lui, comme la chronologie de presentation. Ce
            // n est pas une trace d audit qu une suppression devrait retenir — le resultat gele et
            // le rapport, eux, le sont.
            $table->unsignedBigInteger('combat_instance_id');
            $table->foreign('combat_instance_id', 'cfs_instance_fk')
                ->references('id')
                ->on('combat_instances')
                ->cascadeOnDelete();

            // Le rang de l etape, strictement croissant. Zero est l ouverture du champ, avant tout
            // round ; un est l etat apres le premier round.
            $table->unsignedInteger('step_index');

            // La version du format, lue avant toute relecture : un etat ecrit sous une autre
            // version ne se relit pas en devinant.
            $table->unsignedInteger('schema_version');

            $table->json('state');

            $table->timestamps();

            // **Le nom est court, et ce n est pas une coquetterie** : pose en fluent, il depasserait
            // les soixante-quatre caracteres que MariaDB accepte, ce que SQLite laisserait passer
            // jusqu au deploiement. `MigrationIndexNamingTest` le verifie.
            $table->unique(['combat_instance_id', 'step_index'], 'cfs_step_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('combat_field_states');
    }
};
