<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les trois classements des formes de vie, comme le jeu officiel les tient.
 *
 * ## Pourquoi trois colonnes et pas zero
 *
 * OGame ne verse pas les investissements en formes de vie dans l Economie ni dans la Recherche classiques. Il tient
 * `Lifeform Economy` (les batiments), `Lifeform Technology` (les technologies), et leur somme `Lifeform` — ce
 * dernier total entrant dans le classement General. Deux preuves independantes, conservees au journal (§173) : la
 * correction d un membre du conseil **enterinee par un administrateur de jeu** sur le forum officiel allemand, et
 * l API publique du serveur `en1`, ou `type 9 + type 10 = type 8` a 7 351 pres sur 73 milliards.
 *
 * Avant cette migration, un joueur d Azria portant 147 niveaux de batiments et 235 niveaux de technologies de
 * formes de vie recevait **zero point** pour tout cela : aucune des trois fonctions de score ne consultait leur
 * catalogue. Mesure faite le 20 septembre 2026 sur la base de demonstration — vingt niveaux ajoutes, pas un point
 * de plus.
 *
 * ## Pourquoi les deux tables, et pourquoi les rangs
 *
 * `GenerateHighscoreRanks` parcourt **toutes** les valeurs de `HighscoreTypeEnum` et ecrit `<nom>_rank` pour les
 * joueurs **et** pour les alliances. Ajouter une valeur a l enum sans ses colonnes des deux cotes ferait tomber la
 * tache planifiee au premier passage — le commentaire de la migration de l honneur le disait deja.
 *
 * ## Rien a rattraper
 *
 * La photographie du classement se recalcule integralement depuis l etat vivant toutes les cinq minutes. Les
 * comptes existants recoivent donc leurs points au premier passage qui suit le deploiement, sans script de reprise
 * et sans compteur parallele. Les colonnes naissent a zero, et ce zero ne dure que jusqu au prochain tour.
 */
return new class () extends Migration {
    /**
     * Les trois colonnes de score et leurs trois rangs, dans l ordre ou elles se lisent.
     *
     * @var array<int, string>
     */
    private const array COLONNES = ['lifeform_economy', 'lifeform_technology', 'lifeform'];

    public function up(): void
    {
        Schema::table('highscores', function (Blueprint $table) {
            $apres = 'honor';
            foreach (self::COLONNES as $colonne) {
                $table->bigInteger($colonne)->default(0)->after($apres);
                $apres = $colonne;
            }

            $apresRang = 'honor_rank';
            foreach (self::COLONNES as $colonne) {
                $table->bigInteger($colonne . '_rank')->nullable()->default(null)->after($apresRang);
                $apresRang = $colonne . '_rank';
            }

            foreach (self::COLONNES as $colonne) {
                $table->index($colonne);
            }
        });

        Schema::table('alliance_highscores', function (Blueprint $table) {
            $apres = 'honor';
            foreach (self::COLONNES as $colonne) {
                $table->bigInteger($colonne)->default(0)->after($apres);
                $apres = $colonne;
            }

            $apresRang = 'honor_rank';
            foreach (self::COLONNES as $colonne) {
                $table->integer($colonne . '_rank')->nullable()->after($apresRang);
                $apresRang = $colonne . '_rank';
            }

            foreach (self::COLONNES as $colonne) {
                $table->index($colonne);
            }
        });
    }

    public function down(): void
    {
        foreach (['highscores', 'alliance_highscores'] as $nomDeTable) {
            Schema::table($nomDeTable, function (Blueprint $table) {
                foreach (self::COLONNES as $colonne) {
                    $table->dropIndex([$colonne]);
                }

                $aRetirer = [];
                foreach (self::COLONNES as $colonne) {
                    $aRetirer[] = $colonne;
                    $aRetirer[] = $colonne . '_rank';
                }
                $table->dropColumn($aRetirer);
            });
        }
    }
};
