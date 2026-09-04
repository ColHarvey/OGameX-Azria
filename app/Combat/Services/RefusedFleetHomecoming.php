<?php

namespace OGame\Combat\Services;

use Closure;
use OGame\Combat\Enums\CombatOutboxKind;
use OGame\Combat\Enums\FleetDispositionKind;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Combat\Support\RefusedFleetVerdict;
use OGame\GameMissions\Models\ResolvedReturnDestination;
use OGame\Models\CombatFleetDisposition;
use OGame\Models\CombatOutboxMessage;
use OGame\Models\FleetMission;

/**
 * Le seul chemin par lequel une flotte que le combat refuse rentre chez elle.
 *
 * ## Pourquoi un seul, et pas un par genre de mission
 *
 * La fermeture ecrit une disposition pour **chaque** flotte refusee — vagues attaquantes comprises.
 * Seule la Defense ACS la consommait : l'attaque ecrivait son avis, marquait l'aller et creait son
 * retour par un chemin parallele. La disposition d'une vague attaquante restait donc « en attente »
 * pour toujours, et la phrase « l'avis decoule de la disposition » etait fausse de ce cote-la.
 *
 * Deux protocoles pour un meme mouvement, c'est deux endroits ou la regle peut diverger. Celui-ci
 * est le seul, et chaque genre de mission ne fournit plus que ce qu'il est seul a savoir : comment
 * creer sa mission retour.
 *
 * ## Ce que toute flotte renvoyee traverse, dans cet ordre
 *
 * 1. **La section critique**, avec l'ordre global des verrous et la mission relue sous verrou ;
 * 2. **sa disposition**, ecrite avant l'effet si personne ne l'avait encore jugee ;
 * 3. **sa destination**, decidee sous verrou par le protocole unique — une origine rasee ne fait
 *    pas disparaitre la flotte, elle la fait rentrer par le recours suivant ;
 * 4. **son avis**, derive de la raison persistee et non d'une raison redecidee ici ;
 * 5. **l'aller marque traite** — et rien d'autre : ses heures sont des faits ;
 * 6. **un seul retour**, dans la transaction qui pose `consumed_at`.
 *
 * Le tout est indivisible : une panne a n'importe quelle etape laisse la flotte exactement comme
 * avant, disposition en attente comprise, et le passage suivant recommence.
 */
final class RefusedFleetHomecoming
{
    public function __construct(
        private readonly FleetMovementGate $gate = new FleetMovementGate(),
        private readonly FleetDispositionRegistry $registry = new FleetDispositionRegistry(),
        private readonly ReturnDestinationResolver $destinations = new ReturnDestinationResolver(),
    ) {
    }

    /**
     * Renvoie la flotte si un mouvement lui a ete impose, et une seule fois.
     *
     * @param Closure(FleetMission, ResolvedReturnDestination): void $creerRetour Ce que ce genre de
     *        mission est seul a savoir faire. Il recoit la mission **tenue sous verrou** et la
     *        destination decidee ; l'instant de depart lui appartient.
     * @param Closure(FleetMission): (RefusedFleetVerdict|null)|null $juger Ce que decide le combat
     *        pour une flotte que personne n'avait encore jugee. Rendre `null` : rien a faire.
     * @return bool Vrai si ce passage a renvoye la flotte.
     */
    public function sendHome(FleetMission $mission, int $now, Closure $creerRetour, Closure|null $juger = null): bool
    {
        return $this->gate->decideUnderLock($mission, function (FleetMission $tenue) use ($now, $creerRetour, $juger): bool {
            // **Une flotte deja partie ne repart pas.** Le drapeau a pu etre pose entre le
            // chargement du modele et ce verrou — par un rappel, par un autre travailleur.
            if ((int)$tenue->processed === 1) {
                return false;
            }

            $disposition = $this->registry->pendingFor($tenue);

            if ($disposition === null && $juger !== null) {
                $verdict = ($juger)($tenue);

                if ($verdict === null) {
                    return false;
                }

                // **La decision s'ecrit avant d'etre executee**, meme quand c'est ce passage-ci qui
                // la prononce : une flotte jamais jugee se relit alors comme une refusee, et la
                // panne qui suivrait ne laisserait pas un mouvement fait sans trace.
                $this->registry->record(
                    $verdict->combat,
                    $tenue->id,
                    $verdict->reason,
                    $verdict->decidedAt,
                    FleetDispositionKind::ReturnToOrigin
                );

                $disposition = $this->registry->pendingFor($tenue);
            }

            if ($disposition === null) {
                return false;
            }

            return $this->registry->consume($disposition, $now, function (CombatFleetDisposition $decidee) use ($tenue, $creerRetour): void {
                $this->carryOut($tenue, $decidee, $creerRetour);
            });
        });
    }

