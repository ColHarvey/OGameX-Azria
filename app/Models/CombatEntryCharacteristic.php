<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Les caracteristiques de combat d un participant, gelees a son entree dans un combat durable.
 *
 * Aucune conversion n est declaree : la relecture passe par `FrozenCombatCharacteristics::fromStorage()`,
 * qui exige des entiers. Une conversion ici rendrait valide une ligne abimee avant meme la porte.
 *
 * @property int $id
 * @property int $combat_instance_id
 * @property int|null $fleet_mission_id
 * @property string $participant_key
 * @property int $player_id
 * @property int $weapon_level
 * @property int $shield_level
 * @property int $armor_level
 * @property int $class_combat_bonus
 * @property int $entered_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @method static Builder|CombatEntryCharacteristic newModelQuery()
 * @method static Builder|CombatEntryCharacteristic newQuery()
 * @method static Builder|CombatEntryCharacteristic query()
 * @mixin \Eloquent
 */
#[Fillable([
    'combat_instance_id',
    'fleet_mission_id',
    'participant_key',
    'player_id',
    'weapon_level',
    'shield_level',
    'armor_level',
    'class_combat_bonus',
    'entered_at',
])]
class CombatEntryCharacteristic extends Model
{
}
