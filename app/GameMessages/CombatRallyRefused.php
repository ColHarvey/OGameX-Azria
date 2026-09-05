<?php

namespace OGame\GameMessages;

use OGame\GameMessages\Abstracts\GameMessage;

/**
 * Une flotte refusee au ralliement d'un combat durable rentre chez elle : le joueur l'apprend ici.
 *
 * ## Ce que le message stocke, et ce qu'il traduit
 *
 * L'avis de la boite d'envoi fige **le fait** : un code de raison, le corps, ses coordonnees. Le
 * message garde ce code (`reason_code`) et le traduit **a la lecture**, dans la langue du lecteur —
 * le figer en clair a l'envoi le figerait dans la langue du serveur, et une traduction corrigee
 * plus tard ne l'atteindrait jamais.
 */
class CombatRallyRefused extends GameMessage
{
    protected function initialize(): void
    {
        $this->key = 'combat_rally_refused';
        $this->params = ['coordinates', 'reason'];
        $this->tab = 'fleets';
        $this->subtab = 'other';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function checkParams(array $params): array
    {
        if (isset($params['reason_code']) && is_string($params['reason_code']) && !isset($params['reason'])) {
            $params['reason'] = __('t_messages.combat_rally_refused.reasons.' . $params['reason_code']);
        }

        return parent::checkParams($params);
    }
}
