<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Une patrouille, un combat a la fois : le pendant de `CelestialBodyCombatBarrier` pour l espace
 * libre. `patrol_id` est unique ; la base arbitre la course entre deux ouvertures.
 *
 * Meme place dans l ordre global des verrous que la barriere du corps celeste (barriere, instance,
 * union, missions) ; jamais les deux dans une meme transaction.
 *
 * @property int $id
 * @property int $patrol_id
 * @property int $combat_instance_id
 * @property int $opened_at
 * @property int $owned_through_effect_at
 * @property int $revision
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @method static Builder|PatrolCombatBarrier newModelQuery()
 * @method static Builder|PatrolCombatBarrier newQuery()
 * @method static Builder|PatrolCombatBarrier query()
 * @mixin \Eloquent
 */
#[Fillable([
    'patrol_id',
    'combat_instance_id',
    'opened_at',
    'owned_through_effect_at',
    'revision',
])]
class PatrolCombatBarrier extends Model
{
    /**
     * @return BelongsTo<CombatInstance, $this>
     */
    public function combat(): BelongsTo
    {
        return $this->belongsTo(CombatInstance::class, 'combat_instance_id');
    }

    /**
     * @return BelongsTo<Patrol, $this>
     */
    public function patrol(): BelongsTo
    {
        return $this->belongsTo(Patrol::class, 'patrol_id');
    }

    /**
     * Si cet instant d effet appartient encore a ce combat.
     *
     * Meme regle que sur la barriere du corps celeste, et pour les memes raisons : **l instant
     * compare est l heure planifiee de l effet, jamais celle du traitement**, sans quoi un
     * travailleur en retard ferait changer de combat un evenement qui appartenait a celui-ci.
     *
     * La borne est **fermee du meme cote que partout ailleurs** : une egalite compte pour « apres »,
     * donc pour le combat suivant. En espace libre la fenetre est nulle — personne ne peut rejoindre
     * dans cette version — et cette methode rend donc faux des l instant d ouverture.
     */
    public function ownsEffectAt(int $plannedAt): bool
    {
        return $plannedAt < $this->owned_through_effect_at;
    }
}
