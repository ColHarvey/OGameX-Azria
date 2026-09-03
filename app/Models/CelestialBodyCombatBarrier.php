<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un corps celeste, un combat a la fois.
 *
 * ## Ce que la table garantit, et que le code ne pouvait pas
 *
 * « Ce corps est-il en combat ? » se repondait par une lecture. Une lecture ne verrouille rien :
 * deux flottes arrivant a la meme seconde lisaient toutes les deux « libre » et ouvraient deux
 * combats sur la meme planete, le second effacant la photographie du premier.
 *
 * `target_body_id` est **unique**. La base refuse la seconde insertion, et le perdant de la course
 * apprend qu'il rejoint au lieu d'ouvrir. C'est elle qui arbitre, pas l'ordre dans lequel deux
 * workers ont eu la main.
 *
 * ## La premiere prise du verrou global
 *
 *     1. barriere du corps celeste
 *     2. instance de combat
 *     3. union, puis missions par identifiant croissant
 *     4. reservation de butin
 *
 * Deux transactions qui prennent ces verrous dans un ordre different se bloquent mutuellement.
 * L'ordre est une decision, et celle-ci est le premier maillon.
 *
 * @property int $id
 * @property int $target_body_id
 * @property int $combat_instance_id
 * @property int $opened_at
 * @property int $owned_through_effect_at
 * @property int $revision
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @method static Builder|CelestialBodyCombatBarrier newModelQuery()
 * @method static Builder|CelestialBodyCombatBarrier newQuery()
 * @method static Builder|CelestialBodyCombatBarrier query()
 * @mixin \Eloquent
 */
#[Fillable([
    'target_body_id',
    'combat_instance_id',
    'opened_at',
    'owned_through_effect_at',
    'revision',
])]
class CelestialBodyCombatBarrier extends Model
{
    /**
     * Le combat que cette barriere protege.
     *
     * @return BelongsTo<CombatInstance, $this>
     */
    public function combatInstance(): BelongsTo
    {
        return $this->belongsTo(CombatInstance::class);
    }

    /**
     * Si cet instant d'effet appartient encore a ce combat.
     *
     * **L'instant compare est l'heure planifiee de l'effet, jamais celle du traitement.** Un worker
     * en retard ne doit pas faire changer de combat un evenement qui appartenait a celui-ci : le
     * retard est un fait du serveur, pas un choix du joueur.
     *
     * La borne est **fermee du meme cote que les autres** : une egalite compte pour « apres », donc
     * pour le combat suivant.
     */
    public function ownsEffectAt(int $plannedAt): bool
    {
        return $plannedAt < $this->owned_through_effect_at;
    }
}
