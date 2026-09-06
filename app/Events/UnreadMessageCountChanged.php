<?php

namespace OGame\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use OGame\Models\Message;

/**
 * Le nombre de courriers non lus d'un joueur a change.
 *
 * ## Pourquoi un nombre, et non « tu as du courrier »
 *
 * Un increment se perd. Deux messages arrives pendant une reconnexion, une coupure de quelques
 * secondes, un onglet endormi : le compteur du navigateur derive, et rien ne le ramene avant un
 * rechargement — le defaut meme qu'on ferme. Le serveur envoie donc **son** total ; le navigateur
 * ecrit ce qu'il recoit sans jamais calculer. Un evenement perdu est rattrape par le suivant.
 *
 * ## Un seul evenement pour les deux sens
 *
 * Il part quand un message arrive **et** quand des messages passent en lu. Sans le second, la
 * pastille monterait en direct et ne redescendrait qu'au rechargement — pire que l'etat actuel,
 * ou au moins les deux sens sont egalement faux.
 *
 * Il va au joueur, sur son canal prive, et **a tous ses onglets** : celui qui lit ses messages
 * doit voir sa propre pastille tomber. C'est pourquoi il n'y a pas de `toOthers()` ici.
 *
 * ## Le total se calcule a la diffusion, pas a la construction
 *
 * L'evenement ne transporte que l'identifiant du destinataire. Le compte est lu quand il part,
 * donc apres la validation qui a cree ou marque les messages. Compte a la construction, il
 * decrirait un etat que la base n'a pas encore — ou plus.
 */
class UnreadMessageCountChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public int $recipientId)
    {
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('messages.player.' . $this->recipientId)];
    }

    /**
     * Le nom que le navigateur ecoute, fixe ici plutot que derive de la classe : renommer la
     * classe ne doit pas casser silencieusement l'abonnement.
     */
    public function broadcastAs(): string
    {
        return 'UnreadMessageCountChanged';
    }

    /**
     * @return array<string, int>
     */
    public function broadcastWith(): array
    {
        return [
            'unread' => Message::query()
                ->where('user_id', $this->recipientId)
                ->where('viewed', 0)
                ->count(),
        ];
    }
}
