<?php

namespace OGame\Combat\Services;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OGame\Combat\Enums\CombatCancellationCause;
use OGame\Combat\Enums\CombatOutboxKind;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Exceptions\FleetHasNowhereToReturn;
use OGame\Combat\Exceptions\UnreturnableFleet;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Models\CelestialBodyCombatBarrier;
use OGame\Models\CombatInstance;
use OGame\Models\CombatOutboxMessage;
use OGame\Models\CombatParticipant;
use OGame\Models\FleetMission;
use OGame\Models\FleetUnion;
use OGame\Services\FleetMissionService;
use RuntimeException;

/**
 * L'annulation d'un combat durable : les flottes rentrent, le corps se libere, rien n'est applique.
 *
 * ## La sortie d'exploitation
 *
 * Un combat que le reglement ne sait plus appliquer — resultat fige corrompu, effectif qui ne
 * concorde plus, echelle que le stockage ne porte pas — est mis de cote apres cinq echecs. Laisse
 * tel quel, il tiendrait son corps pour toujours : aucune nouvelle barriere ne pourrait etre
 * posee, et ses flottes attendraient un reglement qui ne viendra pas.
 *
 * Ce service est ce chemin. Il **n'applique rien** : la bataille calculee n'est jamais ecrite, le
 * defenseur ne perd rien, l'attaquant ne prend rien. Les flottes attaquantes rentrent avec ce
 * qu'elles portaient, le corps est libere, et chaque joueur apprend pourquoi.
 *
 * ## Jamais par un joueur
 *
 * Aucune cause d'annulation n'est a la portee d'un joueur, et `CombatState::canBeCancelledFor()`
 * le verifie a chaque appel : un attaquant qui connait deja l'issue defavorable d'une bataille ne
 * peut pas la faire disparaitre. Un combat en cours d'application ou applique ne s'annule plus du
 * tout — ce qui a ete ecrit l'a ete.
 *
 * ## Audite
 *
 * La cause est persistee sur l'instance, chaque flotte renvoyee recoit un message avec cette
 * cause, et une ligne de journal nomme le combat, la cause et les flottes rendues. Une annulation
 * qu'on ne retrouve pas apres coup n'est pas une sortie d'exploitation, c'est une disparition.
 *
 * ## L'ordre des verrous
 *
 * Le meme que partout : barriere -> instance -> union -> missions par identifiant croissant.
 * Aucun corps n'est ecrit — les retours ne creditent rien avant d'arriver.
 */
final class CombatCancellationService
{
    public function __construct(
        private CombatRosterReader $roster = new CombatRosterReader(),
        private ReturnPlanner $planner = new ReturnPlanner(),
        private FleetMissionService|null $fleetMissions = null,
    ) {
    }

