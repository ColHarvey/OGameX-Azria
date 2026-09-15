<?php

namespace OGame\Models\Lifeforms;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * L etat demographique d une planete : population, nourriture, instant du dernier calcul.
 *
 * @property int $id
 * @property int $planet_id
 * @property int $species
 * @property float $population
 * @property float $food
 * @property int $calculated_at
 * @property int $installed_at
 * @property int $rules_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @method static Builder|LifeformPlanet newModelQuery()
 * @method static Builder|LifeformPlanet newQuery()
 * @method static Builder|LifeformPlanet query()
 * @mixin \Eloquent
 */
#[Fillable(['planet_id', 'species', 'population', 'food', 'calculated_at', 'installed_at', 'rules_version'])]
#[Table(name: 'lifeform_planets')]
class LifeformPlanet extends Model
{
    /**
     * @var array<string, string>
     */
    protected $casts = [
        'planet_id' => 'integer',
        'species' => 'integer',
        'population' => 'float',
        'food' => 'float',
        'calculated_at' => 'integer',
        'installed_at' => 'integer',
        'rules_version' => 'integer',
    ];
}
