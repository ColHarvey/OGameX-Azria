<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OGame\Patrol\Enums\PatrolState;
use OGame\Patrol\Geometry\SpatialPoint;

/**
 * L identite durable d une patrouille : ce qui survit a chacun de ses vols.
 *
 * Les unites et la cargaison ne sont **jamais** ici : elles vivent sur le segment courant
 * (`fleet_missions`, genre 11), une seule propriete a la fois. Cette ligne porte l etat, le point
 * ou elle est posee, la reserve de carburant et sa facturation, la version d ordre et la base
 * d attache. Voir la migration `create_patrols_table` et `PatrolState`.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $home_planet_id
 * @property PatrolState $state
 * @property int $galaxy
 * @property int $system
 * @property int|null $x
 * @property int|null $y
 * @property int|null $current_mission_id
 * @property float $fuel_reserve
 * @property int|null $upkeep_paid_at
 * @property int|null $stationed_since
 * @property int|null $entered_system_at
 * @property int $order_version
 * @property int|null $finished_at
 * @property string|null $finish_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @method static Builder|Patrol newModelQuery()
 * @method static Builder|Patrol newQuery()
 * @method static Builder|Patrol query()
 * @mixin \Eloquent
 */
#[Fillable([
    'user_id',
    'home_planet_id',
    'state',
    'galaxy',
    'system',
    'x',
    'y',
    'current_mission_id',
    'fuel_reserve',
    'upkeep_paid_at',
    'stationed_since',
    'entered_system_at',
    'order_version',
    'finished_at',
    'finish_reason',
])]
class Patrol extends Model
{
    /**
     * @var array<string, string>
     */
    protected $casts = [
        'state' => PatrolState::class,
        'fuel_reserve' => 'float',
    ];

    /**
     * Le segment courant : l aller qui vole, ou la ligne posee qui porte les unites.
     *
     * @return BelongsTo<FleetMission, $this>
     */
    public function currentMission(): BelongsTo
    {
        return $this->belongsTo(FleetMission::class, 'current_mission_id');
    }

    /**
     * La base d attache, si elle existe encore.
     *
     * @return BelongsTo<Planet, $this>
     */
    public function homePlanet(): BelongsTo
    {
        return $this->belongsTo(Planet::class, 'home_planet_id');
    }

    /**
     * Le point ou la patrouille est posee, ou `null` tant qu un segment vole.
     */
    public function point(): SpatialPoint|null
    {
        if ($this->x === null || $this->y === null) {
            return null;
        }

        return new SpatialPoint((int)$this->x, (int)$this->y);
    }
}
