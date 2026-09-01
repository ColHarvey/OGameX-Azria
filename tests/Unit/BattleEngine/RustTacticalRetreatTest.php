<?php

namespace Tests\Unit\BattleEngine;

use OGame\GameMissions\BattleEngine\BattleEngine;
use OGame\GameMissions\BattleEngine\RustBattleEngine;

/**
 * Rust battle-engine coverage for tactical retreat.
 */
class RustTacticalRetreatTest extends TacticalRetreatTestAbstract
{
    /**
     * @return class-string<BattleEngine>
     */
    protected function battleEngineClass(): string
    {
        // Ici, et pas dans setUp() : cinq tests de cette classe ne construisent jamais de
        // moteur — ils verifient le comptage de points et l'arrondi des ratios — et
        // s'executent parfaitement sans Rust. Les ignorer tous perdrait cette couverture.
        $this->skipWhenTheRustLibraryIsUnavailable();

        return RustBattleEngine::class;
    }
}
