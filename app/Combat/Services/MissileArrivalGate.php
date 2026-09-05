<?php

namespace OGame\Combat\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OGame\Combat\Enums\CombatMissionKind;
use OGame\Combat\Enums\CombatReasonCode;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Enums\InvariantCode;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameMessages\CombatRallyRefused;
use OGame\Models\CelestialBodyCombatBarrier;
use OGame\Models\FleetMission;
use OGame\Services\MessageService;
use RuntimeException;

/**
 * Ce qu'un missile fait en arrivant sur un corps qu'un combat tient.
 *
 * ## Les trois verdicts de la matrice, enfin raccordes
 *
 * `CombatDecisionMatrix` les decrivait depuis le debut, et rien ne les executait : `updateMission()`
 * frappait a l'arrivee, quel que soit l'etat du combat. Ici, avant tout gestionnaire :
 *
 * | le combat est… | et le missile est parti… | verdict |
 * | --- | --- | --- |
 * | en ralliement | avant l'ouverture | **frappe** ; la fermeture lira son delta au registre |
 * | en ralliement | a l'ouverture ou apres | **anomalie** : le lancement aurait du etre refuse ; annule sans impact, missiles rendus (decision de Keven, revue 94), joueur averti, alerte ; silo disparu : **retenu**, rien n'est rendu ni dit rendu |
 * | en bataille ou en reglement | — | **differe** : il attend le resultat, puis frappe ce qui reste — une seule fois |
 * | final, ou sans combat | — | frappe |
 *
 * Un missile differe n'est pas traite : `processed` reste a zero, son arrivee reste dans le passe,
 * et le travailleur le represente a chaque passage jusqu'a ce que la barriere ait disparu. Il ne
 * peut frapper qu'une fois, par le meme `processed` que tout gestionnaire.
 *
 * ## Le sort des missiles d'une anomalie
 *
 * La matrice dit « annulation et alerte », pas ce que deviennent les missiles. Ils sont **rendus** au
 * silo de depart, **une fois** : Keven l'a tranche (revue 94) — ce n'est ni un droit de rappel des
 * missiles normalement lances, ni une autorisation de tirer sur une cible verrouillee. Le
 * remboursement est atomique et idempotent (`cancelWithoutImpact()`), et un silo disparu retient le
 * missile au lieu de perdre les actifs en silence.
 */
final class MissileArrivalGate
{
    public const string APPLY = 'apply';

    public const string DEFER = 'defer';

    public const string CANCELLED = 'cancelled';

    /**
     * L'anomalie est reconnue mais le silo d'origine n'existe plus : le missile est **retenu**, non
     * traite, jusqu'a ce que l'exploitation tranche. Rien n'est perdu en silence, rien n'est annonce
     * comme rendu.
     */
    public const string HELD = 'held';

    /**
     * Decide, et execute l'annulation si c'en est une. Rend le verdict pour que l'appelant sache
     * s'il doit appeler le gestionnaire.
     */
    public function decide(FleetMission $mission): string
    {
        if ($mission->parent_id !== null || CombatMissionKind::fromMissionType((int)$mission->mission_type) !== CombatMissionKind::Missile) {
            return self::APPLY;
        }

        $corps = $mission->planet_id_to === null ? null : (int)$mission->planet_id_to;
        $barriere = $corps === null
            ? null
            : CelestialBodyCombatBarrier::query()->where('target_body_id', $corps)->first();

        if ($barriere === null) {
            return self::APPLY;
        }

        $combat = $barriere->combatInstance;
        if ($combat === null) {
            throw new RuntimeException('La barriere du corps ' . $corps . ' ne designe aucun combat : le missile ' . $mission->id . ' ne peut pas etre decide.');
        }

        if ($combat->status === CombatState::Active || $combat->status === CombatState::Resolving) {
            // **Differe.** « Un missile ne peut ni modifier une defense deja photographiee ni
            // disparaitre sans raison » : il attend le resultat, puis frappe ce qui reste.
            return self::DEFER;
        }

        if ($combat->status !== CombatState::Rallying) {
            // Un combat final dont la barriere survivrait ne tient plus rien : le missile frappe.
            return self::APPLY;
        }

        if ((int)$mission->time_departure < (int)$barriere->opened_at) {
            return self::APPLY;
        }

        return $this->cancelWithoutImpact($mission, $corps);
    }

