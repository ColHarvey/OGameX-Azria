<?php

namespace OGame\Combat\Services;

use OGame\GameMissions\BattleEngine\Models\AttackerFleet;
use OGame\GameMissions\BattleEngine\Models\DefenderFleet;
use OGame\Models\FleetMission;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;

/**
 * Qui se bat dans ce combat, et sur quoi.
 *
 * Le meme effectif sert deux fois : a la cloture, pour calculer la bataille ; a l'echeance, pour
 * appliquer son resultat. Les deux doivent voir exactement les memes flottes, sans quoi le
 * reglement creerait un retour pour une flotte qui n'a pas combattu — ou en oublierait une.
 */
final readonly class CombatRoster
{
    /**
     * @param array<int, AttackerFleet> $attackers L'initiatrice en tete : le moteur traite la premiere comme celle qui mene.
     * @param array<int, DefenderFleet> $defenders La garnison en tete, puis les renforts admis.
     * @param PlanetService $target Le corps attaque, charge par sa fabrique.
     * @param PlayerService $targetOwner Son proprietaire.
     * @param PlayerService $initiatorOwner Le proprietaire de la flotte initiatrice.
     * @param FleetMission $initiator La mission qui a ouvert le combat.
     * @param array<int, PlanetService> $originBodies Les corps d'ou les flottes attaquantes sont
     *        parties, sans doublon. C'est leur chantier spatial qui fixe la taille du champ d'epaves
     *        d'un attaquant de classe General : la photographie d'application les fige tous, sans
     *        quoi un chantier monte d'un niveau pendant la bataille en changerait l'issue.
     */
    public function __construct(
        public array $attackers,
        public array $defenders,
        public PlanetService $target,
        public PlayerService $targetOwner,
        public PlayerService $initiatorOwner,
        public FleetMission $initiator,
        public array $originBodies = [],
    ) {
    }
}
