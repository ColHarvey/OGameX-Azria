<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un ordre de reparation de survivants au chantier spatial.
 *
 * **Le verrou n est pas ici, il est dans la base.** `active_on_planet_id` est unique : tant qu un
 * ordre court, la colonne porte l identifiant de la planete et un second ordre est **physiquement**
 * refuse. A la terminaison elle repasse a `null`, et plusieurs `null` ne se genent pas. Aucune
 * verification applicative ne tient cette regle — deux requetes simultanees la passeraient toutes
 * les deux.
 *
 * **La progression ne s ecrit jamais** : elle se calcule (`repairedShareAt()`), et c est ce qui rend
 * l idempotence structurelle. Le seul effet qui doit n avoir lieu qu une fois — rendre les unites —
 * est marque par `settled_at`, pose dans la transaction qui le produit.
 *
 * @property int $id
 * @property int $planet_id
 * @property int $player_id
 * @property int|null $active_on_planet_id
 * @property array<string, array<int, int>> $units
 * @property int $cost_metal
 * @property int $cost_crystal
 * @property int $cost_deuterium
 * @property int $dock_level
 * @property int $started_at
 * @property int $completed_at
 * @property string $status
 * @property int|null $settled_at
 * @property string|null $ended_because
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @method static Builder|HullRepairOrder newModelQuery()
 * @method static Builder|HullRepairOrder newQuery()
 * @method static Builder|HullRepairOrder query()
 * @mixin \Eloquent
 */
#[Fillable([
    'planet_id',
    'player_id',
    'active_on_planet_id',
    'units',
    'cost_metal',
    'cost_crystal',
    'cost_deuterium',
    'dock_level',
    'started_at',
    'completed_at',
    'status',
    'settled_at',
    'ended_because',
])]
class HullRepairOrder extends Model
{
    public const string STATUS_REPAIRING = 'repairing';
    public const string STATUS_SETTLED = 'settled';
    public const string STATUS_CANCELLED = 'cancelled';

    /**
     * Les trois fins anticipees, qui empruntent toutes le **meme** chemin : figer la coque
     * interpolee, rendre les unites, rembourser la part non faite.
     */
    public const string BECAUSE_PLAYER = 'player';
    public const string BECAUSE_DOCK_LOST = 'dock_lost';
    public const string BECAUSE_COMBAT = 'combat';

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'units' => 'array',
    ];

    /**
     * @return BelongsTo<Planet, $this>
     */
    public function planet(): BelongsTo
    {
        return $this->belongsTo(Planet::class, 'planet_id');
    }

    /**
     * La part du travail accomplie a cet instant, entre 0 et 1.
     *
     * **Fonction pure du temps** : aucune lecture, aucune ecriture, aucun effet. C est ce qui permet
     * a la reparation d etre progressive sans travailleur qui la fasse avancer, et ce qui rend deux
     * lectures successives forcement d accord.
     *
     * Une duree nulle ou negative n existe pas — le devis impose un plancher — mais si elle
     * survenait, le travail serait entier plutot que de diviser par zero.
     */
    public function repairedShareAt(int $instant): float
    {
        $duree = $this->completed_at - $this->started_at;

        if ($duree <= 0) {
            return 1.0;
        }

        $ecoule = $instant - $this->started_at;

        if ($ecoule <= 0) {
            return 0.0;
        }

        if ($ecoule >= $duree) {
            return 1.0;
        }

        return $ecoule / $duree;
    }

    /**
     * L ordre court-il encore a cet instant ?
     */
    public function isRunning(): bool
    {
        return $this->status === self::STATUS_REPAIRING;
    }

    /**
     * L echeance est-elle passee ?
     */
    public function isDueAt(int $instant): bool
    {
        return $this->isRunning() && $instant >= $this->completed_at;
    }
}
