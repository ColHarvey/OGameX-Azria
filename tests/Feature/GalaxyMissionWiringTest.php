<?php

namespace Tests\Feature;

use OGame\Factories\GameMissionFactory;
use OGame\GameMissions\MissileMission;
use OGame\GameMissions\MoonDestructionMission;
use Tests\UnitTestCase;

/**
 * Ce que la galaxie propose correspond-il a la mission qui partira ?
 *
 * L'entree « Destruction de la Lune » envoyait le type 10 — celui de l'attaque de missiles —
 * et le type 9, seul a instancier MoonDestructionMission, n'etait propose nulle part. La
 * mission etait complete, testee, et parfaitement inaccessible : un joueur qui cliquait sur
 * le libelle arrivait sur une attaque de missiles.
 *
 * Aucun test ne pouvait voir cela. Chaque moitie fonctionnait : la galaxie proposait un type
 * valide, la fabrique rendait une mission valide, et rien ne verifiait que les deux parlaient
 * de la meme chose. C'est le raccord que ce fichier surveille.
 */
class GalaxyMissionWiringTest extends UnitTestCase
{
    /**
     * Assert that the moon-destruction entry really instantiates the moon-destruction mission.
     */
    public function testTheMoonDestructionEntryPointsAtTheMoonDestructionMission(): void
    {
        $controleur = file_get_contents(app_path('Http/Controllers/GalaxyController.php'));
        $this->assertIsString($controleur);

        // Le bloc est identifie par son libelle, seul repere stable : le numero est
        // precisement ce qui est en cause.
        $position = strpos($controleur, 'mission_destroy_moon');
        $this->assertNotFalse($position, 'The galaxy no longer offers a moon destruction entry at all.');

        $bloc = substr($controleur, max(0, $position - 400), 400);

        $this->assertMatchesRegularExpression(
            "/'missionType' => 9,/",
            $bloc,
            'The moon destruction entry does not send mission type 9, so the player is routed to a different mission than the label promises.'
        );

        $this->assertDoesNotMatchRegularExpression(
            "/'missionType' => 10,/",
            $bloc,
            'The moon destruction entry still sends mission type 10, which is the missile attack.'
        );
    }

    /**
     * Assert that the two type numbers involved still mean what the fix assumed.
     *
     * Le correctif repose entierement sur cette correspondance. Si un jour la fabrique change
     * de numerotation, c'est ici qu'il faut le voir, et non en jeu.
     */
    public function testTheFactoryStillMapsThoseTwoTypesAsExpected(): void
    {
        $missions = GameMissionFactory::getAllMissions();

        $this->assertInstanceOf(
            MoonDestructionMission::class,
            $missions[9] ?? null,
            'Mission type 9 no longer means moon destruction.'
        );

        $this->assertInstanceOf(
            MissileMission::class,
            $missions[10] ?? null,
            'Mission type 10 no longer means missile attack.'
        );
    }
}
