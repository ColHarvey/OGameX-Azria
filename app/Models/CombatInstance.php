<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OGame\Combat\Enums\CombatCancellationCause;
use OGame\Combat\Enums\CombatState;

/**
 * Une bataille qui dure.
 *
 * Le resultat est calcule a l'arrivee de la flotte et conserve ici **sans etre applique**.
 * C'est la garantie centrale du systeme : recalculer a la fin laisserait le defenseur changer
 * retroactivement l'issue d'une bataille deja engagee.
 *
 * Le modele de duree — rythme, amortissement, plancher — est ecrit avec le combat, pas lu dans
 * les reglages a la resolution. Ajuster un reglage ne touche donc que les combats suivants.
 *
 * Les **faits geles** etendent ce principe a tout le reste : les cinq versions de regle,
 * l'alliance qui gouverne, les budgets, les reglages de champ d'epave, le plan de destruction
 * de lune. Un combat qui dure deux heures traverse des changements, et aucun ne doit changer
 * l'issue d'une bataille deja engagee.
 *
 * **Une colonne oubliee ici serait invisible.** Eloquent ignore silencieusement ce qui n'est pas
 * assignable : le fait cense etre fige ne le serait jamais, et personne ne s'en apercevrait
 * avant la resolution. C'est pourquoi un essai compare cette liste au schema.
 *
 * @property int $id
 * @property CombatState $status
 * @property CombatCancellationCause|null $cancellation_cause
 * @property int $mission_id
 * @property int|null $union_id
 * @property int|null $target_planet_id
 * @property int $target_type
 * @property int $galaxy
 * @property int $system
 * @property int $position
 * @property int|null $started_at
 * @property int|null $ends_at
 * @property int $duration_seconds
 * @property float $duration_rate
 * @property float $duration_damping
 * @property int $duration_minimum_seconds
 * @property bool $duration_implausible
 * @property array<mixed>|null $round_schedule
 * @property array<mixed>|null $battle_snapshot
 * @property array<mixed>|null $battle_result
 * @property int|null $battle_report_id
 * @property string|null $causal_order_version
 * @property string|null $loot_allocator_version
 * @property string|null $loot_policy_version
 * @property string|null $moon_destruction_rule_version
 * @property string|null $fingerprint_schema_version
 * @property string|null $opener_identity
 * @property int|null $founding_creator_id
 * @property int|null $governing_alliance_id
 * @property int|null $authoritative_arrival_at
 * @property string|null $schedule_version
 * @property int $max_fleets
 * @property int $max_players
 * @property int $fleets_admitted
 * @property int $players_admitted
 * @property array<mixed>|null $frozen_settings
 * @property array<mixed>|null $frozen_moon_identity
 * @property array<mixed>|null $moon_destruction_plan
 * @property string|null $frozen_facts_fingerprint
 * @property array<mixed>|null $frozen_alliance_membership
 * @property bool $result_published
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @method static Builder|CombatInstance newModelQuery()
 * @method static Builder|CombatInstance newQuery()
 * @method static Builder|CombatInstance query()
 * @mixin \Eloquent
 */
#[Fillable([
    'status',
    'cancellation_cause',
    'mission_id',
    'union_id',
    'target_planet_id',
    'target_type',
    'galaxy',
    'system',
    'position',
    'started_at',
    'ends_at',
    'duration_seconds',
    'duration_rate',
    'duration_damping',
    'duration_minimum_seconds',
    'duration_implausible',
    'round_schedule',
    'battle_snapshot',
    'battle_result',
    'battle_report_id',
    'causal_order_version',
    'loot_allocator_version',
    'loot_policy_version',
    'moon_destruction_rule_version',
    'fingerprint_schema_version',
    'opener_identity',
    'founding_creator_id',
    'governing_alliance_id',
    'authoritative_arrival_at',
    'schedule_version',
    'max_fleets',
    'max_players',
    'fleets_admitted',
    'players_admitted',
    'frozen_settings',
    'frozen_moon_identity',
    'moon_destruction_plan',
    'frozen_facts_fingerprint',
    'frozen_alliance_membership',
    'result_published',
])]
class CombatInstance extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CombatState::class,
            'cancellation_cause' => CombatCancellationCause::class,
            'duration_rate' => 'float',
            'duration_damping' => 'float',
            'duration_implausible' => 'boolean',
            'round_schedule' => 'array',
            'battle_snapshot' => 'array',
            'battle_result' => 'array',
            'frozen_settings' => 'array',
            'frozen_moon_identity' => 'array',
            'moon_destruction_plan' => 'array',
            'frozen_alliance_membership' => 'array',
            'result_published' => 'boolean',
        ];
    }

    /**
     * Get the participants of this combat.
     *
     * @return HasMany<CombatParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(CombatParticipant::class);
    }

    /**
     * Get whether the targeted celestial body is locked by this combat.
     *
     * Le verrou couvre `Pending` autant qu'`Active` : entre l'arrivee et le premier round, le
     * resultat est deja fige, et laisser partir une flotte la ferait echapper a une bataille
     * qui la compte deja parmi les defenseurs.
     */
    public function locksTargetBody(): bool
    {
        return $this->status->locksTargetBody();
    }
}
