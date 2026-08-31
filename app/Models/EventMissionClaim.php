<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Une mission d'evenement reclamee par un joueur, un jour donne.
 *
 * @property int $id
 * @property int $user_id
 * @property Carbon $mission_date
 * @property string $mission_key
 * @property int $tritium
 * @property Carbon $claimed_at
 * @property-read User $user
 * @method static Builder|EventMissionClaim newModelQuery()
 * @method static Builder|EventMissionClaim newQuery()
 * @method static Builder|EventMissionClaim query()
 * @method static Builder|EventMissionClaim whereId($value)
 * @method static Builder|EventMissionClaim whereUserId($value)
 * @method static Builder|EventMissionClaim whereMissionDate($value)
 * @method static Builder|EventMissionClaim whereMissionKey($value)
 * @method static Builder|EventMissionClaim whereTritium($value)
 * @method static Builder|EventMissionClaim whereClaimedAt($value)
 * @mixin \Eloquent
 */
#[Fillable([
    'user_id',
    'mission_date',
    'mission_key',
    'tritium',
    'claimed_at',
])]
#[WithoutTimestamps]
class EventMissionClaim extends Model
{
    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'mission_date' => 'date',
        'claimed_at' => 'datetime',
    ];

    /**
     * Get the user that owns the claim.
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
