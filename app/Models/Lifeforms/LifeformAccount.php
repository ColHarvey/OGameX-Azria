<?php

namespace OGame\Models\Lifeforms;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * L espece choisie par un compte, une fois pour toutes, avec son portefeuille de decouvertes.
 *
 * @property int $id
 * @property int $user_id
 * @property int $species
 * @property int $chosen_at
 * @property int $artifacts
 * @property int $discoveries_available
 * @property int|null $discoveries_started_at
 * @property int|null $discoveries_credited_until
 * @property int $welcome_dismissed_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @method static Builder|LifeformAccount newModelQuery()
 * @method static Builder|LifeformAccount newQuery()
 * @method static Builder|LifeformAccount query()
 * @mixin \Eloquent
 */
#[Fillable(['user_id', 'species', 'chosen_at', 'artifacts', 'discoveries_available', 'discoveries_started_at', 'discoveries_credited_until', 'welcome_dismissed_version'])]
#[Table(name: 'lifeform_accounts')]
class LifeformAccount extends Model
{
    /**
     * @var array<string, string>
     */
    protected $casts = [
        'user_id' => 'integer',
        'species' => 'integer',
        'chosen_at' => 'integer',
        'artifacts' => 'integer',
        'discoveries_available' => 'integer',
        'discoveries_started_at' => 'integer',
        'discoveries_credited_until' => 'integer',
        'welcome_dismissed_version' => 'integer',
    ];
}
