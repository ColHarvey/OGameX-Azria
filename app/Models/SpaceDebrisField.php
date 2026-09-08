<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OGame\Patrol\Geometry\SpatialPoint;

/**
 * Les debris d un combat en espace libre, a l adresse de la geometrie de reference ou il a eu lieu.
 *
 * Distinct de `DebrisField` (cle galaxie/systeme/position) : la revue 120 interdit de fondre ces
 * debris dans le champ d une position planetaire voisine.
 *
 * @property int $id
 * @property int $galaxy
 * @property int $system
 * @property int $x
 * @property int $y
 * @property float $metal
 * @property float $crystal
 * @property float $deuterium
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @method static Builder|SpaceDebrisField newModelQuery()
 * @method static Builder|SpaceDebrisField newQuery()
 * @method static Builder|SpaceDebrisField query()
 * @mixin \Eloquent
 */
#[Fillable([
    'galaxy',
    'system',
    'x',
    'y',
    'metal',
    'crystal',
    'deuterium',
])]
class SpaceDebrisField extends Model
{
    /**
     * @var array<string, string>
     */
    protected $casts = [
        'metal' => 'float',
        'crystal' => 'float',
        'deuterium' => 'float',
    ];

    public function point(): SpatialPoint
    {
        return new SpatialPoint((int)$this->x, (int)$this->y);
    }
}
