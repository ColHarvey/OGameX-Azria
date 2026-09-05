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
