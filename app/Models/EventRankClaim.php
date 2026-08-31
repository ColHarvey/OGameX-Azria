<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un rang d'evenement reclame par un joueur, avec la recompense qu'il a choisie.
 *
 * @property int $id
 * @property int $user_id
 * @property Carbon $event_start
 * @property int $rank
 * @property string $reward_key
 * @property Carbon $claimed_at
 * @property-read User $user
 * @method static Builder|EventRankClaim newModelQuery()
 * @method static Builder|EventRankClaim newQuery()
 * @method static Builder|EventRankClaim query()
 * @method static Builder|EventRankClaim whereId($value)
 * @method static Builder|EventRankClaim whereUserId($value)
 * @method static Builder|EventRankClaim whereEventStart($value)
 * @method static Builder|EventRankClaim whereRank($value)
 * @method static Builder|EventRankClaim whereRewardKey($value)
 * @method static Builder|EventRankClaim whereClaimedAt($value)
 * @mixin \Eloquent
 */
#[Fillable([
    'user_id',
    'event_start',
    'rank',
    'reward_key',
    'claimed_at',
])]
#[WithoutTimestamps]
class EventRankClaim extends Model
{
    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'event_start' => 'date',
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
