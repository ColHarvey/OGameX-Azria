<?php

namespace OGame\Models\Lifeforms;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Un changement d occupation d un emplacement de recherche : a partir de `from_at`, cet emplacement
 * portait `object_id` — nul quand il etait vide.
 *
 * @property int $id
 * @property int $planet_id
 * @property int $slot
 * @property int|null $object_id
 * @property int $from_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @method static Builder|LifeformSlotChange newModelQuery()
 * @method static Builder|LifeformSlotChange newQuery()
 * @method static Builder|LifeformSlotChange query()
 * @mixin \Eloquent
 */
#[Fillable(['planet_id', 'slot', 'object_id', 'from_at'])]
#[Table(name: 'lifeform_slot_history')]
class LifeformSlotChange extends Model
{
    /**
     * @var array<string, string>
     */
    protected $casts = [
        'planet_id' => 'integer',
        'slot' => 'integer',
        'object_id' => 'integer',
        'from_at' => 'integer',
    ];
}
