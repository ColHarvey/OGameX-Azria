<?php

namespace OGame\Galaxy;

use OGame\Services\PlayerService;

/**
 * Les compteurs du bandeau de la Galaxie : sondes, recycleurs et missiles disponibles sur la
 * planete courante, emplacements de flotte utilises et maximum.
 *
 * ## Une seule source
 *
 * Trois reponses les portaient chacune a sa maniere : la photographie du systeme
 * (`GalaxyController::ajax()`, lue au chargement), l'envoi rapide depuis la Galaxie
 * (`FleetController::dispatchSendMiniFleet()`, qui rendait des **valeurs de demonstration** — onze
 * sondes, un emplacement — que le rendu herite ecrivait dans le bandeau apres chaque sonde), et
 * rien entre les deux : Keven voyait « Esp.Sonde : 2 » ne jamais bouger. Ici une seule lecture,
 * que la photographie, l'envoi rapide et la couche des flottes (`GalaxyFleetsController`, redemandee
 * a chaque mouvement du joueur) rendent a l'identique.
 *
 * Les valeurs sont celles de la planete courante et du joueur **au moment de la reponse** : apres
 * un envoi, apres un retour, apres un lot du chantier spatial livre par la requete elle-meme.
 */
final class GalaxyHeaderCounters
{
    /**
     * @return array{probes: int, recyclers: int, missiles: int, slotsUsed: int, slotsMax: int}
     */
    public static function of(PlayerService $player): array
    {
        $planet = $player->planets->current();

        return [
            'probes' => $planet->getObjectAmount('espionage_probe'),
            'recyclers' => $planet->getObjectAmount('recycler'),
            'missiles' => $planet->getObjectAmount('interplanetary_missile'),
            'slotsUsed' => $player->getFleetSlotsInUse(),
            'slotsMax' => $player->getFleetSlotsMax(),
        ];
    }
}
