<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OGame\Combat\Enums\SnapshotContribution;

/**
 * Ce qu'un evenement apporte a **une** photographie, et une seule fois.
 *
 * ## L'unicite porte sur un triplet, pas sur l'evenement
 *
 * Un meme evenement peut legitimement figurer dans plusieurs photographies : deux combats successifs
 * sur la meme planete lisent tous deux la garnison. Une unicite sur l'evenement seul aurait donc
 * fait disparaitre la garnison du second combat.
 *
 * Elle porte sur combat / evenement / version de projection. La version compte : ce qu'une inclusion
 * **signifie** peut changer sans que l'evenement change, et deux versions coexistent le temps d'une
 * bascule.
 *
 * @property int $id
 * @property int $combat_instance_id
 * @property string $event_identity
 * @property string $projection_version
 * @property SnapshotContribution $contribution
 * @property int $included_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @method static Builder|CombatSnapshotInclusion newModelQuery()
 * @method static Builder|CombatSnapshotInclusion newQuery()
 * @method static Builder|CombatSnapshotInclusion query()
 * @mixin \Eloquent
 */
#[Fillable([
    'combat_instance_id',
    'event_identity',
    'projection_version',
    'contribution',
    'included_at',
])]
class CombatSnapshotInclusion extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'contribution' => SnapshotContribution::class,
        ];
    }

    /**
     * La photographie a laquelle cette inclusion appartient.
     *
     * @return BelongsTo<CombatInstance, $this>
     */
    public function combatInstance(): BelongsTo
    {
        return $this->belongsTo(CombatInstance::class);
    }
}
