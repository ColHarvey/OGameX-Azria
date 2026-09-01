<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Une decision des factions, consignee pour l'observation.
 *
 * Telemetrie pure : aucune mecanique ne lit cette table, et la purger n'a aucun effet sur le
 * jeu. Elle sert a repondre aux questions d'equilibrage sur donnees reelles plutot que sur
 * intuition.
 *
 * @property int $id
 * @property int $user_id
 * @property string $outcome
 * @property string|null $reason
 * @property int $threat
 * @property string|null $band
 * @property string|null $motive
 * @property int|null $power
 * @property int|null $fleet_size
 * @property int|null $estimated_loot
 * @property string|null $base_coordinate
 * @property string|null $target_coordinate
 * @property bool $executed
 * @property int $active_players
 * @property int $median_score
 * @property int $threshold
 * @property int $living_bases
 * @property Carbon $observed_at
 * @method static Builder|NpcObservation newModelQuery()
 * @method static Builder|NpcObservation newQuery()
 * @method static Builder|NpcObservation query()
 * @mixin \Eloquent
 */
#[Fillable([
    'user_id',
    'outcome',
    'reason',
    'threat',
    'band',
    'motive',
    'power',
    'fleet_size',
    'estimated_loot',
    'base_coordinate',
    'target_coordinate',
    'executed',
    'active_players',
    'median_score',
    'threshold',
    'living_bases',
    'observed_at',
])]
#[WithoutTimestamps]
class NpcObservation extends Model
{
    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'threat' => 'integer',
        'power' => 'integer',
        'fleet_size' => 'integer',
        'estimated_loot' => 'integer',
        'executed' => 'boolean',
        'active_players' => 'integer',
        'median_score' => 'integer',
        'threshold' => 'integer',
        'living_bases' => 'integer',
        'observed_at' => 'datetime',
    ];
}
