<?php

namespace OGame\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Des pertes viennent de devenir visibles pour un joueur : son navigateur l'apprend tout de suite.
 *
 * ## Ce qui voyage, et ce qui ne voyage pas
 *
 * Uniquement des pertes **deja visibles**, celles de ce joueur, sur son canal prive. Jamais une
 * perte future, jamais le calendrier de la bataille, jamais son echeance : le navigateur ne recoit
 * que ce qu'il aurait pu demander lui-meme a cet instant. Chaque perte porte son rang, qui sert au
 * navigateur a dedupliquer apres une reconnexion — le meme rang deux fois n'ajoute rien.
 *
 * ## Pourquoi `ShouldBroadcastNow`
 *
 * Le diffuseur tourne deja dans un processus dedie, a la seconde. Passer par la file y ajouterait
 * l'attente du travailleur — la latence que cette diffusion existe pour supprimer — et ferait
 * dependre l'immediatete d'un second processus.
 */
class CombatLossesPublished implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param int $playerId Le joueur qui subit ces pertes, et le seul a qui elles sont envoyees.
     * @param int $combatInstanceId La bataille concernee.
     * @param array<int, array<string, mixed>> $losses Les pertes devenues visibles, en ordre de rang.
     */
    public function __construct(
        public int $playerId,
        public int $combatInstanceId,
        public array $losses,
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
        return 'CombatLossesPublished';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'combatId' => $this->combatInstanceId,
            'losses' => $this->losses,
        ];
    }
}
