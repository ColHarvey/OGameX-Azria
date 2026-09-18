<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Le rapport de combat gele chaque participant avec ses propres caracteristiques (journal §161).
 *
 * `battle_reports.participants` : le bloc que `BattleReportParticipants::freeze()` compose au reglement — une entree
 * par flotte des deux camps, garnison comprise, avec ses niveaux, sa classe, ses unites et, par type d unite, les
 * caracteristiques que la bataille a employees, la part des formes de vie a part, et les pertes de chaque round par
 * participant. Nul pour les rapports anterieurs : ils gardent leur affichage additionne, sans que rien ne soit
 * recalcule depuis les bonus d aujourd hui.
 *
 * `combat_instances.report_participants` : le meme bloc, gele **a la cloture** d un combat durable, avec le resultat et
 * la photographie d application — la bataille s y calcule sur des combattants geles a leur admission, et le reglement,
 * des heures plus tard, ne doit rien relire sur les comptes vivants. Nul pour un combat clos avant cette colonne : son
 * rapport n aura pas de bloc plutot qu un bloc lu au mauvais instant.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('battle_reports', function (Blueprint $table) {
            $table->json('participants')->nullable()->after('wreckage');
        });
        Schema::table('combat_instances', function (Blueprint $table) {
            $table->json('report_participants')->nullable()->after('frozen_settings');
        });
    }

    public function down(): void
    {
        Schema::table('combat_instances', function (Blueprint $table) {
            $table->dropColumn('report_participants');
        });
        Schema::table('battle_reports', function (Blueprint $table) {
            $table->dropColumn('participants');
        });
    }
};
