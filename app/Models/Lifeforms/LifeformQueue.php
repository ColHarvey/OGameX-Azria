<?php

namespace OGame\Models\Lifeforms;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Un travail de forme de vie (batiment ou technologie) : prix et echeance figes au depart.
 *
 * @property int $id
 * @property int $planet_id
 * @property int $user_id
 * @property string $kind
 * @property int $object_id
 * @property int $target_level
 * @property int $metal
 * @property int $crystal
 * @property int $deuterium
 * @property int $energy
 * @property int|null $time_start
 * @property int|null $time_end
 * @property string $status
 * @property int $catalogue_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @method static Builder|LifeformQueue newModelQuery()
 * @method static Builder|LifeformQueue newQuery()
 * @method static Builder|LifeformQueue query()
 * @mixin \Eloquent
 */
#[Fillable(['planet_id', 'user_id', 'kind', 'object_id', 'target_level', 'metal', 'crystal', 'deuterium', 'energy', 'time_start', 'time_end', 'status', 'catalogue_version'])]
#[Table(name: 'lifeform_queues')]
class LifeformQueue extends Model
{
    /**
     * @var array<string, string>
     */
    protected $casts = [
        'planet_id' => 'integer',
        'user_id' => 'integer',
        'object_id' => 'integer',
        'target_level' => 'integer',
        'metal' => 'integer',
        'crystal' => 'integer',
        'deuterium' => 'integer',
        'energy' => 'integer',
        'time_start' => 'integer',
        'time_end' => 'integer',
        'catalogue_version' => 'integer',
    ];
}
