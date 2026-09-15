<?php

namespace OGame\Models\Lifeforms;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Le niveau d une technologie de forme de vie sur une planete ; l absence de ligne vaut zero.
 *
 * @property int $id
 * @property int $planet_id
 * @property int $object_id
 * @property int $level
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @method static Builder|LifeformTechnologyLevel newModelQuery()
 * @method static Builder|LifeformTechnologyLevel newQuery()
 * @method static Builder|LifeformTechnologyLevel query()
 * @mixin \Eloquent
 */
#[Fillable(['planet_id', 'object_id', 'level'])]
#[Table(name: 'lifeform_technology_levels')]
class LifeformTechnologyLevel extends Model
{
    /**
     * @var array<string, string>
     */
    protected $casts = [
        'planet_id' => 'integer',
        'object_id' => 'integer',
        'level' => 'integer',
    ];
}
