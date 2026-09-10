<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Les degats qu une unite a subis et qu elle garde.
 *
 * ------------------------------------------------------------------------------------
 * DEUX COLONNES, PARCE QUE LE JEU N A QUE DEUX PORTEURS D UNITES
 *
 * L inventaire fait avant d ecrire une ligne (journal §118.3) a donne un resultat plus simple
 * qu attendu : toute unite du jeu vit dans l une de deux tables, une **colonne entiere par type**.
 * `planets.<type>` porte les corps, les garnisons et les defenses ; `fleet_missions.<type>` porte
 * tout ce qui bouge — les patrouilles comprises, dont un segment est une `FleetMission` ordinaire.
 *
 * Il n existe **aucune identite de vaisseau** dans ce modele. Ajouter une table d unites serait
 * plusieurs millions de lignes pour une information que personne ne lit vaisseau par vaisseau ;
 * la consigne l a explicitement ecarte, et la mesure lui a donne raison.
 *
 * ------------------------------------------------------------------------------------
 * POURQUOI UNE COLONNE JSON, ET CE QU ELLE PESE VRAIMENT
 *
 * Le contenu est un histogramme : par type, combien d unites portent quel niveau de degats. Trois
 * encodages ont ete mesures sur le meme etat de fin de combat, a trois echelles :
 *
 *   effectif        survivants   une ligne/vaisseau   suites ordonnees   histogramme
 *   1 670              302          302 lignes         286 / 6 203 o      82 / 802 o
 *  13 360            2 220        2 220 lignes       2 132 / 46 561 o    136 / 1 344 o
 *
 * L effectif est multiplie par 7,4, les entrees de l histogramme par 1,7 : le nombre de paliers est
 * borne par les **valeurs de degats atteignables**, pas par le nombre de vaisseaux.
 *
 * ------------------------------------------------------------------------------------
 * `NULL` VEUT DIRE « TOUT INTACT », ET C EST LA MIGRATION DES DONNEES
 *
 * La consigne exige que les unites existantes soient considerees intactes. Une colonne nullable
 * laissee vide **decrit exactement le monde d avant** : il n y a donc aucune donnee a recopier, et
 * la migration inverse ne perd rien qui existait avant elle.
 *
 * Inerte tant que `hull_damage_enabled` vaut 0.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('planets', function (Blueprint $table) {
            $table->json('damaged_hulls')->nullable();
        });

        Schema::table('fleet_missions', function (Blueprint $table) {
            $table->json('damaged_hulls')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('planets', function (Blueprint $table) {
            $table->dropColumn('damaged_hulls');
        });

        Schema::table('fleet_missions', function (Blueprint $table) {
            $table->dropColumn('damaged_hulls');
        });
    }
};
