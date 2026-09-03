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
 *
 * ## Un genre n'existe que s'il a un ecrivain
 *
 * Cette enumeration en portait trois de plus — le rapport de bataille, la destruction de lune, le
 * butin rendu — que **personne n'ecrivait**. Un genre sans ecrivain n'est pas une place reservee :
 * c'est un second canal apparent pour un message qui passe deja ailleurs, et un lecteur qui
 * l'attend finit par le brancher deux fois.
 *
 * Le rapport de bataille en est l'exemple : `MessageService::sendBattleReportMessageToPlayer()`
 * ecrit une ligne dans la **meme base**, appelee **dans la transaction du reglement**. Elle est
 * donc invisible avant le commit et disparait avec le debit si la transaction est annulee : c'est
 * atomique, et c'est plus fort qu'un depot differe. La boite d'envoi n'est necessaire que pour un
 * effet **externe** ou reellement differe — ce que le refus de ralliement est, puisqu'il doit
 * survivre a un redemarrage entre la fermeture et l'envoi.
 *
 * `CombatOutboxKindHasWriterTest` tient cette regle : un genre ajoute sans ecrivain fait tomber la
 * suite, et un genre s'ajoute le jour ou son ecrivain arrive.
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
}
