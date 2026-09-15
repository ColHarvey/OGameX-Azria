<?php

namespace OGame\GameMessages;

use OGame\GameMessages\Abstracts\GameMessage;

/**
 * Le rapport d un vol de decouverte des formes de vie : ce qu il a rapporte, traduit a la lecture.
 *
 * Le message garde **les faits** (le code de l issue, les quantites, le nom machine de l espece) et
 * les traduit dans la langue du lecteur au moment de l afficher, comme `CombatRallyRefused`.
 */
class LifeformDiscoveryReport extends GameMessage
{
    protected function initialize(): void
    {
        $this->key = 'lifeform_discovery_report';
        $this->params = ['coordinates', 'outcome'];
        $this->tab = 'fleets';
        $this->subtab = 'expeditions';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function checkParams(array $params): array
    {
        if (isset($params['outcome_code']) && is_string($params['outcome_code']) && !isset($params['outcome'])) {
            $espece = isset($params['species_code']) && is_string($params['species_code']) && $params['species_code'] !== ''
                ? __('t_lifeforms.species.' . $params['species_code'])
                : '';
            $params['outcome'] = __('t_messages.lifeform_discovery_report.outcomes.' . $params['outcome_code'], [
                'artifacts' => (int)($params['artifacts'] ?? 0),
                'experience' => (int)($params['experience'] ?? 0),
                'species' => is_string($espece) ? $espece : '',
            ]);
        }

        return parent::checkParams($params);
    }
}
