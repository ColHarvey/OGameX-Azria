<?php

namespace OGame\GameMessages;

use OGame\GameMessages\Abstracts\GameMessage;

class AdminAnnouncement extends GameMessage
{
    protected function initialize(): void
    {
        $this->key = 'admin_announcement';
        $this->params = ['subject', 'body'];
        $this->tab = 'universe';
        $this->subtab = 'universe';
    }
}
