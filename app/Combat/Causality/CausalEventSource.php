<?php

namespace OGame\Combat\Causality;

/**
 * Les sources qu'une tranche doit avoir interrogees pour se dire complete.
 *
 * ## Pourquoi une enumeration plutot qu'un compte
 *
 * Une tranche « complete » qui aurait oublie d'interroger la file de recherche produirait une
 * photographie **plausible et fausse** : il y manquerait un niveau d'arme, et rien ne le
 * signalerait. Enumerer les sources rend l'oubli visible — ajouter un genre d'evenement sans
 * ajouter sa source fait tomber un essai.
 *
 * L'inventaire des faits de photographie peut allonger cette liste. C'est voulu : chaque nouvelle
 * source doit etre declaree ici avant de pouvoir alimenter une fermeture.
 */
enum CausalEventSource: string
{
    /**
     * Les missions de flotte qui atterrissent : transports, retours, deploiements, renforts.
     */
    case FleetMissions = 'fleet_missions';

    /**
     * Les tirs de missiles interplanetaires prevus sur le corps.
     */
    case MissileVolleys = 'missile_volleys';

    /**
     * La file de construction de vaisseaux et de defenses.
     */
    case BuildQueue = 'build_queue';

    /**
     * La file de recherche, pour les technologies qui changent le combat.
     */
    case ResearchQueue = 'research_queue';
}
