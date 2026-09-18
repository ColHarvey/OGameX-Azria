<?php

namespace OGame\Models\Lifeforms;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Une revision datee des cotes d artefacts des vols de decouverte (journal §159).
 *
 * @property int $id
 * @property int $effective_at
 * @property int $artifact_chance
 * @property int $small
 * @property int $medium
 * @property int $large
 * @property int $medium_chance
 * @property int $large_chance
 * @property int|null $changed_by
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @method static Builder|LifeformDiscoveryOddsRevision newModelQuery()
 * @method static Builder|LifeformDiscoveryOddsRevision newQuery()
 * @method static Builder|LifeformDiscoveryOddsRevision query()
 * @mixin \Eloquent
 */
#[Fillable(['effective_at', 'artifact_chance', 'small', 'medium', 'large', 'medium_chance', 'large_chance', 'changed_by', 'note'])]
#[Table(name: 'lifeform_discovery_odds_revisions')]
class LifeformDiscoveryOddsRevision extends Model
{
    /**
     * @var array<string, string>
     */
    protected $casts = [
        'effective_at' => 'integer',
        'artifact_chance' => 'integer',
        'small' => 'integer',
        'medium' => 'integer',
        'large' => 'integer',
        'medium_chance' => 'integer',
        'large_chance' => 'integer',
        'changed_by' => 'integer',
    ];
}
