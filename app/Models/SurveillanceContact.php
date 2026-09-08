<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ce qu un Reseau de surveillance sait d une patrouille etrangere dans son systeme.
 *
 * Derive des faits (entree dans le systeme, delai du niveau), revoque quand la patrouille quitte le
 * systeme ou disparait, ou quand le reseau tombe. Le niveau d information se lit sur la planete
 * observatrice au moment de la lecture, jamais ici. Voir la migration `create_surveillance_contacts_table`.
 *
 * @property int $id
 * @property int $observer_planet_id
 * @property int $observer_user_id
 * @property int $patrol_id
 * @property int $entered_system_at
 * @property int $visible_from
 * @property int|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @method static Builder|SurveillanceContact newModelQuery()
 * @method static Builder|SurveillanceContact newQuery()
 * @method static Builder|SurveillanceContact query()
 * @mixin \Eloquent
 */
#[Fillable([
    'observer_planet_id',
    'observer_user_id',
    'patrol_id',
    'entered_system_at',
    'visible_from',
    'revoked_at',
])]
class SurveillanceContact extends Model
{
    /**
     * @return BelongsTo<Patrol, $this>
     */
    public function patrol(): BelongsTo
    {
        return $this->belongsTo(Patrol::class, 'patrol_id');
    }

    /**
     * @return BelongsTo<Planet, $this>
     */
    public function observerPlanet(): BelongsTo
    {
        return $this->belongsTo(Planet::class, 'observer_planet_id');
    }

    /**
     * Le contact est acquis et non revoque a cet instant.
     */
    public function isVisibleAt(int $now): bool
    {
        return $this->revoked_at === null && (int)$this->visible_from <= $now;
    }
}
