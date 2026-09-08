<?php

namespace OGame\Galaxy;

use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\FleetMission;
use OGame\Models\Patrol;
use OGame\Patrol\Enums\PatrolState;
use OGame\Patrol\PatrolOrders;
use OGame\Patrol\PatrolUpkeep;
use OGame\Services\PlayerService;

/**
 * Les patrouilles qu un joueur a le droit de voir dans un systeme : **les siennes, et rien d autre**.
 *
 * ## Pourquoi seulement les siennes, a cette etape
 *
 * Une patrouille etrangere ne se voit que par l acquisition d un reseau de surveillance, avec le
 * detail que le niveau du reseau autorise (etape 5). Tant que ce reseau n existe pas, aucune
 * patrouille d un tiers ne voyage : ni masquee, ni degradee — **absente**. La carte n elargit rien,
 * elle affiche ce que le serveur lui donne ; c est la meme discipline que `FleetMovementProjection`.
 *
 * ## Ce qui voyage pour les siennes
 *
 * Ce que le joueur sait deja de sa propre flotte : l etat, le point ou le segment en cours avec ses
 * deux instants — le navigateur interpole, il ne calcule pas —, la composition, la reserve telle
 * qu elle est maintenant, la consommation horaire et l echeance du retour de securite **telle que
 * la ligne la porte**. Et les commandes, chacune avec sa raison quand elle est refusee : un bouton
 * grise l est parce que le serveur l a dit, pour la raison qu il a dite, et cette raison est
 * exactement celle que la confirmation opposerait — elle vient de la meme methode
 * (`PatrolOrders::whyMoveIsRefused()`). L interface n a rien a decider.
 *
 * ## L interrupteur n efface pas une flotte
 *
 * Une patrouille qui existe se voit de son proprietaire, interrupteur ou non : ce sont ses
 * vaisseaux. Eteint, ce sont les commandes qui se refusent (`disabled`), et le joueur lit pourquoi.
 */
final class PatrolProjection
{
    public function __construct(
        private readonly PlayerService $player,
        private readonly PatrolOrders $orders,
        private readonly PatrolUpkeep $upkeep,
    ) {
    }

    /**
     * Les patrouilles du joueur dont le segment courant touche le systeme demande.
     *
     * @return array<int, array<string, mixed>>
     */
    public function inSystem(int $galaxy, int $system, int $now): array
    {
        $projections = [];

        $patrouilles = Patrol::query()
            ->where('user_id', $this->player->getId())
            ->where('state', '!=', PatrolState::Finished->value)
            ->with('currentMission')
            ->orderBy('id')
            ->get();

        foreach ($patrouilles as $patrouille) {
            $segment = $patrouille->currentMission;

            if (!$segment instanceof FleetMission || (int)$segment->processed === 1) {
                continue;
            }

            if (!FleetMovementProjection::touches($segment, $galaxy, $system)) {
                continue;
            }

            $projections[] = $this->project($patrouille, $segment, $now);
        }

        return $projections;
    }

    /**
     * @return array<string, mixed>
     */
    private function project(Patrol $patrouille, FleetMission $segment, int $now): array
    {
        $units = $this->orders->unitsOf($segment);
        $point = $patrouille->point();
        $posee = $patrouille->state->isParked();
        $base = $this->orders->homeCoordinateOf($patrouille);

        // **La reserve telle qu elle est maintenant** : ce que la ligne porte, moins ce que le
        // stationnement a brule depuis le curseur. Lecture seule — facturer reste au service, et
        // c est la meme formule qu il emploiera.
        $reserve = (float)$patrouille->fuel_reserve;

        if ($posee) {
            $reserve -= $this->upkeep->dueBetween($units, (int)($patrouille->upkeep_paid_at ?? $now), $now);
        }

        return [
            'id' => (int)$patrouille->id,
            'state' => $patrouille->state->value,
            'state_label' => (string)__('t_ingame.patrol.state_' . $patrouille->state->value),
            'galaxy' => (int)$patrouille->galaxy,
            'system' => (int)$patrouille->system,
            'point' => $point === null ? null : ['x' => $point->x, 'y' => $point->y],
            'segment' => [
                'id' => (int)$segment->id,
                'from' => $this->endpoint($segment->galaxy_from, $segment->system_from, $segment->position_from, $segment->type_from, $segment->x_from, $segment->y_from),
                'to' => $this->endpoint($segment->galaxy_to, $segment->system_to, $segment->position_to, $segment->type_to, $segment->x_to, $segment->y_to),
                'time_departure' => (int)$segment->time_departure,
                'time_arrival' => (int)$segment->time_arrival,
            ],
            'units' => $this->unitsList($units),
            'cargo' => [
                'metal' => (int)$segment->metal,
                'crystal' => (int)$segment->crystal,
                'deuterium' => (int)$segment->deuterium,
            ],
            'fuel_reserve' => round(max(0.0, $reserve), 2),
            'upkeep_per_hour' => round($this->upkeep->perHour($units), 2),
            'safety_return_cost' => $this->orders->safetyReturnCostOf($patrouille, $units),
            // Le rendez-vous **persiste**, jamais recalcule : c est lui que le travailleur attend.
            'safety_return_at' => $posee ? $this->orders->nextEventAt($segment) : null,
            'stationed_since' => $patrouille->stationed_since === null ? null : (int)$patrouille->stationed_since,
            'order_version' => (int)$patrouille->order_version,
            'home' => $base === null ? null : [
                'galaxy' => $base->galaxy,
                'system' => $base->system,
                'position' => $base->position,
            ],
            'commands' => [
                'move' => $this->command($this->orders->whyMoveIsRefused($patrouille, $now)),
                'recall' => $this->command($this->orders->whyRecallIsRefused($patrouille, $now)),
            ],
        ];
    }

    /**
     * Une commande, telle que le bouton la lira : permise, ou refusee pour une raison dite.
     *
     * La clef voyage a cote du texte : le texte est pour le joueur, la clef pour ce qui doit
     * reconnaitre la raison sans lire une langue — les essais, et un jour un style par raison.
     *
     * @return array{allowed: bool, reason_key: string|null, reason: string|null}
     */
    private function command(string|null $refus): array
    {
        return [
            'allowed' => $refus === null,
            'reason_key' => $refus,
            'reason' => $refus === null ? null : (string)__('t_ingame.patrol.refusal_' . $refus),
        ];
    }

    /**
     * Un bout de segment : un corps par sa position d orbite, ou un point libre par ses coordonnees.
     *
     * @return array{galaxy: int, system: int, position: int, type: int, x: int|null, y: int|null}
     */
    private function endpoint(mixed $galaxy, mixed $system, mixed $position, mixed $type, mixed $x, mixed $y): array
    {
        return [
            'galaxy' => (int)$galaxy,
            'system' => (int)$system,
            'position' => (int)$position,
            'type' => (int)$type,
            'x' => $x === null ? null : (int)$x,
            'y' => $y === null ? null : (int)$y,
        ];
    }

    /**
     * La composition, avec le nom que le joueur lit — le serveur connait sa langue, pas la carte.
     *
     * @return array<int, array{machine_name: string, amount: int, label: string}>
     */
    private function unitsList(UnitCollection $units): array
    {
        $liste = [];

        foreach ($units->units as $entree) {
            $liste[] = [
                'machine_name' => $entree->unitObject->machine_name,
                'amount' => (int)$entree->amount,
                'label' => $entree->unitObject->title,
            ];
        }

        return $liste;
    }
}
