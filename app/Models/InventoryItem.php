<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un objet de boutique possede par un joueur.
 *
 * Le catalogue lui-meme est defini en code dans ShopService : cette table ne porte que la
 * quantite possedee, comme les objets de jeu qui vivent dans app/GameObjects.
 *
 * @property int $id
 * @property int $user_id
 * @property string $item_ref
 * @property int $quantity
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @method static Builder|InventoryItem newModelQuery()
 * @method static Builder|InventoryItem newQuery()
 * @method static Builder|InventoryItem query()
 * @method static Builder|InventoryItem whereId($value)
 * @method static Builder|InventoryItem whereUserId($value)
 * @method static Builder|InventoryItem whereItemRef($value)
 * @method static Builder|InventoryItem whereQuantity($value)
 * @mixin \Eloquent
 */
#[Fillable([
    'user_id',
    'item_ref',
    'quantity',
])]
class InventoryItem extends Model
{
    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'quantity' => 'integer',
    ];

    /**
     * Get the user this inventory line belongs to.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