    /**
     * Cree apres l'ouverture malgre le verrou : ni applique — la photographie est prise — ni
     * silencieux.
     */
    /**
     * Cree apres l'ouverture malgre le verrou : ni applique — la photographie est prise — ni
     * silencieux. **Le remboursement est unique et atomique** : la mission est relue sous verrou dans
     * une transaction, `processed` est pose **avant** le credit, et un second appelant — un autre
     * travailleur, la mise a jour d'un corps, l'administration — trouve la mission traitee et ne
     * rembourse rien. Une panne entre le credit et la validation ramene tout en arriere.
     *
     * Si le silo d'origine n'existe plus, rien n'est rendu et rien n'est dit rendu : le missile est
     * retenu, non traite, et le journal le nomme a chaque passage jusqu'a ce que l'exploitation tranche.
     */
    private function cancelWithoutImpact(FleetMission $mission, int $corps): string
    {
        return DB::transaction(function () use ($mission, $corps): string {
            $tenue = FleetMission::query()->whereKey($mission->id)->lockForUpdate()->first();
            if (!$tenue instanceof FleetMission) {
                throw new RuntimeException('Le missile ' . $mission->id . ' a disparu avant son annulation.');
            }
            if ((int)$tenue->processed === 1) {
                // Deja annule par un autre appelant : le remboursement a eu lieu une fois, pas deux.
                return self::CANCELLED;
            }

            $planetes = resolve(PlanetServiceFactory::class);
            $origine = $tenue->planet_id_from === null ? null : $planetes->make((int)$tenue->planet_id_from, true);
            $missiles = (int)$tenue->interplanetary_missile;

            if ($origine === null && $missiles > 0) {
                Log::warning('Missile lance apres l ouverture d un combat sur sa cible : annulation impossible, le silo d origine n existe plus ; missile retenu, rien n est rendu.', [
                    'invariant' => InvariantCode::EffectCreatedAfterTheLock->value,
                    'fleet_mission_id' => $tenue->id,
                    'target_body_id' => $corps,
                    'origin_body_id' => $tenue->planet_id_from,
                    'missiles_held' => $missiles,
                ]);

                return self::HELD;
            }

            // **`processed` d'abord, le credit ensuite**, dans la meme transaction : c'est l'ordre qui
            // rend un second appel inoffensif et une panne sans effet.
            $tenue->processed = 1;
            $tenue->save();

            if ($origine !== null && $missiles > 0) {
                $origine->addUnit('interplanetary_missile', $missiles);
            }

            Log::warning('Missile lance apres l ouverture d un combat sur sa cible : annule sans impact, missiles rendus.', [
                'invariant' => InvariantCode::EffectCreatedAfterTheLock->value,
                'fleet_mission_id' => $tenue->id,
                'target_body_id' => $corps,
                'time_departure' => (int)$tenue->time_departure,
                'missiles_returned' => $missiles,
            ]);

            $lanceur = $origine?->getPlayer();
            if ($lanceur !== null) {
                resolve(MessageService::class, ['player' => $lanceur])->sendSystemMessageToPlayer($lanceur, CombatRallyRefused::class, [
                    'coordinates' => '[coordinates]' . (int)$tenue->galaxy_to . ':' . (int)$tenue->system_to . ':' . (int)$tenue->position_to . '[/coordinates]',
                    'reason_code' => CombatReasonCode::TargetCombatLocked->value,
                ]);
            }

            return self::CANCELLED;
        });
    }
}
