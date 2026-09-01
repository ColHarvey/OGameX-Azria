<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * La rancune qu'un joueur humain s'est attiree aupres des factions hostiles.
 *
 * Une ligne n'existe qu'a partir de la premiere provocation : l'absence de ligne vaut zero.
 *
 * @property int $id
 * @property int $user_id
 * @property int $threat
 * @property Carbon|null $last_decay_at
 * @property Carbon|null $last_raid_at
 * @property string|null $last_motive
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @method static Builder|NpcThreat newModelQuery()
 * @method static Builder|NpcThreat newQuery()
 * @method static Builder|NpcThreat query()
 * @method static Builder|NpcThreat whereUserId($value)
 * @mixin \Eloquent
 */
#[Fillable([
    'user_id',
    'threat',
    'last_decay_at',
    'last_raid_at',
    'last_motive',
])]
class NpcThreat extends Model
{
    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'threat' => 'integer',
        'last_decay_at' => 'datetime',
        'last_raid_at' => 'datetime',
    ];

    /**
     * The player this rancour belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
