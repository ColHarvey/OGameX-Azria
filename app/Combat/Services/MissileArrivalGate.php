<?php

namespace OGame\Combat\Services;

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
 * | en ralliement | a l'ouverture ou apres | **anomalie** : le lancement aurait du etre refuse ; annule sans impact, missiles rendus, joueur averti, alerte |
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
 * silo de depart : le joueur n'a rien fait d'autre que passer par une porte que le serveur a
 * laissee ouverte une seconde de trop, et les detruire serait la punition d'une course qui n'est
 * pas la sienne. C'est un choix d'implementation, dit au journal, que Keven peut renverser.
 */
final class MissileArrivalGate
{
    public const string APPLY = 'apply';

    public const string DEFER = 'defer';

    public const string CANCELLED = 'cancelled';

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

        $this->cancelWithoutImpact($mission, $corps);

        return self::CANCELLED;
    }

    /**
     * Cree apres l'ouverture malgre le verrou : ni applique — la photographie est prise — ni
     * silencieux.
     */
    private function cancelWithoutImpact(FleetMission $mission, int $corps): void
    {
        $planetes = resolve(PlanetServiceFactory::class);
        $origine = $mission->planet_id_from === null ? null : $planetes->make((int)$mission->planet_id_from, true);
        $missiles = (int)$mission->interplanetary_missile;

        if ($origine !== null && $missiles > 0) {
            $origine->addUnit('interplanetary_missile', $missiles);
        }

        $mission->processed = 1;
        $mission->save();

        Log::warning('Missile lance apres l ouverture d un combat sur sa cible : annule sans impact, missiles rendus.', [
            'invariant' => InvariantCode::EffectCreatedAfterTheLock->value,
            'fleet_mission_id' => $mission->id,
            'target_body_id' => $corps,
            'time_departure' => (int)$mission->time_departure,
            'missiles_returned' => $origine === null ? 0 : $missiles,
        ]);

        $lanceur = $origine?->getPlayer();
        if ($lanceur !== null) {
            resolve(MessageService::class, ['player' => $lanceur])->sendSystemMessageToPlayer($lanceur, CombatRallyRefused::class, [
                'coordinates' => '[coordinates]' . (int)$mission->galaxy_to . ':' . (int)$mission->system_to . ':' . (int)$mission->position_to . '[/coordinates]',
                'reason_code' => CombatReasonCode::TargetCombatLocked->value,
            ]);
        }
    }
}
