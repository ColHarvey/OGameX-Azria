<?php

namespace OGame\Combat\Enums;

/**
 * Ce que la porte unique des arrivees a reellement fait de la mission qu on lui a donnee.
 *
 * ## Le silence que cette enumeration remplace
 *
 * `FleetMissionService::updateMission()` ne rendait rien. Trois chemins en sortaient **sans effet** —
 * le jeton de traitement tenu par un autre travailleur, le missile differe par la matrice, le missile
 * annule sans impact — et l appelant devait deviner lequel en interrogeant le registre des effets.
 *
 * La fermeture du ralliement, elle, en tirait une conclusion fausse : ne trouvant pas de delta, elle
 * accusait la porte de ne pas avoir vu la barriere. Un rouge du bac MariaDB l a montre le 9 septembre
 * 2026 — un travailleur tenait le jeton pendant que la fermeture appliquait, et le message accusait
 * le mauvais coupable.
 *
 * **Une absence n est pas une explication.** Chaque issue se nomme donc, et l appelant branche.
 *
 * ## Pourquoi ici
 *
 * Ces distinctions n existent que pour le combat : c est la fermeture qui doit distinguer « applique »
 * de « personne n a rien fait, et voici pourquoi ». Le reste du jeu ignore le retour, et c est
 * legitime — un travailleur de pages n a rien a decider de plus.
 */
enum MissionUpdateOutcome: string
{
    /**
     * Le gestionnaire a traite la mission. Un effet gouverne par un combat a donc inscrit son delta.
     */
    case Applied = 'applied';

    /**
     * Un autre travailleur tient le jeton de traitement : rien n a ete fait **par nous**.
     *
     * Ce n est pas un echec — c est une course ordinaire, et le jeton existe pour qu un seul
     * travailleur traite une mission a la fois. L appelant qui avait besoin de cet effet doit
     * relacher et reprendre plus tard, jamais attendre en gardant ses verrous : celui qui tient le
     * jeton a peut-etre besoin de ces verrous-la pour finir.
     */
    case ClaimedElsewhere = 'claimed_elsewhere';

    /**
     * La mission etait deja traitee. Distincte de la precedente : personne ne la tient, elle est faite.
     */
    case AlreadyProcessed = 'already_processed';

    /**
     * La matrice differe l effet : un missile attend le reglement d une bataille engagee, pour
     * frapper ce qui reste. Rien n est perdu, rien n est frappe deux fois.
     */
    case Deferred = 'deferred';

    /**
     * La matrice annule l effet sans impact : un missile parti apres l ouverture est rendu, une fois.
     */
    case Cancelled = 'cancelled';

    /**
     * La mission n existe plus : elle a ete effacee entre le moment ou l appelant l a lue et celui
     * ou la porte l a relue.
     */
    case Vanished = 'vanished';

    /**
     * L echeance n est pas atteinte — arrivee, ou fin de stationnement. Rien a faire, et ce n est
     * pas une anomalie.
     */
    case NotDueYet = 'not_due_yet';

    /**
     * Un effet gouverne par un combat a-t-il ete livre, et doit-il donc avoir son delta au registre ?
     */
    public function shouldHaveWrittenItsDelta(): bool
    {
        return $this === self::Applied;
    }
}
