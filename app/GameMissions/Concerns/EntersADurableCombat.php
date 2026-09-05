<?php

namespace OGame\GameMissions\Concerns;

use Closure;
use Illuminate\Support\Facades\Date;
use OGame\Combat\Enums\CombatReasonCode;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Services\CombatOpeningService;
use OGame\Combat\Services\RefusedFleetHomecoming;
use OGame\Combat\Support\RefusedFleetVerdict;
use OGame\Combat\Support\ReturnOrder;
use OGame\Models\CombatInstance;
use OGame\Models\CombatParticipant;
use OGame\Models\FleetMission;

/**
 * L'arrivee d'un genre qui **ouvre** un combat durable : entrer, ou repartir.
 *
 * ## Pourquoi un trait, et pas une copie
 *
 * Deux genres ouvrent un combat sur le corps qu'ils visent : l'attaque et la destruction de lune
 * (`CombatMissionKind::opensCombat()`). Cette entree vivait dans `AttackMission` seule, en methodes
 * privees ; la destruction de lune, elle, se reglait comme si l'interrupteur n'existait pas — un
 * combat durable ne s'ouvrait jamais sur une lune, et le plan de destruction gele depuis le §27
 * n'etait appele nulle part. Une copie aurait diverge au premier changement de regle.
 *
 * ## Ce que l'entree fait, et dans quel ordre
 *
 * Elle est appelee **sous la porte des mouvements**, sur une mission relue sous verrou :
 *
 * 1. deja traitee, rien a faire ;
 * 2. porteuse d'une disposition decidee par un combat, elle execute ce verdict et s'arrete ;
 * 3. sinon elle ouvre le combat du corps, ou le rejoint ;
 * 4. admise — le ralliement court, ou elle est inscrite — elle porte le lien du combat ;
 * 5. refusee, elle rentre par la route unique, avec `RallyClosed`.
 *
 * ## Pourquoi ces methodes restent privees
 *
 * PHP recopie les methodes d'un trait dans chaque classe qui l'emploie : `private` y garde tout son
 * sens, et la garantie que `FleetMovementGateTest` exige — aucune decision appelable avec un modele
 * jamais relu sous verrou — tient sans etre affaiblie par le partage.
 */
trait EntersADurableCombat
{
    private function enterOrLeaveTheCombat(FleetMission $mission, int $targetBodyId): void
    {
        if ((int)$mission->processed === 1) {
            return;
        }

        if ($this->followTheMovementAlreadyDecided($mission)) {
            return;
        }

        $combat = resolve(CombatOpeningService::class)->openOrJoin($mission, $targetBodyId, (int)$mission->time_arrival);

        if ($combat->status === CombatState::Rallying || $this->belongsToCombat($mission, $combat)) {
            $mission->combat_instance_id = $combat->id;
            $mission->save();

            return;
        }

        $this->sendItHomeAfterTheRallyClosed($mission, $combat);
    }

    /**
     * Cette flotte est-elle inscrite a ce combat ? L'inscription est la preuve d'admission ; le lien
     * de la mission suit, il ne precede pas.
     */
    private function belongsToCombat(FleetMission $mission, CombatInstance $combat): bool
    {
        return CombatParticipant::query()
            ->where('combat_instance_id', $combat->id)
            ->where('fleet_mission_id', $mission->id)
            ->exists();
    }

    private function sendItHomeAfterTheRallyClosed(FleetMission $mission, CombatInstance $combat): void
    {
        $this->goHome(
            $mission,
            fn (FleetMission $tenue): RefusedFleetVerdict => new RefusedFleetVerdict(
                $combat,
                CombatReasonCode::RallyClosed,
                ReturnOrder::physicalArrivalOf($tenue)
            )
        );
    }

    private function followTheMovementAlreadyDecided(FleetMission $mission): bool
    {
        return $this->carryOutTheMovementAlreadyDecided($mission, (int)Date::now()->timestamp);
    }

    private function goHome(FleetMission $mission, Closure|null $juger): bool
    {
        return resolve(RefusedFleetHomecoming::class)->sendHome(
            $mission,
            (int)Date::now()->timestamp,
            $this->returnOfARefusedFleet(),
            $juger
        );
    }
}
