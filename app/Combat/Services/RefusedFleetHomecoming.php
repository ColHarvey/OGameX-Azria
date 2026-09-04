<?php

namespace OGame\Combat\Services;

use Closure;
use OGame\Combat\Enums\FleetDispositionKind;
use OGame\Combat\Exceptions\ReturnDoesNotMatchTheOrder;
use OGame\Combat\Support\RefusedFleetNotice;
use OGame\Combat\Support\RefusedFleetVerdict;
use OGame\Combat\Support\ReturnOrder;
use OGame\Models\CombatFleetDisposition;
use OGame\Models\FleetMission;
use OGame\Services\FleetMissionService;
use OGame\Services\ObjectService;

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
 * est le seul. Les genres de mission ne choisissent plus rien : ni la destination, ni l'instant du
 * depart — ils recoivent un `ReturnOrder` et creent le retour qu'il decrit, et le protocole verifie
 * qu'ils en ont cree exactement un.
 *
 * ## Ce que toute flotte renvoyee traverse, dans cet ordre
 *
 * 1. **La section critique**, avec l'ordre global des verrous et la mission relue sous verrou ;
 * 2. **sa disposition**, ecrite avant l'effet si personne ne l'avait encore jugee ;
 * 3. **sa destination**, decidee sous verrou par le protocole unique — une origine rasee ne fait
 *    pas disparaitre la flotte, elle la fait rentrer par le recours suivant ;
 * 4. **son instant de depart**, derive de la decision et jamais de l'horloge du travailleur ;
 * 5. **son avis**, derive de la raison persistee — et compare a celui que la fermeture a pu ecrire ;
 * 6. **l'aller marque traite** — et rien d'autre : ses heures sont des faits ;
 * 7. **un seul retour**, dans la transaction qui pose `consumed_at`.
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
     * @param Closure(FleetMission, ReturnOrder): void $creerRetour Ce que ce genre de mission est
     *        seul a savoir faire : creer sa mission retour. Il recoit la mission **tenue sous
     *        verrou** et l'ordre — destination et depart imposes —, et doit creer exactement un retour.
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
     * Execute le mouvement decide : destination, instant, avis, marquage, retour.
     *
     * @param Closure(FleetMission, ReturnOrder): void $creerRetour
     */
    private function carryOut(FleetMission $mission, CombatFleetDisposition $decidee, Closure $creerRetour): void
    {
        // **La destination peut refuser** — origine rasee et plus aucun recours. Ce qui garantit
        // alors que rien ne subsiste n'est pas l'ordre de ces lignes mais la transaction, qui
        // ramene tout en arriere ou que la panne survienne : ni avis annonce, ni aller marque, ni
        // decision consommee. La flotte reste visible et recuperable, et le passage suivant
        // recommence.
        $destination = $this->destinations->resolveUnderLock($mission, (int)$decidee->combat_instance_id);

        // **L'instant de depart vient de la decision, jamais de l'horloge.** Un travailleur en
        // retard de trois heures ecrit les memes heures qu'un travailleur ponctuel.
        $ordre = new ReturnOrder($destination, ReturnOrder::departureInstant((int)$decidee->decided_at, $mission));

        // **L'avis decoule du mouvement, jamais l'inverse** — et s'il existe deja, il doit dire la
        // meme chose. Un message est une chose que l'on affiche ; une disposition est une chose que
        // l'on doit faire.
        RefusedFleetNotice::write($decidee->combatInstance, $mission, $decidee->reason, (int)$decidee->decided_at);

        // **L'aller ne perd que son etat de traitement.** Son arrivee et son stationnement sont des
        // faits : ce que le joueur avait planifie, ce que l'admission a juge, ce que l'audit relira.
        $mission->processed = 1;
        $mission->save();

        // **Exactement un nouveau retour, et celui que l'ordre decrit.** La creation appartient au
        // genre de mission ; sa verification appartient au protocole. Compter les enfants ne prouvait
        // rien : un enfant preexistant et une fermeture qui ne fait rien passaient, et un enfant
        // unique avec une autre destination, un autre depart ou des actifs amputes passait aussi.
        // Le protocole photographie donc les retours avant l'appel, exige un seul nouveau, le relit
        // et le compare champ par champ a l'ordre.
        $avant = FleetMission::query()->where('parent_id', $mission->id)->pluck('id')->all();

        if ($avant !== []) {
            throw new ReturnDoesNotMatchTheOrder($mission->id, 'un retour existe deja avant l appel (' . implode(', ', $avant) . ')');
        }

        ($creerRetour)($mission, $ordre);

        $nouveaux = array_values(array_diff(
            FleetMission::query()->where('parent_id', $mission->id)->pluck('id')->all(),
            $avant
        ));

        if (count($nouveaux) !== 1) {
            throw new ReturnDoesNotMatchTheOrder($mission->id, count($nouveaux) . ' nouveau(x) retour(s) au lieu d exactement un');
        }

        $retour = FleetMission::query()->whereKey($nouveaux[0])->first();

        if (!$retour instanceof FleetMission) {
            throw new ReturnDoesNotMatchTheOrder($mission->id, 'le retour cree ne se relit pas');
        }

        $this->refuseIfTheReturnDiffersFromTheOrder($mission, $retour, $ordre);
    }

    /**
     * Le retour relu, champ par champ, contre l'ordre et contre l'aller.
     *
     * Parent, proprietaire, genre, destination complete, depart, arrivee, unites et ressources :
     * tout ce qu'un genre de mission aurait pu choisir a sa guise est verifie ici. Comparer en bloc
     * laisserait passer plusieurs ecarts a la fois ; chaque champ nomme son ecart.
     */
    private function refuseIfTheReturnDiffersFromTheOrder(FleetMission $aller, FleetMission $retour, ReturnOrder $ordre): void
    {
        // **Ce que l'aller porte, tel que le service le definit** — et non ses colonnes brutes : le
        // retour ramene aussi la moitie du carburant consomme, et les missiles comptent parmi les
        // unites. C'est la meme lecture que le createur fait ; comparer a autre chose refuserait
        // un retour juste.
        $service = resolve(FleetMissionService::class);
        $ressources = $service->getResources($aller);

        $attendu = [
            'parent_id' => (int)$aller->id,
            'user_id' => (int)$aller->user_id,
            'mission_type' => (int)$aller->mission_type,
            'planet_id_to' => $ordre->destination->bodyId,
            'type_to' => $ordre->destination->type->value,
            'galaxy_to' => $ordre->destination->coordinate->galaxy,
            'system_to' => $ordre->destination->coordinate->system,
            'position_to' => $ordre->destination->coordinate->position,
            'time_departure' => $ordre->departureAt,
            'time_arrival' => $ordre->departureAt + ReturnOrder::tripDurationOf($aller),
            'metal' => (int)$ressources->metal->get(),
            'crystal' => (int)$ressources->crystal->get(),
            'deuterium' => (int)$ressources->deuterium->get(),
        ];

        foreach (ObjectService::getShipObjects() as $vaisseau) {
            $attendu[$vaisseau->machine_name] = 0;
        }

        $attendu['interplanetary_missile'] = 0;

        foreach ($service->getFleetUnits($aller)->units as $unite) {
            $attendu[$unite->unitObject->machine_name] = (int)$unite->amount;
        }

        foreach ($attendu as $champ => $valeur) {
            if ((int)$retour->{$champ} !== (int)$valeur) {
                throw new ReturnDoesNotMatchTheOrder(
                    $aller->id,
                    $champ . ' vaut ' . (int)$retour->{$champ} . ' au lieu de ' . (int)$valeur
                );
            }
        }
    }
}
