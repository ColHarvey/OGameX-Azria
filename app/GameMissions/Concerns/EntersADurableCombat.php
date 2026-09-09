<?php

namespace OGame\GameMissions\Concerns;

use Closure;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Alliance\AllianceOffensiveGuard;
use OGame\Combat\Enums\CombatMissionKind;
use OGame\Combat\Enums\CombatReasonCode;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Services\CombatOpeningService;
use OGame\Combat\Services\RefusedFleetHomecoming;
use OGame\Combat\Support\RefusedFleetVerdict;
use OGame\Combat\Support\ReturnOrder;
use OGame\GameMessages\AttackCancelledByAllianceProtection;
use OGame\Models\CombatInstance;
use OGame\Models\CombatParticipant;
use OGame\Models\FleetMission;
use OGame\Models\Planet\Coordinate;

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
 * 3. visant desormais un membre de sa propre alliance, elle fait demi-tour sans rien ouvrir ni
 *    rejoindre — **la lecture qui l'autorise vit ici, sous le rendez-vous qui protege l'action** ;
 * 4. sinon elle ouvre le combat du corps, ou le rejoint ;
 * 5. admise — le ralliement court, ou elle est inscrite — elle porte le lien du combat ;
 * 6. refusee, elle rentre par la route unique, avec `RallyClosed`.
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

        if ($this->theAllianceForbidsThisArrival($mission, $targetBodyId)) {
            $this->turnBackBecauseTheTargetIsNowAnAlly($mission);

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
     * L'appartenance interdit-elle cette arrivee ? **Lue ici, et nulle part ailleurs pour decider.**
     *
     * ## Pourquoi la decision a demenage jusqu'ici
     *
     * Elle vivait dans `AttackMission::processArrival()`, avant l'aiguillage du combat durable —
     * donc hors de toute transaction et hors du rendez-vous. Une course du bac MariaDB l'a prise en
     * faute : l'arrivee lisait « pas allies », l'adhesion prenait sa barriere, ecrivait, commitait,
     * puis l'arrivee prenait la barriere a son tour et ouvrait le combat sur sa lecture d'avant.
     * **La barriere protegeait l'action ; la decision, elle, etait restee dehors.**
     *
     * Ici, la porte tient deja le rendez-vous des deux joueurs, et la lecture est verrouillante :
     * l'adhesion ne peut ni s'etre glissee entre la lecture et l'ecriture, ni rester invisible
     * derriere la photographie de la transaction.
     *
     * ## Et l'admission compte autant que l'ouverture
     *
     * Le controle est place **avant `openOrJoin()`**, qui est la seule porte des deux : rejoindre un
     * combat deja ouvert est aussi une offensive contre un allie, et un controle qui n'aurait ferme
     * que la creation aurait laisse passer la vague suivante.
     *
     * Le genre vient de la mission relue : l'attaque et la destruction de lune passent toutes deux
     * par ce trait, et cette derniere n'avait aucun controle d'arrivee.
     */
    private function theAllianceForbidsThisArrival(FleetMission $mission, int $targetBodyId): bool
    {
        return resolve(AllianceOffensiveGuard::class)->forbidsUnderTheRendezvous(
            CombatMissionKind::fromMissionType((int)$mission->mission_type),
            (int)$mission->user_id,
            $this->ownerOfTheTargetBody($targetBodyId)
        );
    }

    /**
     * Le proprietaire du corps vise — **celui-la meme sur qui le rendez-vous a ete pris**.
     *
     * La porte lit le proprietaire de la meme facon pour choisir les barrieres a prendre. Lire
     * autrement — un service de planete charge avant la porte, par exemple — donnerait une decision
     * portant sur un joueur dont le rendez-vous n'a pas ete tenu.
     */
    private function ownerOfTheTargetBody(int $targetBodyId): int|null
    {
        $proprietaire = DB::table('planets')->where('id', $targetBodyId)->value('user_id');

        return $proprietaire === null ? null : (int)$proprietaire;
    }

    /**
     * Demi-tour : rien n'est ouvert, rien n'est rejoint, et la flotte rentre une fois.
     *
     * **Le demi-tour vit sous la meme protection que la decision.** Ecrit avant la porte, il serait
     * un second ecrivain du mouvement de cette flotte — exactement ce que la porte existe pour
     * interdire : un rappel simultane creerait un deuxieme retour.
     *
     * Ce n'est pas la route de refus des combats : celle-la compose sa decision et son avis depuis
     * une instance, et il n'y en a aucune — la flotte s'arrete avant toute ouverture. `processed`
     * est pose avant le retour, donc un traitement relance ne cree pas un second retour ; vaisseaux
     * et cargaison rentrent tels quels, et le carburant suit la regle ordinaire du trajet.
     *
     * L'avis part dans la transaction de la porte : une tentative annulee n'en laisse aucun.
     */
    private function turnBackBecauseTheTargetIsNowAnAlly(FleetMission $mission): void
    {
        $mission->processed = 1;
        $mission->save();

        $this->startReturn(
            $mission,
            $this->fleetMissionService->getResources($mission),
            $this->fleetMissionService->getFleetUnits($mission)
        );

        $this->messageService->sendSystemMessageToPlayer(
            $this->playerServiceFactory->make((int)$mission->user_id, true),
            AttackCancelledByAllianceProtection::class,
            [
                'coordinates' => (new Coordinate(
                    (int)$mission->galaxy_to,
                    (int)$mission->system_to,
                    (int)$mission->position_to
                ))->asString(),
            ]
        );
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
