<?php

namespace OGame\Models\Lifeforms;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Le report de l accueil des formes de vie par un compte : une ligne, une version, un instant.
 *
 * @property int $id
 * @property int $user_id
 * @property int $dismissed_version
 * @property int $dismissed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @method static Builder|LifeformWelcome newModelQuery()
 * @method static Builder|LifeformWelcome newQuery()
 * @method static Builder|LifeformWelcome query()
 * @mixin \Eloquent
 */
#[Fillable(['user_id', 'dismissed_version', 'dismissed_at'])]
#[Table(name: 'lifeform_welcomes')]
class LifeformWelcome extends Model
{
    /**
     * La version courante de l accueil : une invitation reportee ne revient qu avec une version neuve.
     */
    public const int VERSION = 1;

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'user_id' => 'integer',
        'dismissed_version' => 'integer',
        'dismissed_at' => 'integer',
    ];
}
