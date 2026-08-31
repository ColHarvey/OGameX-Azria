<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Le tirage de missions d'un joueur pour un jour d'evenement, fige.
 *
 * @property int $id
 * @property int $user_id
 * @property Carbon $event_start
 * @property Carbon $mission_date
 * @property array<int, array{key: string, tritium: int, target?: int}> $missions
 * @property-read User $user
 * @method static Builder|EventMissionDraw newModelQuery()
 * @method static Builder|EventMissionDraw newQuery()
 * @method static Builder|EventMissionDraw query()
 * @method static Builder|EventMissionDraw whereId($value)
 * @method static Builder|EventMissionDraw whereUserId($value)
 * @method static Builder|EventMissionDraw whereEventStart($value)
 * @method static Builder|EventMissionDraw whereMissionDate($value)
 * @method static Builder|EventMissionDraw whereMissions($value)
 * @mixin \Eloquent
 */
#[Fillable([
    'user_id',
    'event_start',
    'mission_date',
    'missions',
])]
#[WithoutTimestamps]
class EventMissionDraw extends Model
{
    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'event_start' => 'date',
        'mission_date' => 'date',
        'missions' => 'array',
    ];

    /**
     * Get the user that owns the draw.
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
