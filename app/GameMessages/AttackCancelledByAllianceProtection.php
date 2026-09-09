<?php

namespace OGame\GameMessages;

use OGame\GameMessages\Abstracts\GameMessage;

/**
 * L attaque a ete annulee a l arrivee : la cible appartient a un membre de l alliance.
 *
 * ## Pourquoi un avis, et pourquoi celui-ci
 *
 * Une flotte qui part en guerre et rentre sans avoir tire doit dire pourquoi, sinon le joueur croit
 * a un defaut. Le cas se produit quand l appartenance change **pendant le trajet** : au lancement la
 * cible etait attaquable, a l arrivee elle ne l est plus.
 *
 * Il ne reutilise pas l avis de refus des combats (`RefusedFleetNotice`) : celui-la est compose
 * depuis une instance de combat, et ici aucun combat n existe — la flotte fait demi-tour avant toute
 * ouverture.
 */
class AttackCancelledByAllianceProtection extends GameMessage
{
    protected function initialize(): void
    {
        $this->key = 'attack_cancelled_by_alliance_protection';
        $this->params = ['coordinates'];
        $this->tab = 'fleets';
        $this->subtab = 'other';
    }
}
