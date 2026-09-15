<?php

namespace OGame\Models\Lifeforms;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Une revision datee des vitesses qui gouvernent la demographie et les files des formes de vie.
 *
 * @property int $id
 * @property int $effective_at
 * @property float $economy_speed
 * @property float $research_speed
 * @property float $build_multiplier
 * @property float $research_multiplier
 * @property float $discovery_multiplier
 * @property int|null $changed_by
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @method static Builder|LifeformRuleRevision newModelQuery()
 * @method static Builder|LifeformRuleRevision newQuery()
 * @method static Builder|LifeformRuleRevision query()
 * @mixin \Eloquent
 */
#[Fillable(['effective_at', 'economy_speed', 'research_speed', 'build_multiplier', 'research_multiplier', 'discovery_multiplier', 'changed_by', 'note'])]
#[Table(name: 'lifeform_rule_revisions')]
class LifeformRuleRevision extends Model
{
    /**
     * @var array<string, string>
     */
    protected $casts = [
        'effective_at' => 'integer',
        'economy_speed' => 'float',
        'research_speed' => 'float',
        'build_multiplier' => 'float',
        'research_multiplier' => 'float',
        'discovery_multiplier' => 'float',
        'changed_by' => 'integer',
    ];
}
