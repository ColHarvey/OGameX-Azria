<?php

namespace OGame\Models\Lifeforms;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Un vol de decouverte : son issue scellee au lancement, creditee une fois a l echeance.
 *
 * @property int $id
 * @property int $user_id
 * @property int $planet_id
 * @property int $galaxy
 * @property int $system
 * @property int $position
 * @property int $started_at
 * @property int $ends_at
 * @property array<string, mixed> $outcome
 * @property string $status
 * @property int|null $settled_at
 * @property int $rules_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @method static Builder|LifeformDiscovery newModelQuery()
 * @method static Builder|LifeformDiscovery newQuery()
 * @method static Builder|LifeformDiscovery query()
 * @mixin \Eloquent
 */
#[Fillable(['user_id', 'planet_id', 'galaxy', 'system', 'position', 'started_at', 'ends_at', 'outcome', 'status', 'settled_at', 'rules_version'])]
#[Table(name: 'lifeform_discoveries')]
class LifeformDiscovery extends Model
{
    /**
     * @var array<string, string>
     */
    protected $casts = [
        'user_id' => 'integer',
        'planet_id' => 'integer',
        'galaxy' => 'integer',
        'system' => 'integer',
        'position' => 'integer',
        'started_at' => 'integer',
        'ends_at' => 'integer',
        'outcome' => 'array',
        'settled_at' => 'integer',
        'rules_version' => 'integer',
    ];
}
