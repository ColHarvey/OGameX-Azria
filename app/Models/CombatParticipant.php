<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Qui prend part a une bataille, de quel cote, et avec quoi.
 *
 * `units_snapshot` conserve ce qui etait la au moment de la photo. C'est ce qui rend une table
 * de reservation par vaisseau inutile pour le prototype : le verrou porte sur tout le corps
 * celeste, donc rien n'en part, et la photo suffit a dire qui combattait.
 *
 * @property int $id
 * @property int $combat_instance_id
 * @property int $player_id
 * @property int|null $fleet_mission_id
 * @property string $participant_key
 * @property string $side
 * @property string $participant_type
 * @property array<mixed>|null $units_snapshot
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @method static Builder|CombatParticipant newModelQuery()
 * @method static Builder|CombatParticipant newQuery()
 * @method static Builder|CombatParticipant query()
 * @mixin \Eloquent
 */
#[Fillable([
    'combat_instance_id',
    'player_id',
    'fleet_mission_id',
    'participant_key',
    'side',
    'participant_type',
    'units_snapshot',
])]
class CombatParticipant extends Model
{
    public const string SIDE_ATTACKER = 'attacker';
    public const string SIDE_DEFENDER = 'defender';

    public const string TYPE_ATTACK_FLEET = 'attack_fleet';
    public const string TYPE_ACS_ATTACK = 'acs_attack';
    public const string TYPE_PLANET_FLEET = 'planet_fleet';
    public const string TYPE_ACS_DEFEND = 'acs_defend';
    public const string TYPE_DEFENCE = 'defense';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'units_snapshot' => 'array',
        ];
    }

    /**
     * Get the combat this participant belongs to.
     *
     * @return BelongsTo<CombatInstance, $this>
     */
    public function combatInstance(): BelongsTo
    {
        return $this->belongsTo(CombatInstance::class);
    }
}
