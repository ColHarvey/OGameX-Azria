<?php

namespace OGame\Models\Lifeforms;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * L experience d un compte dans une espece, et l instant ou il l a decouverte.
 *
 * @property int $id
 * @property int $user_id
 * @property int $species
 * @property int $experience
 * @property int|null $discovered_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @method static Builder|LifeformSpeciesProgress newModelQuery()
 * @method static Builder|LifeformSpeciesProgress newQuery()
 * @method static Builder|LifeformSpeciesProgress query()
 * @mixin \Eloquent
 */
#[Fillable(['user_id', 'species', 'experience', 'discovered_at'])]
#[Table(name: 'lifeform_species_progress')]
class LifeformSpeciesProgress extends Model
{
    /**
     * @var array<string, string>
     */
    protected $casts = [
        'user_id' => 'integer',
        'species' => 'integer',
        'experience' => 'integer',
        'discovered_at' => 'integer',
    ];
}
