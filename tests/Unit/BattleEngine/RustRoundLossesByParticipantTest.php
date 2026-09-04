<?php

namespace Tests\Unit\BattleEngine;

use OGame\GameMissions\BattleEngine\BattleEngine;
use OGame\GameMissions\BattleEngine\RustBattleEngine;

/**
 * Les pertes par participant et par round, sur le moteur Rust.
 *
 * C'est ici que la couture gagne son sens : le round Rust doit rendre les pertes du round par
 * flotte des deux camps, sinon le moteur partage refuse le resultat.
 */
class RustRoundLossesByParticipantTest extends RoundLossesByParticipantTestAbstract
{
    /**
     * @return class-string<BattleEngine>
     */
    protected function battleEngineClass(): string
    {
        // Ici, et pas dans setUp() : seul un essai qui construit vraiment le moteur Rust doit etre
        // ignore quand la bibliotheque est absente.
        $this->skipWhenTheRustLibraryIsUnavailable();

        return RustBattleEngine::class;
    }
}
