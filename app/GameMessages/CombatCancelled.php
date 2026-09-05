<?php

namespace OGame\GameMessages;

use OGame\GameMessages\Abstracts\GameMessage;

/**
 * Un combat durable annule : attaquantes, renforts et cible en sont avises.
 *
 * ## Ce que le joueur lit
 *
 * La cause, traduite a la lecture depuis son code (`cause_code`) ; les coordonnees du corps ; et
 * l'empreinte des faits abandonnes, pour que le joueur puisse nommer la bataille qui n'a pas eu
 * lieu s'il en parle a l'administrateur. La note administrative, elle, reste dans l'audit : elle
 * s'adresse a celui qui a annule, pas a ceux qui le subissent.
 */
class CombatCancelled extends GameMessage
{
    protected function initialize(): void
    {
        $this->key = 'combat_cancelled';
        $this->params = ['coordinates', 'cause', 'fingerprint'];
        $this->tab = 'fleets';
        $this->subtab = 'other';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function checkParams(array $params): array
    {
        if (isset($params['cause_code']) && is_string($params['cause_code']) && !isset($params['cause'])) {
            $params['cause'] = __('t_messages.combat_cancelled.causes.' . $params['cause_code']);
        }

        if (array_key_exists('fingerprint', $params) && ($params['fingerprint'] === null || $params['fingerprint'] === '')) {
            $params['fingerprint'] = __('t_messages.combat_cancelled.no_fingerprint');
        }

        return parent::checkParams($params);
    }
}
