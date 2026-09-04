<?php

namespace OGame\GameObjects\Models;

use OGame\GameMissions\BattleEngine\Draws\BattleDraws;
use OGame\GameMissions\BattleEngine\Draws\Draw;
use OGame\GameObjects\Models\Abstracts\GameObject;
use OGame\GameObjects\Models\Fields\GameObjectProperties;
use OGame\GameObjects\Models\Fields\GameObjectRapidfire;
use Random\RandomException;

abstract class UnitObject extends GameObject
{
    /**
     * Objects that this object requires on with required level.
     *
     * @var array<GameObjectRapidfire>
     */
    public array $rapidfire = [];

    /**
     * Properties of the object.
     *
     * @var GameObjectProperties
     */
    public GameObjectProperties $properties;

    /**
     * Do rapidfire dice roll against the given target object. If current unit has rapidfire against the target
     * and the dice roll is successful, return true. Otherwise, return false.
     *
     * @param UnitObject $object
     * @return bool
     * @throws RandomException
     */
    public function didSuccessfulRapidfire(UnitObject $object, BattleDraws $draws): bool
    {
        foreach ($this->rapidfire as $rapidfire) {
            if ($rapidfire->object_machine_name == $object->machine_name) {
                // If chance is 85%, it means that 85 out of 100 times, rapidfire will be successful.
                return Draw::rapidfire($draws, $rapidfire->getChance());
            }
        }

        return false;
    }
}
