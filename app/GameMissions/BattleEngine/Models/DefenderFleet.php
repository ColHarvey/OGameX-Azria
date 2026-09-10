<?php

namespace OGame\GameMissions\BattleEngine\Models;

use OGame\Factories\PlayerServiceFactory;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Hull\DamagedHulls;
use OGame\Models\FleetMission;
use OGame\Services\FleetMissionService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use RuntimeException;

/**
 * Represents a single defending fleet in a battle.
 * Can be either the planet owner's stationary forces or an ACS defend fleet.
 */
class DefenderFleet
{
    /**
     * @var UnitCollection The units in this defending fleet.
     */
    public UnitCollection $units;

    /**
     * @var PlayerService The player who owns this defending fleet.
     */
    public PlayerService $player;

    /**
     * @var int The fleet mission ID (0 for planet owner's stationary forces).
     */
    public int $fleetMissionId;

    /**
     * @var int The ID of the player who owns this fleet.
     */
    public int $ownerId;

    /**
     * @var FleetMission|null The fleet mission (null for planet owner's stationary forces).
     */
    public ?FleetMission $fleetMission;

    /**
     * Create a DefenderFleet from a planet's stationary forces.
     *
     * Note: This excludes interplanetary missiles and anti-ballistic missiles
     * from combat, as they should not participate in fleet battles.
     * - ABMs only intercept IPMs during missile attacks
     * - IPMs only attack defenses via the MissileMission
     *
     * @param PlanetService $planet
     * @return self
     */
    /**
     * La garnison telle qu'une photographie la fixe, et non telle que le corps la porte.
     *
     * Un combat durable se bat contre l'effectif qu'il a photographie : celui de son ouverture,
     * augmente des seules unites que des effets admissibles ont produites. Relire le corps a la
     * fermeture ferait combattre des vaisseaux construits pendant le ralliement sur une decision
     * prise apres l'ouverture — ou manquer ceux qu'une file admissible a produits mais que le monde
     * n'a pas encore appliques.
     */
    public static function fromPhotographedGarrison(PlanetService $planet, UnitCollection $units, DamagedHulls|null $damagedHulls = null): self
    {
        $defender = self::fromPlanet($planet);
        $defender->units = $units;

        // **Les degats de l ouverture, jamais ceux du moment.**
        //
        // `fromPlanet()` vient de poser les degats **courants** du corps, ce qui est juste sur le
        // chemin instantane et faux ici : cet effectif est celui de l ouverture, et une flotte
        // abimee qui atterrit pendant le ralliement lui ferait porter plus d unites abimees qu il
        // n en compte — le moteur le refuserait, a raison.
        //
        // `null` laisse ce que `fromPlanet()` a pose : c est le comportement d un appelant qui n a
        // pas de photographie, et d un combat ouvert sous une version anterieure.
        if ($damagedHulls !== null) {
            $defender->damagedHulls = $damagedHulls;
        }

        return $defender;
    }

    public static function fromPlanet(PlanetService $planet): self
    {
        $defender = new self();

        // Collect all units on the planet (ships + defenses)
        // but exclude missiles which should not participate in combat
        $defender->units = new UnitCollection();
        $defender->units->addCollection($planet->getShipUnits());
        $defender->units->addCollection(self::getDefenseUnitsForCombat($planet));

        $player = $planet->getPlayer();
        if ($player === null) {
            throw new RuntimeException('Defender planet has no owner.');
        }
        $defender->player = $player;
        $defender->fleetMissionId = 0; // 0 indicates stationary planet forces
        $defender->ownerId = $player->getId();
        $defender->fleetMission = null;

        // **La garnison entre au combat dans l etat ou la derniere bataille l a laissee.**
        //
        // Sans cette ligne, un corps dont la flotte stationnaire est a moitie detruite la verrait
        // renaitre intacte au combat suivant — et le defaut serait **invisible** : les effectifs
        // seraient justes, seule la resistance serait fausse. C est le pendant exact de ce que
        // `AttackerFleet` recoit du cote attaquant.
        $defender->damagedHulls = $planet->damagedHulls();

        return $defender;
    }

    /**
     * Get defense units for combat, excluding missiles.
     *
     * Missiles (interplanetary and anti-ballistic) should not participate
     * in fleet combat. They are only used in missile attacks.
     *
     * @param PlanetService $planet
     * @return UnitCollection
     */
    private static function getDefenseUnitsForCombat(PlanetService $planet): UnitCollection
    {
        $units = new UnitCollection();
        $objects = ObjectService::getDefenseObjects();
        foreach ($objects as $object) {
            // Skip missiles - they should not participate in combat
            if (in_array($object->machine_name, [
                'interplanetary_missile',
                'anti_ballistic_missile',
            ])) {
                continue;
            }

            $amount = $planet->getObjectAmount($object->machine_name);
            if ($amount > 0) {
                $units->addUnit($object, $amount);
            }
        }

        return $units;
    }

    /**
     * Create a DefenderFleet from an ACS defend fleet mission.
     *
     * @param FleetMission $mission
     * @param FleetMissionService $fleetMissionService
     * @param PlayerServiceFactory $playerServiceFactory
     * @return self
     */
    public static function fromFleetMission(
        FleetMission $mission,
        FleetMissionService $fleetMissionService,
        PlayerServiceFactory $playerServiceFactory
    ): self {
        $defender = new self();

        $defender->units = $fleetMissionService->getFleetUnits($mission);
        $defender->player = $playerServiceFactory->make($mission->user_id, true);
        $defender->fleetMissionId = $mission->id;
        $defender->ownerId = $mission->user_id;
        $defender->fleetMission = $mission;

        return $defender;
    }

    /**
     * Les degats que porte cette flotte en entrant au combat, par type et par palier.
     *
     * **Volontairement sans valeur par defaut**, comme cote attaquant : la garnison et des dizaines
     * de montages construisent une `DefenderFleet` sans rien savoir des coques. `damagedHulls()`
     * rend « rien d abime » tant que personne n a pose la propriete.
     */
    public DamagedHulls $damagedHulls;

    /**
     * Les degats de cette flotte, ou aucun si personne ne les a poses.
     */
    public function damagedHulls(): DamagedHulls
    {
        return $this->damagedHulls ?? DamagedHulls::none();
    }
}
