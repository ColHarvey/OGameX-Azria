<?php

namespace Tests\MariaDb;

use OGame\GameMissions\AttackMission;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\FleetMission;
use OGame\Models\Resources;

/**
 * La mission d'attaque qui prete sa fermeture de retour au reglement, comme `AttackMission` le
 * fait elle-meme dans `settlePersistentCombat()` : `startReturn()` est protegee et doit le rester.
 */
final class SettlingAttackMission extends AttackMission
{
    public function returnFor(FleetMission $retourDe, Resources $ressources, UnitCollection $unites, int $tempsSupplementaire = 0, array|null $epaves = null, int|null $dureeImposee = null): void
    {
        $this->startReturn($retourDe, $ressources, $unites, $tempsSupplementaire, $epaves, $dureeImposee);
    }
}
