<?php

namespace OGame\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Quelque chose de public a change dans un systeme : une planete, une lune, un champ de debris.
 *
 * ## Ce qui voyage, et pourquoi si peu
 *
 * Les coordonnees et le genre du changement. **Rien d'autre** — ni nom, ni proprietaire, ni
 * quantite. Le navigateur qui regarde ce systeme apprend qu'il doit le redemander, et la reponse
 * qu'il obtient est la meme photographie autoritative que le tableau a toujours servie, avec les
 * memes droits. L'annonce ne peut donc reveler que ce que la page suivante aurait montre de toute
 * facon.
 *
 * Le canal est prive au sens de Laravel — une session est exigee — mais commun a tous les joueurs
 * connectes qui regardent ce systeme, comme la Galaxie elle-meme.
 *
 * Une annonce repetee est sans effet : le navigateur relit, il n'additionne rien.
 */
class GalaxySystemChanged implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public int $galaxy,
        public int $system,
        public int $position,
        public string $kind,
        public string $change,
    ) {
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('galaxy.system.' . $this->galaxy . '.' . $this->system)];
    }

    public function broadcastAs(): string
    {
        return 'GalaxySystemChanged';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'galaxy' => $this->galaxy,
            'system' => $this->system,
            'position' => $this->position,
            'kind' => $this->kind,
            'change' => $this->change,
        ];
    }
}
