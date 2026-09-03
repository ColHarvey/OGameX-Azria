<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OGame\Combat\Enums\LootReservationState;

/**
 * Ce que le defenseur ne peut plus mettre a l'abri.
 *
 * ## La voie du milieu
 *
 * Le butin est calcule sur les ressources presentes a la photographie. Sans rien d'autre, le
 * defenseur passe les deux heures du combat a vider ses caisses. A l'inverse, tout geler le
 * punirait d'etre attaque : il ne pourrait plus rien construire, defenses comprises.
 *
 * Seule la **part pillable** est immobilisee. Ce qui est produit pendant la bataille appartient au
 * defenseur.
 *
 * ## Le passage qui n'existe pas
 *
 *     OPEN ──→ SEALED ──→ SETTLED
 *       │
 *       └────→ CANCELLED
 *
 * `CANCELLED` n'est accessible que depuis `OPEN`. Le placer apres `SETTLED` laisserait entendre
 * qu'une reservation deja reglee peut etre annulee : le butin preleve **puis** les fonds liberes,
 * c'est-a-dire verses deux fois.
 *
 * @property int $id
 * @property int $combat_instance_id
 * @property int $target_body_id
 * @property int $metal
 * @property int $crystal
 * @property int $deuterium
 * @property LootReservationState $state
 * @property int $opened_at
 * @property int|null $sealed_at
 * @property int|null $settled_at
 * @property string|null $last_raise_reason
 * @property int|null $last_raise_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @method static Builder|CombatLootReservation newModelQuery()
 * @method static Builder|CombatLootReservation newQuery()
 * @method static Builder|CombatLootReservation query()
 * @mixin \Eloquent
 */
#[Fillable([
    'combat_instance_id',
    'target_body_id',
    'metal',
    'crystal',
    'deuterium',
    'state',
    'opened_at',
    'sealed_at',
    'settled_at',
    'last_raise_reason',
    'last_raise_at',
])]
class CombatLootReservation extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => LootReservationState::class,
        ];
    }

    /**
     * Le combat qui tient cette reservation.
     *
     * @return BelongsTo<CombatInstance, $this>
     */
    public function combatInstance(): BelongsTo
    {
        return $this->belongsTo(CombatInstance::class);
    }

    /**
     * Si la borne peut encore etre relevee.
     *
     * Uniquement pendant le ralliement. La regle vit sur l'enumeration, qui la porte pour tout le
     * monde : la reecrire ici en ferait une seconde verite.
     */
    public function acceptsARaise(): bool
    {
        return $this->state->acceptsARaise();
    }
}
