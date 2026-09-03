<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * La preuve qu'un effet a ete applique au monde, et une seule fois.
 *
 * ## L'identite ne suffit pas
 *
 * Un identifiant d'evenement dit « c'est le meme evenement ». Il ne dit pas « c'est le meme effet ».
 * Un evenement rejoue apres qu'une regle a change produirait un effet different sous la meme
 * identite, et un recu fonde sur la seule identite le laisserait passer pour deja applique.
 *
 * Le recu porte donc la version de la regle, l'empreinte canonique de l'effet, et l'agregat sur
 * lequel il a porte. Deux effets de meme identite mais d'empreinte differente sont **un defaut, pas
 * un doublon** — et la comparaison le rend visible au lieu de l'absorber.
 *
 * ## Ce recu ne dit rien de la photographie
 *
 * « Applique au monde » et « inclus dans cette photographie » sont deux questions differentes, et un
 * meme effet peut legitimement figurer dans plusieurs photographies — deux combats successifs sur la
 * meme planete lisent tous deux la garnison. C'est `CombatSnapshotInclusion` qui repond a l'autre.
 *
 * @property int $id
 * @property string $event_identity
 * @property int|null $combat_instance_id
 * @property string $kind_version
 * @property string $effect_fingerprint
 * @property string $aggregate_key
 * @property int $applied_at
 * @property string $receipt_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @method static Builder|CombatEffectReceipt newModelQuery()
 * @method static Builder|CombatEffectReceipt newQuery()
 * @method static Builder|CombatEffectReceipt query()
 * @mixin \Eloquent
 */
#[Fillable([
    'event_identity',
    'combat_instance_id',
    'kind_version',
    'effect_fingerprint',
    'aggregate_key',
    'applied_at',
    'receipt_id',
])]
class CombatEffectReceipt extends Model
{
    /**
     * Si ce recu decrit exactement le meme effet qu'on s'apprete a appliquer.
     *
     * **Un recu qui ne correspond pas n'est pas un feu vert.** Meme identite, autre empreinte : le
     * rejeu porte un effet different de celui qui a ete applique, et l'appliquer par-dessus
     * doublerait ou contredirait le premier. C'est a l'appelant de lever, pas de continuer.
     */
    public function describesTheSameEffect(string $kindVersion, string $effectFingerprint): bool
    {
        return $this->kind_version === $kindVersion
            && $this->effect_fingerprint === $effectFingerprint;
    }
}
