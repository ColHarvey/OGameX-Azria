<?php

namespace OGame\Combat\Support;

use OGame\Services\PlayerService;
use RuntimeException;

/**
 * Un joueur tel qu une bataille ouverte **avant** le 12 septembre 2026 le voyait.
 *
 * ## Ce que la premiere regle faisait, et que ce combat garde
 *
 * Les niveaux de recherche se lisaient vivants a la cloture ; le bonus de combat des classes n entrait
 * **pas** dans les tirs, et **entrait** dans les niveaux rapportes. Un combat engage sous cette regle la
 * garde jusqu a son terme (decision de Keven : « version de regle + migration »).
 *
 * Deux lectures seulement different donc du joueur vivant : le bonus applique aux tirs vaut zero, et le
 * bonus rapporte reste celui que le monde derive. Les niveaux, la classe et tout le reste sont le compte.
 */
final class CombatantUnderTheFirstRule extends PlayerService
{
    public function __construct(int $playerId)
    {
        parent::__construct($playerId);
    }

    /**
     * Aucun bonus de classe dans les tirs : c est la premiere regle.
     */
    public function getCombatResearchBonusLevels(): int
    {
        return 0;
    }

    /**
     * Le bonus que le rapport annoncait sous la premiere regle : celui que le monde derive.
     */
    public function getReportedCombatResearchBonusLevels(): int
    {
        return parent::getCombatResearchBonusLevels();
    }

    public function setResearchLevel(string $machine_name, int $level, bool $save_to_db = true): void
    {
        throw new RuntimeException(
            'A combatant under the first rule was asked to change its « ' . $machine_name . ' » level: a '
            . 'battle reads an account, it never writes to it.'
        );
    }
}
