<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * L'etat d'une base hostile a un instant donne.
 *
 * Telemetrie pure, comme [[NpcObservation]] : aucune mecanique ne lit cette table, et la
 * purger n'a aucun effet sur le jeu. Elle existe parce qu'on ne peut pas repondre a « est-ce
 * que les bases evoluent bien » en regardant une base — il faut comparer deux instants, et
 * rien ne conservait cette trace.
 *
 * @property int $id
 * @property int $user_id
 * @property int $planet_id
 * @property int $score
 * @property int $maturity
 * @property int $buildings
 * @property int $ships
 * @property int $defences
 * @property int $metal
 * @property int $crystal
 * @property int $deuterium
 * @property string|null $action
 * @property string|null $detail
 * @property Carbon $observed_at
 * @method static Builder|NpcBaseSnapshot newModelQuery()
 * @method static Builder|NpcBaseSnapshot newQuery()
 * @method static Builder|NpcBaseSnapshot query()
 * @mixin \Eloquent
 */
#[Fillable([
    'user_id',
    'planet_id',
    'score',
    'maturity',
    'buildings',
    'ships',
    'defences',
    'metal',
    'crystal',
    'deuterium',
    'action',
    'detail',
    'observed_at',
])]
#[WithoutTimestamps]
class NpcBaseSnapshot extends Model
{
    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'score' => 'integer',
        'maturity' => 'integer',
        'buildings' => 'integer',
        'ships' => 'integer',
        'defences' => 'integer',
        'metal' => 'integer',
        'crystal' => 'integer',
        'deuterium' => 'integer',
        'observed_at' => 'datetime',
    ];
}
