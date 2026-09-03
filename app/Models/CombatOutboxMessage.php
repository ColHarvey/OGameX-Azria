<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ce qui reste a dire aux joueurs, ecrit dans la meme transaction que le resultat.
 *
 * ## Le probleme des deux ecritures
 *
 * Resoudre un combat fait deux choses : appliquer le resultat au monde, et en informer les joueurs.
 * Les faire separement laisse deux pannes possibles, mauvaises toutes les deux :
 *
 *     resultat applique, message perdu  -> le joueur voit sa flotte disparaitre sans rapport
 *     message envoye, resultat annule   -> le joueur lit un combat qui n'a pas eu lieu
 *
 * Le message est donc ecrit **ici**, dans la transaction du resultat, et envoye ensuite par un
 * lecteur separe. Annulee, la transaction emporte le message ; passee, le message existe et finira
 * par partir, meme apres un redemarrage.
 *
 * ## Une ligne par destinataire et par genre
 *
 * L'unicite porte sur combat / participant / genre : un rejeu de la resolution ne peut pas produire
 * deux rapports pour le meme joueur. La base le refuse, plutot que de compter sur le fait que la
 * resolution ne sera jamais rejouee.
 *
 * @property int $id
 * @property int $combat_instance_id
 * @property string $participant_key
 * @property string $kind
 * @property array<mixed>|null $payload
 * @property int $available_at
 * @property int|null $dispatched_at
 * @property int $attempts
 * @property string|null $last_error
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @method static Builder|CombatOutboxMessage newModelQuery()
 * @method static Builder|CombatOutboxMessage newQuery()
 * @method static Builder|CombatOutboxMessage query()
 * @mixin \Eloquent
 */
#[Fillable([
    'combat_instance_id',
    'participant_key',
    'kind',
    'payload',
    'available_at',
    'dispatched_at',
    'attempts',
    'last_error',
])]
// **Le nom de la table ne se deduit pas du nom de la classe.** Eloquent en ferait
// `combat_outbox_messages` ; la table s appelle `combat_outbox`, parce que c est une boite, pas
// une collection de messages.
#[Table(name: 'combat_outbox')]
class CombatOutboxMessage extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    /**
     * Le combat dont ce message rend compte.
     *
     * @return BelongsTo<CombatInstance, $this>
     */
    public function combatInstance(): BelongsTo
    {
        return $this->belongsTo(CombatInstance::class);
    }

    /**
     * Si ce message reste a envoyer.
     */
    public function isPending(): bool
    {
        return $this->dispatched_at === null;
    }
}