    /**
     * Execute le mouvement decide : destination, avis, marquage, retour.
     *
     * @param Closure(FleetMission, ResolvedReturnDestination): void $creerRetour
     */
    private function carryOut(FleetMission $mission, CombatFleetDisposition $decidee, Closure $creerRetour): void
    {
        // **La destination peut refuser** — origine rasee et plus aucun recours. Ce qui garantit
        // alors que rien ne subsiste n'est pas l'ordre de ces lignes mais la transaction, qui
        // ramene tout en arriere ou que la panne survienne : ni avis annonce, ni aller marque, ni
        // decision consommee. La flotte reste visible et recuperable, et le passage suivant
        // recommence. La resoudre en premier ne fait qu ecrire le protocole dans l ordre ou on le
        // raconte.
        $destination = $this->destinations->resolveUnderLock($mission, (int)$decidee->combat_instance_id);

        $this->announce($mission, $decidee);

        // **L'aller ne perd que son etat de traitement.** Son arrivee et son stationnement sont des
        // faits : ce que le joueur avait planifie, ce que l'admission a juge, ce que l'audit relira.
        $mission->processed = 1;
        $mission->save();

        ($creerRetour)($mission, $destination);
    }

    /**
     * Ecrit l'avis depuis la raison persistee.
     *
     * **L'avis decoule du mouvement, jamais l'inverse.** Un message est une chose que l'on affiche ;
     * une disposition est une chose que l'on doit faire. Les ecrire dans cet ordre garantit qu'aucun
     * refus annonce ne reste sans le mouvement qui va avec — et qu'aucune raison affichee ne diverge
     * de celle qui a ete decidee.
     */
    private function announce(FleetMission $mission, CombatFleetDisposition $decidee): void
    {
        $combat = $decidee->combatInstance;

        CombatOutboxMessage::query()->firstOrCreate(
            [
                'combat_instance_id' => $decidee->combat_instance_id,
                'participant_key' => CombatParticipantKey::forFleet($mission->id),
                'kind' => CombatOutboxKind::RallyRefused->value,
            ],
            [
                'payload' => [
                    'reason' => $decidee->reason->value,
                    'target_body_id' => $combat->target_planet_id,
                    'galaxy' => $combat->galaxy,
                    'system' => $combat->system,
                    'position' => $combat->position,
                    'group_fleets' => 1,
                ],
                // L'avis est lisible depuis l'instant ou la flotte s'est posee, pas depuis celui ou
                // un travailleur a fini par la voir.
                'available_at' => self::physicalArrivalOf($mission),
            ]
        );
    }

    /**
     * L'instant ou la flotte s'est reellement posee sur le corps.
     *
     * Pour une Defense ACS, `time_arrival` porte la fin du stationnement : l'arrivee physique est en
     * amont. Pour les autres genres, le stationnement est nul et les deux se confondent.
     */
    public static function physicalArrivalOf(FleetMission $mission): int
    {
        return (int)$mission->time_arrival - (int)($mission->time_holding ?? 0);
    }
}
