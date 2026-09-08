<?php

use Illuminate\Database\Migrations\Migration;
use OGame\Patrol\PatrolLegDestinationCleanup;

/**
 * Efface le corps d arrivee des segments de patrouille qui ne s y posent pas.
 *
 * La regle, ce qu elle epargne et pourquoi elle ne consulte aucun proprietaire de corps vivent dans
 * `PatrolLegDestinationCleanup`, avec l explication complete. Elle y est nommee pour qu un essai
 * puisse l eprouver directement : une migration anonyme ne s appelle pas depuis un banc.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        PatrolLegDestinationCleanup::run();
    }

    /**
     * Reverse the migrations.
     *
     * **Rien a defaire.** La destination effacee n est conservee nulle part : la retablir
     * demanderait de deviner quel corps chaque segment nommait, et remettrait la fuite. Le sens
     * inverse de cette migration est donc l absence d effet, dite plutot que simulee.
     */
    public function down(): void
    {
    }
};
