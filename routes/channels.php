<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('App.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/*
 * Le nombre de courriers non lus d un joueur.
 *
 * Un canal par joueur, et lui seul : combien de messages il n a pas lus ne regarde personne
 * d autre. Le canal ne porte qu un nombre — jamais le contenu ni l expediteur —, de sorte qu une
 * autorisation qui ne serait plus valable ne divulguerait rien de sensible.
 */
Broadcast::channel('messages.player.{playerId}', function ($user, $playerId) {
    return (int) $user->id === (int) $playerId;
});

// Private channel for direct messages - user can only listen to their own channel
Broadcast::channel('chat.user.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

// Private channel for alliance messages - user must be a member of the alliance
Broadcast::channel('chat.alliance.{allianceId}', function ($user, $allianceId) {
    return $user->alliance_id === (int) $allianceId;
});

/*
 * Les pertes d'un joueur pendant une bataille durable.
 *
 * Un canal par joueur, et lui seul : ce que la bataille lui coute ne regarde ni son alliance,
 * ni son adversaire. L'autorisation ne vaut que pour sa propre ligne, et elle est revocable —
 * un compte supprime ou renomme cesse d'y avoir droit des la requete suivante.
 */
Broadcast::channel('combat.player.{playerId}', function ($user, $playerId) {
    return (int) $user->id === (int) $playerId;
});
