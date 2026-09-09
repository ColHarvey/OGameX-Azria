<?php

namespace OGame\Combat\Exceptions;

use RuntimeException;

/**
 * Un autre travailleur tenait une arrivee dont la fermeture avait besoin : elle se retire.
 *
 * ## Pourquoi se retirer plutot qu attendre
 *
 * La fermeture tient la barriere du corps, l instance, les unions et les missions. Attendre que le
 * jeton se libere en gardant tout cela, c est bloquer celui qui doit finir — **il a peut-etre besoin
 * de ces verrous-la**. Elle relache donc, et un passage suivant reprendra.
 *
 * C est le patron que le depot applique deja quand un lien change sous les verrous
 * (`MovementLocksOutdated`) : relacher tout, recommencer, jamais prendre le verrou manquant apres
 * coup.
 *
 * ## Ce que ce retrait garantit
 *
 * **Aucun effet partiel.** Elle est levee dans la transaction de la fermeture, qui revient en
 * arriere entierement : l instance reste en `Rallying`, aucune photographie n est ecrite, aucun
 * effet n est a moitie applique.
 *
 * **Aucune fenetre prolongee.** L echeance du ralliement vit sur la barriere
 * (`owned_through_effect_at`), fixee a l ouverture. Une reprise plus tardive la relit telle quelle :
 * le retard technique ne rallonge pas le temps donne aux renforts.
 *
 * **Une reprise reellement prevue.** L avanceur passe chaque minute et ferme les ralliements echus ;
 * la reprise ne depend donc pas de la visite d un joueur.
 *
 * ## Ce qu elle n est pas
 *
 * Ni une faute, ni une anomalie a signaler comme telle : c est une course ordinaire entre deux
 * travailleurs. Ce qui serait une faute, c est de la confondre avec « la porte n a pas vu la
 * barriere » — ce que ce depot a fait jusqu au 9 septembre 2026.
 */
class ClosureMustWaitForAnotherWorker extends RuntimeException
{
    public function __construct(public readonly int $combatInstanceId, public readonly string $eventIdentity)
    {
        parent::__construct(
            'La fermeture du combat ' . $combatInstanceId . ' se retire : un autre travailleur tient '
            . 'l arrivee ' . $eventIdentity . '. Elle reprendra au passage suivant, sans effet partiel.'
        );
    }
}
