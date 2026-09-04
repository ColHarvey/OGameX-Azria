<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OGame\Combat\Enums\CombatReasonCode;
use OGame\Combat\Enums\FleetDispositionKind;

/**
 * Ce qu'une flotte doit faire, decide par le combat et conserve jusqu'a ce qu'elle le fasse.
 *
 * ## Pourquoi une decision ecrite, et non deduite
 *
 * Le demi-tour d'une flotte refusee etait rededuit au moment du traitement : on cherchait la barriere
 * du corps, on retrouvait le combat, on constatait que la flotte n'y etait pas inscrite. Cette
 * deduction cesse de fonctionner des que le combat se termine — la barriere est levee au reglement,
 * et il ne reste plus rien a interroger.
 *
 * Une Defense ACS refusee dont le stationnement s'acheve longtemps apres la bataille suivait alors
 * son chemin ordinaire : elle stationnait hors photographie, ce que la regle interdit, et la boite
 * d'envoi annoncait un refus dont le mouvement n'arrivait jamais.
 *
 * La decision est donc ecrite dans la transaction qui la produit. Elle survit a la fin du combat, a
 * la levee de la barriere et a n'importe quel retard du travailleur.
 *
 * ## Consommee une fois, par celui qui la fait
 *
 * `consumed_at` dit si le mouvement a eu lieu. C'est lui qu'un second passage relit, sous verrou,
 * plutot qu'un drapeau porte par la mission : la disposition et sa consommation vivent au meme
 * endroit, et ne peuvent donc pas se contredire.
 *
 * @property int $id
 * @property int $fleet_mission_id
 * @property int $combat_instance_id
 * @property FleetDispositionKind $movement
 * @property CombatReasonCode $reason
 * @property int $decided_at
 * @property int|null $consumed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read FleetMission $fleetMission
 * @property-read CombatInstance $combatInstance
 */
#[Fillable([
    'fleet_mission_id',
    'combat_instance_id',
    'movement',
    'reason',
    'decided_at',
    'consumed_at',
])]
class CombatFleetDisposition extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'movement' => FleetDispositionKind::class,
            'reason' => CombatReasonCode::class,
        ];
    }

    /**
     * La mission qui doit faire ce mouvement.
     *
     * @return BelongsTo<FleetMission, $this>
     */
    public function fleetMission(): BelongsTo
    {
        return $this->belongsTo(FleetMission::class);
    }

    /**
     * Le combat qui l'a decide — souvent termine quand on relit la ligne.
     *
     * @return BelongsTo<CombatInstance, $this>
     */
    public function combatInstance(): BelongsTo
    {
        return $this->belongsTo(CombatInstance::class);
    }

    /**
     * Le mouvement reste-t-il a faire ?
     */
    public function isPending(): bool
    {
        return $this->consumed_at === null;
    }
}
