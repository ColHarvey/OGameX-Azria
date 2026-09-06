<?php

namespace OGame\Observers;

use Illuminate\Support\Facades\DB;
use OGame\Events\UnreadMessageCountChanged;
use OGame\Models\Message;

/**
 * L'annonce d'un courrier nait de l'ecriture, pas de l'appelant.
 *
 * ## Pourquoi un observateur, et non cinq appels
 *
 * `MessageService` ecrit un message a cinq endroits — message systeme, rapport d'espionnage,
 * rapport de combat, perte de contact, message de bienvenue — et le reglement d'un combat en cree
 * d'autres par ses propres chemins. Poser l'annonce a chaque endroit, c'est accepter d'en oublier
 * un : la pastille serait alors juste pour certains courriers et fausse pour d'autres, ce qui est
 * pire qu'une pastille qui ne bouge jamais — on lui ferait confiance.
 *
 * L'observateur ferme la classe : tout ce qui cree une ligne l'annonce, y compris ce qui sera
 * ecrit demain.
 *
 * ## Apres la validation, jamais dedans
 *
 * Un rapport de combat nait dans la transaction du reglement, qui peut etre annulee. Annoncer
 * depuis l'interieur enverrait au joueur le compte d'un courrier qui n'existera pas. `afterCommit`
 * rend l'annonce a la validation la plus exterieure — et ne fait rien si elle echoue. Hors
 * transaction, la fermeture part immediatement.
 */
class MessageObserver
{
    public function created(Message $message): void
    {
        $destinataire = (int)$message->user_id;

        if ($destinataire === 0) {
            return;
        }

        DB::afterCommit(static function () use ($destinataire): void {
            broadcast(new UnreadMessageCountChanged($destinataire));
        });
    }
}
