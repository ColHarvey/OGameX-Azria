<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Une recompense quotidienne reclamee : un compte, une journee du serveur, un montant.
 *
 * L absence de ligne pour aujourd hui vaut « pas encore reclamee ». La contrainte unique
 * (`user_id`, `reward_date`) est ce qui rend le double credit impossible.
 *
 * @property int $id
 * @property int $user_id
 * @property string $reward_date
 * @property int $amount
 * @property Carbon $claimed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @method static Builder|DailyReward newModelQuery()
 * @method static Builder|DailyReward newQuery()
 * @method static Builder|DailyReward query()
 * @mixin \Eloquent
 */
#[Fillable(['user_id', 'reward_date', 'amount', 'claimed_at'])]
#[Table(name: 'daily_rewards')]
class DailyReward extends Model
{
    /**
     * @var array<string, string>
     */
    protected $casts = [
        'user_id' => 'integer',
        // **Pas de transtypage en date.** Avec `date`, Eloquent ecrit « 2026-09-21 00:00:00 » et la
        // comparaison a la journee « 2026-09-21 » tombe a cote — mesure faite, trois essais rouges. La
        // colonne porte une JOURNEE, une chaine `Y-m-d`, et c est elle qui porte l unicite.
        'amount' => 'integer',
        'claimed_at' => 'datetime',
    ];
}
