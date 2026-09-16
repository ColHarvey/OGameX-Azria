<?php

namespace OGame\GameMessages;

use OGame\GameMessages\Abstracts\GameMessage;

/**
 * Les habitants perdus lors d une attaque reussie contre une planete qui porte une forme de vie
 * (journal §155.6). Le message garde les nombres ; la phrase est traduite a la lecture.
 */
class LifeformPopulationLossReport extends GameMessage
{
    protected function initialize(): void
    {
        $this->key = 'lifeform_population_loss';
        $this->params = ['coordinates', 'lost', 'survivors', 'protected_percent', 'loss_percent'];
        $this->tab = 'fleets';
        $this->subtab = 'combat_reports';
    }
}
