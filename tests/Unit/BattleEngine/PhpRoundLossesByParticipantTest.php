<?php

namespace Tests\Unit\BattleEngine;

use OGame\GameMissions\BattleEngine\BattleEngine;
use OGame\GameMissions\BattleEngine\PhpBattleEngine;

/**
 * Les pertes par participant et par round, sur le moteur PHP.
 */
class PhpRoundLossesByParticipantTest extends RoundLossesByParticipantTestAbstract
{
    /**
     * @return class-string<BattleEngine>
     */
    protected function battleEngineClass(): string
    {
        return PhpBattleEngine::class;
    }
}