    /**
     * Annule le combat, ou explique pourquoi il n'y avait rien a annuler.
     *
     * @param Closure $creerRetour Cree une mission retour ; delegue a GameMission::startReturn(),
     *                              en lui donnant la duree du trajet aller, l'instant du depart et le
     *                              corps ou le plan de repli fait atterrir la flotte.
     */
    public function cancel(int $combatInstanceId, CombatCancellationCause $cause, Closure $creerRetour, int $now): CombatCancellationOutcome
    {
        return DB::transaction(function () use ($combatInstanceId, $cause, $creerRetour, $now): CombatCancellationOutcome {
            $barriere = CelestialBodyCombatBarrier::query()
                ->where('combat_instance_id', $combatInstanceId)
                ->lockForUpdate()
                ->first();

            $combat = CombatInstance::query()->whereKey($combatInstanceId)->lockForUpdate()->first();

            if ($combat === null) {
                return CombatCancellationOutcome::unknownCombat();
            }

            if ($combat->status->isFinal()) {
                return CombatCancellationOutcome::alreadyOver();
            }

            // **La machine d'etats tranche, pas ce service.** Elle refuse toute cause a la portee
            // d'un joueur, et tout combat dont l'application a commence.
            if (!$combat->status->canBeCancelledFor($cause)) {
                throw new RuntimeException(
                    'Le combat ' . $combat->id . ' en « ' . $combat->status->value . ' » ne peut pas etre annule pour « '
                    . $cause->value . ' ».'
                );
            }

            if ($combat->union_id !== null) {
                FleetUnion::query()->whereKey($combat->union_id)->lockForUpdate()->first();
            }

            $attaquantes = $this->roster->missionIdsOf($combat, CombatParticipant::SIDE_ATTACKER);
            $identifiants = array_values(array_unique(array_merge($attaquantes, [(int)$combat->mission_id])));
            sort($identifiants);

            $missions = FleetMission::query()
                ->whereIn('id', $identifiants)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            // **Tous les plans d'abord, l'etat ensuite.** Une flotte sans destination legitime, ou
            // dont le trajet aller ne se lit pas, arrete l'annulation : le corps reste tenu et rien
            // n'est ecrit. Liberer le corps en pretendant que toutes les flottes sont rendues serait
            // exactement la perte silencieuse que ce chemin existe pour eviter.
            $plans = [];

            foreach ($missions as $mission) {
                if ((int)$mission->processed === 1) {
                    continue;
                }

                $trajet = (int)$mission->time_arrival - (int)$mission->time_departure;

                if ($trajet < 1) {
                    throw new UnreturnableFleet($combat->id, $mission->id, $trajet);
                }

                $plan = $this->planner->planFor($mission);

                if (!$plan->isPossible() || $plan->planetId === null) {
                    throw new FleetHasNowhereToReturn($combat->id, $mission->id, $plan->reason?->value);
                }

                $plans[$mission->id] = [$plan, $trajet];
            }

            $combat->status = CombatState::Cancelled;
            $combat->cancellation_cause = $cause;
            $combat->save();

            // **Le corps se libere.** C'est tout l'objet : sans cela, l'annulation laisserait la
            // barriere qu'elle existe pour lever.
            $barriere?->delete();

            $rendues = 0;
            $dejaParties = 0;

            foreach ($missions as $mission) {
                // **Une inscrite deja traitee n'existe que par corruption ou reparation manuelle** :
                // l'arrivee ne traite pas, le rappel d'une engagee est refuse, et le reglement ne
                // traite qu'en rendant le combat final. C'est precisement l'etat qu'une sortie
                // d'exploitation rencontre — elle le tolere sans creer un second retour, et le dit.
                if ((int)$mission->processed === 1) {
                    $dejaParties++;

                    continue;
                }

                CombatOutboxMessage::query()->updateOrCreate(
                    [
                        'combat_instance_id' => $combat->id,
                        'participant_key' => CombatParticipantKey::forFleet($mission->id),
                        'kind' => CombatOutboxKind::CombatCancelled->value,
                    ],
                    [
                        'payload' => [
                            'cause' => $cause->value,
                            'target_body_id' => $combat->target_planet_id,
                            'galaxy' => $combat->galaxy,
                            'system' => $combat->system,
                            'position' => $combat->position,
                        ],
                        'available_at' => $now,
                    ]
                );

                // **Le retour se decrit, il ne se fabrique pas en reecrivant l'aller.** Il part de
                // l'instant d'annulation, dure le trajet aller, et se pose la ou son plan le dit ; la
                // mission aller garde son heure d'arrivee, qui est un fait de l'admission, de l'ordre
                // causal et de l'audit.
                [$plan, $trajet] = $plans[$mission->id];

                $mission->processed = 1;
                $mission->save();

                ($creerRetour)(
                    $mission,
                    $this->fleetMissions()->getResources($mission),
                    $this->fleetMissions()->getFleetUnits($mission),
                    $trajet,
                    $now,
                    (int)$plan->planetId
                );

                $rendues++;
            }

            Log::warning('Combat durable annule.', [
                'combat_instance_id' => $combat->id,
                'cause' => $cause->value,
                'target_body_id' => $combat->target_planet_id,
                'fleets_sent_home' => $rendues,
                'fleets_already_gone' => $dejaParties,
                'at' => $now,
            ]);

            return CombatCancellationOutcome::cancelled($rendues, $dejaParties);
        });
    }

    private function fleetMissions(): FleetMissionService
    {
        return $this->fleetMissions ??= resolve(FleetMissionService::class);
    }
}
