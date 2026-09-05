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
 * @property string|null $broadcast_status Le dernier etat annonce aux joueurs, nul tant qu aucun ne l a ete.
 * @property CombatCancellationCause|null $cancellation_cause
 * @property string|null $cancellation_note Ce que l'administrateur a ecrit en annulant.
 * @property int|null $cancelled_at L'instant de l'annulation, en secondes.
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
 * @property array<mixed>|null $opening_state L etat protege du corps a l ouverture, avec sa provenance.
 * @property string|null $opening_state_fingerprint L empreinte de cet etat : une relecture constate une divergence.
 * @property int|null $opening_captured_at L instant de la capture, qui est celui de l ouverture.
 * @property array<mixed>|null $frozen_moon_identity
 * @property array<mixed>|null $moon_destruction_plan
 * @property string|null $frozen_facts_fingerprint
 * @property array<mixed>|null $frozen_alliance_membership
 * @property string|null $projection_version
 * @property string|null $presentation_version La regle de presentation sous laquelle le fil a ete ecrit.
 * @property int|null $potential_loot_metal
 * @property int|null $potential_loot_crystal
 * @property int|null $potential_loot_deuterium
 * @property int|null $potential_loot_rate_in_basis_points
 * @property int|null $potential_loot_frozen_at
 * @property string|null $loot_snapshot_fingerprint
 * @property int|null $applied_loot_metal
 * @property int|null $applied_loot_crystal
 * @property int|null $applied_loot_deuterium
 * @property int|null $loot_settled_at
 * @property int $advance_attempts
 * @property string|null $advance_last_error
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
    'broadcast_status',
    'opening_state',
    'opening_state_fingerprint',
    'opening_captured_at',
    'cancellation_cause',
    'cancellation_note',
    'cancelled_at',
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
    'projection_version',
    'presentation_version',
    'potential_loot_metal',
    'potential_loot_crystal',
    'potential_loot_deuterium',
    'potential_loot_rate_in_basis_points',
    'potential_loot_frozen_at',
    'loot_snapshot_fingerprint',
    'applied_loot_metal',
    'applied_loot_crystal',
    'applied_loot_deuterium',
    'loot_settled_at',
    'advance_attempts',
    'advance_last_error',
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
            'opening_state' => 'array',
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
