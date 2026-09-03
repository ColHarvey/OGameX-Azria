<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ce qu'un evenement apporte a **une** photographie, et une seule fois.
 *
 * ## L'unicite porte sur un triplet, pas sur l'evenement
 *
 * Un meme evenement peut legitimement figurer dans plusieurs photographies : deux combats successifs
 * sur la meme planete lisent tous deux la garnison. Une unicite sur l'evenement seul aurait donc
 * fait disparaitre la garnison du second combat.
 *
 * Elle porte sur **combat / evenement**, et rien de plus. La projection en a d'abord fait partie ;
 * c'etait une erreur : une instance n'a qu'une projection gelee, et l'y laisser aurait permis a un
 * defaut d'ecrire le meme evenement deux fois sous deux versions. Les versions coexistent **entre**
 * deux combats, jamais dans une meme photographie.
 *
 * ## Un ensemble de contributions, jamais une seule
 *
 * Elles se cumulent : un retour charge apporte des vaisseaux **et** une cargaison. Une colonne a
 * valeur unique aurait force ces evenements en plusieurs lignes, et la ligne serait devenue l'unite
 * d'unicite a la place de l'evenement.
 *
 * @property int $id
 * @property int $combat_instance_id
 * @property string $event_identity
 * @property string $projection_version
 * @property array<int, string> $contributions
 * @property int $included_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @method static Builder|CombatSnapshotInclusion newModelQuery()
 * @method static Builder|CombatSnapshotInclusion newQuery()
 * @method static Builder|CombatSnapshotInclusion query()
 * @mixin \Eloquent
 */
#[Fillable([
    'combat_instance_id',
    'event_identity',
    'projection_version',
    'contributions',
    'included_at',
])]
class CombatSnapshotInclusion extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'contributions' => 'array',
        ];
    }

    /**
     * La photographie a laquelle cette inclusion appartient.
     *
     * @return BelongsTo<CombatInstance, $this>
     */
    public function combatInstance(): BelongsTo
    {
        return $this->belongsTo(CombatInstance::class);
    }
}
