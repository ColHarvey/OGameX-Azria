<?php

namespace OGame\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Un mouvement de flotte que ce joueur a le droit de voir a change : il part, il arrive, il
 * rentre, il est rappele.
 *
 * ## Un canal par joueur, et lui seul
 *
 * Les mouvements sont l'information la plus sensible de la Galaxie : qui attaque qui, quand.
 * L'annonce ne part donc que vers les deux joueurs que la boite d'evenements sert deja — celui
 * qui a envoye la flotte, celui dont la planete est visee — chacun sur son canal prive. Un tiers
 * n'a pas de canal ou l'entendre.
 *
 * ## Ce qui voyage
 *
 * L'identifiant de la mission et les deux systemes qu'elle relie : assez pour que le navigateur
 * sache s'il regarde un systeme concerne et redemande sa couche de flottes. **Ni genre, ni heure,
 * ni cible precise** — ces faits sont dans la reponse a la requete qui suit, sous les memes droits.
 * Une annonce repetee est sans effet : le navigateur relit, il n'additionne rien.
 */
class FleetMovementChanged implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public int $playerId,
        public int $missionId,
        public int $galaxyFrom,
        public int $systemFrom,
        public int $galaxyTo,
        public int $systemTo,
    ) {
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('galaxy.player.' . $this->playerId)];
    }

    public function broadcastAs(): string
    {
        return 'FleetMovementChanged';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'missionId' => $this->missionId,
            'from' => ['galaxy' => $this->galaxyFrom, 'system' => $this->systemFrom],
            'to' => ['galaxy' => $this->galaxyTo, 'system' => $this->systemTo],
        ];
    }
}
