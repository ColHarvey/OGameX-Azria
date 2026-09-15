<?php

namespace OGame\Models\Lifeforms;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Le niveau d un batiment de forme de vie sur une planete ; l absence de ligne vaut zero.
 *
 * @property int $id
 * @property int $planet_id
 * @property int $object_id
 * @property int $level
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @method static Builder|LifeformBuildingLevel newModelQuery()
 * @method static Builder|LifeformBuildingLevel newQuery()
 * @method static Builder|LifeformBuildingLevel query()
 * @mixin \Eloquent
 */
#[Fillable(['planet_id', 'object_id', 'level'])]
#[Table(name: 'lifeform_building_levels')]
class LifeformBuildingLevel extends Model
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
