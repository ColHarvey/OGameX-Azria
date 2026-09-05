<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un evenement de la chronologie de presentation d'un combat : une perte, a qui, de quoi, et quand
 * elle devient visible.
 *
 * Derive du resultat gele a la cloture, dans la meme transaction que lui ; jamais des modeles
 * vivants. Voir la migration `create_combat_presentation_events_table` pour le contrat entier.
 *
 * @property int $id
 * @property int $combat_instance_id
 * @property string $version
 * @property int $sequence
 * @property int $visible_at
 * @property string $participant_key
 * @property string $side
 * @property string $unit
 * @property int $amount
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @method static Builder|CombatPresentationEvent newModelQuery()
 * @method static Builder|CombatPresentationEvent newQuery()
 * @method static Builder|CombatPresentationEvent query()
 * @mixin \Eloquent
 */
#[Fillable([
    'combat_instance_id',
    'version',
    'sequence',
    'visible_at',
    'broadcast_at',
    'participant_key',
    'side',
    'unit',
    'amount',
])]
#[Table(name: 'combat_presentation_events')]
class CombatPresentationEvent extends Model
{
    /**
     * Le combat que cet evenement devoile.
     *
     * @return BelongsTo<CombatInstance, $this>
     */
    public function combatInstance(): BelongsTo
    {
        return $this->belongsTo(CombatInstance::class, 'combat_instance_id');
    }
}
