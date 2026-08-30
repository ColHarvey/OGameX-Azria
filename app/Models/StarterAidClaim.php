<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Une recompense du pack de bienvenue reclamee par un joueur.
 *
 * @property int $id
 * @property int $user_id
 * @property int $day
 * @property Carbon $claimed_at
 * @property-read User $user
 * @method static Builder|StarterAidClaim newModelQuery()
 * @method static Builder|StarterAidClaim newQuery()
 * @method static Builder|StarterAidClaim query()
 * @method static Builder|StarterAidClaim whereId($value)
 * @method static Builder|StarterAidClaim whereUserId($value)
 * @method static Builder|StarterAidClaim whereDay($value)
 * @method static Builder|StarterAidClaim whereClaimedAt($value)
 * @mixin \Eloquent
 */
#[Fillable([
    'user_id',
    'day',
    'claimed_at',
])]
#[WithoutTimestamps]
class StarterAidClaim extends Model
{
    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
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
