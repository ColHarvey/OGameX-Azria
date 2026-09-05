<?php

namespace OGame\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Une bataille a change d'etat pour un joueur : elle commence, elle se regle, elle est finie.
 *
 * ## Ce qui voyage
 *
 * L'etat, son libelle, et si le rapport est reellement accessible. **Rien de ce qui reste a venir** :
 * ni echeance, ni instant de fin, ni nombre de rounds. Le navigateur apprend qu'un etat a change et
 * redemande la carte ; il n'en deduit rien.
 *
 * Une annonce repetee est sans effet : le navigateur relit, il n'additionne rien. C'est ce qui
 * autorise la garantie « au moins une fois » sans autre precaution.
 */
class CombatStateChanged implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public int $playerId,
        public int $combatInstanceId,
        public string $status,
        public string $statusLabel,
        public bool $reportAvailable,
    ) {
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('combat.player.' . $this->playerId)];
    }

    public function broadcastAs(): string
    {
        return 'CombatStateChanged';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'combatId' => $this->combatInstanceId,
            'status' => $this->status,
            'status_label' => $this->statusLabel,
            'report_available' => $this->reportAvailable,
        ];
    }
}
