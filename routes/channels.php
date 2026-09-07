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

/*
 * Le chat general du serveur.
 *
 * Prive et non public : le canal n'est ouvert qu'a un joueur authentifie. Il ne porte aucune
 * appartenance, donc la regle n'a rien a comparer — mais elle existe, et c'est elle qui exige
 * la session. Un visiteur sans compte ne s'y abonne pas.
 */
Broadcast::channel('chat.general', function ($user) {
    return $user !== null;
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

/*
 * Les changements publics d un systeme de la Galaxie : colonie, lune, debris, destruction.
 *
 * Prive au sens de Laravel — une session est exigee — mais commun a tous les joueurs qui
 * regardent ce systeme, comme la Galaxie elle-meme. L annonce ne porte que des coordonnees :
 * ce que le navigateur apprend ensuite vient de la photographie du systeme, sous ses droits.
 */
Broadcast::channel('galaxy.system.{galaxy}.{system}', function ($user) {
    return $user !== null;
});

/*
 * Les mouvements de flotte qu un joueur a le droit de voir.
 *
 * Un canal par joueur, et lui seul : qui attaque qui, et quand, est l information la plus
 * sensible de la Galaxie. Elle ne part que vers l expediteur et vers le proprietaire du corps
 * vise — les deux que la boite d evenements sert deja. Un tiers n a pas de canal ou l entendre.
 */
Broadcast::channel('galaxy.player.{playerId}', function ($user, $playerId) {
    return (int) $user->id === (int) $playerId;
});
