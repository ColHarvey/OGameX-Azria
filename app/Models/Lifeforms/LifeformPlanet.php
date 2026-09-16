<?php

namespace OGame\Models\Lifeforms;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OGame\Observers\LifeformPlanetObserver;

/**
 * L etat demographique d une planete : population, nourriture, instant du dernier calcul.
 *
 * Toute ecriture de la population ou de l ancre invalide la memoire des bonus (`LifeformPlanetObserver`) :
 * la population decide quelles technologies sont actives.
 *
 * @property int $id
 * @property int $planet_id
 * @property int $species
 * @property float $population
 * @property float $food
 * @property int $calculated_at
 * @property float|null $previous_population
 * @property float|null $previous_food
 * @property int|null $previous_calculated_at
 * @property int $installed_at
 * @property int $rules_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @method static Builder|LifeformPlanet newModelQuery()
 * @method static Builder|LifeformPlanet newQuery()
 * @method static Builder|LifeformPlanet query()
 * @mixin \Eloquent
 */
#[Fillable(['planet_id', 'species', 'population', 'food', 'calculated_at', 'previous_population', 'previous_food', 'previous_calculated_at', 'installed_at', 'rules_version'])]
#[Table(name: 'lifeform_planets')]
#[ObservedBy([LifeformPlanetObserver::class])]
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
        'previous_population' => 'float',
        'previous_food' => 'float',
        'previous_calculated_at' => 'integer',
        'installed_at' => 'integer',
        'rules_version' => 'integer',
    ];
}
