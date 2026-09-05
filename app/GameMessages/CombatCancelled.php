<?php

namespace OGame\GameMessages;

use OGame\GameMessages\Abstracts\GameMessage;

/**
 * Un combat durable annule : attaquantes, renforts et cible en sont avises.
 *
 * ## Ce que le joueur lit
 *
 * La cause, traduite a la lecture depuis son code (`cause_code`) ; les coordonnees du corps ; et
 * une **reference d'incident** — le numero du combat — pour que le joueur puisse nommer la bataille
 * qui n'a pas eu lieu s'il en parle a l'administrateur.
 *
 * L'empreinte des faits abandonnes n'y figure plus : c'est une somme de controle interne, illisible
 * pour un joueur, et elle decrit l'etat du systeme plutot que ce qui lui arrive. Elle reste dans
 * l'audit, avec la note administrative — qui s'adresse a celui qui a annule, pas a ceux qui le
 * subissent.
 */
class CombatCancelled extends GameMessage
{
    protected function initialize(): void
    {
        $this->key = 'combat_cancelled';
        $this->params = ['coordinates', 'cause', 'reference'];
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

        return parent::checkParams($params);
    }
}
