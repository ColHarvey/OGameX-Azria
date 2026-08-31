<?php

namespace OGame\GameMessages;

use OGame\GameMessages\Abstracts\GameMessage;

/**
 * Annonce envoyee a tous les joueurs a l ouverture d un evenement de missions.
 *
 * Les dates sont passees en parametres plutot que redigees par l administrateur : le
 * message est ainsi traduit dans la langue de chaque joueur.
 */
class EventStarted extends GameMessage
{
    protected function initialize(): void
    {
        $this->key = 'event_started';
        $this->params = ['start', 'end'];
        $this->tab = 'universe';
        $this->subtab = 'universe';
    }
}
