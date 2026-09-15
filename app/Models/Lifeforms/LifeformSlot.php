<?php

namespace OGame\Models\Lifeforms;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Un des dix-huit emplacements de recherche d une planete et la technologie qu il porte.
 *
 * @property int $id
 * @property int $planet_id
 * @property int $slot
 * @property int|null $object_id
 * @property int|null $selected_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @method static Builder|LifeformSlot newModelQuery()
 * @method static Builder|LifeformSlot newQuery()
 * @method static Builder|LifeformSlot query()
 * @mixin \Eloquent
 */
#[Fillable(['planet_id', 'slot', 'object_id', 'selected_at'])]
#[Table(name: 'lifeform_slots')]
class LifeformSlot extends Model
{
    /**
     * @var array<string, string>
     */
    protected $casts = [
        'planet_id' => 'integer',
        'slot' => 'integer',
        'object_id' => 'integer',
        'selected_at' => 'integer',
    ];
}
