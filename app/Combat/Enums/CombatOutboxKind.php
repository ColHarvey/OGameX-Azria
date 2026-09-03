<?php

namespace OGame\Combat\Enums;

/**
 * Ce qu'un message de la boite d'envoi vient dire au joueur.
 *
 * ## Pourquoi une enumeration, et non une chaine libre
 *
 * L'unicite de la boite porte sur le triplet combat / participant / **genre**. Une chaine libre
 * aurait donc laisse `rally_refused` et `rallyRefused` coexister : deux genres pour un meme
 * evenement, et le joueur aurait recu deux fois le meme message sans que la contrainte s'en
 * apercoive.
 *
 * ## Le genre porte le fait, jamais le texte
 *
 * Le message se compose a l'envoi, depuis les cles de traduction. Le figer en clair dans la boite
 * l'aurait rendu insensible a la langue du joueur — et un joueur qui change de langue entre la
 * fermeture et la lecture aurait lu l'ancienne.
 *
 * Ce qui est fige, c'est le **fait** : la raison, le corps, l'heure. Le recomposer plus tard le
 * ferait dependre d'un monde qui a change depuis.
 */
enum CombatOutboxKind: string
{
    /**
     * Une flotte candidate n'a pas ete admise au ralliement, et repart.
     *
     * **Le joueur doit savoir pourquoi.** Une flotte qui rentre sans explication ressemble a une
     * panne : il a paye le carburant, attendu l'arrivee, et rien ne s'est passe. La raison
     * d'admission — camp plein, alliance non eligible, fenetre depassee — est precisement ce qui
     * distingue une regle d'un bogue.
     */
    case RallyRefused = 'rally_refused';

    /**
     * Le rapport de bataille, une fois le combat resolu.
     */
    case BattleReport = 'battle_report';

    /**
     * La lune visee a ete detruite, ou a resiste.
     */
    case MoonDestruction = 'moon_destruction';

    /**
     * Le butin reserve a ete rendu sans avoir ete pris.
     */
    case LootReleased = 'loot_released';
}
