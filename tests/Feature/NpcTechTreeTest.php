<?php

namespace Tests\Feature;

use OGame\Services\Npc\NpcGrowthService;
use OGame\Services\Npc\NpcRaidService;
use OGame\Services\ObjectService;
use ReflectionClass;
use Tests\UnitTestCase;

/**
 * Les plans de croissance ne citent que des objets qui existent, et ils se tiennent.
 *
 * Une faute de frappe dans un plan ne casse rien : le palier reste simplement inatteignable,
 * la base le saute a chaque passage, et personne ne s'en apercoit avant de constater six mois
 * plus tard qu'aucune base n'a jamais construit de croiseur. C'est exactement le genre de
 * defaut qu'aucun autre test ne voit, parce que le code s'execute parfaitement.
 */
class NpcTechTreeTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createAndSetPlanetModel([]);
    }

    /**
     * Assert that every machine name in the three plans is a real game object.
     */
    public function testEveryPlanEntryNamesARealObject(): void
    {
        $reflection = new ReflectionClass(NpcGrowthService::class);

        $recherches = array_map(fn ($o) => $o->machine_name, ObjectService::getResearchObjects());
        $batiments = array_merge(
            array_map(fn ($o) => $o->machine_name, ObjectService::getBuildingObjects()),
            array_map(fn ($o) => $o->machine_name, ObjectService::getStationObjects())
        );
        $unites = array_merge(
            array_map(fn ($o) => $o->machine_name, ObjectService::getShipObjects()),
            array_map(fn ($o) => $o->machine_name, ObjectService::getDefenseObjects())
        );

        $plans = [
            'PLAN_RESEARCH' => $recherches,
            'PLAN_BUILDINGS' => $batiments,
            'PLAN_UNITS' => $unites,
        ];

        foreach ($plans as $plan => $connus) {
            /** @var array<int, array<int, string|int>> $lignes */
            $lignes = $reflection->getConstant($plan);

            $this->assertNotEmpty($lignes, "The plan {$plan} is empty.");

            foreach ($lignes as $ligne) {
                $this->assertContains(
                    $ligne[0],
                    $connus,
                    "The plan {$plan} names '{$ligne[0]}', which is not a game object — the step would be skipped forever, in silence."
                );
            }
        }
    }

    /**
     * Assert that the unit plan really does cover every ship in the game.
     *
     * C'est la demande, formulee telle quelle : une base doit pouvoir fabriquer chaque
     * vaisseau. Les missiles sont la seule exception, et elle est volontaire — MissileMission
     * n'a ni controle du mode vacances ni protection administrateur, defaut du depot amont,
     * donc les factions n'en utilisent jamais.
     */
    public function testTheUnitPlanCoversEveryShip(): void
    {
        $reflection = new ReflectionClass(NpcGrowthService::class);
        /** @var array<int, array<int, string|int>> $plan */
        $plan = $reflection->getConstant('PLAN_UNITS');
        $prevus = array_column($plan, 0);

        foreach (ObjectService::getShipObjects() as $vaisseau) {
            $this->assertContains(
                $vaisseau->machine_name,
                $prevus,
                "No base will ever build a {$vaisseau->machine_name}: it is missing from the unit plan."
            );
        }
    }

    /**
     * Assert that a raid can actually field the heavy ships the bases now build.
     *
     * Le plan de construction et la liste des vaisseaux de raid sont deux endroits differents.
     * Un croiseur construit mais absent de la liste resterait a quai pour toujours, et la
     * base paraitrait faible malgre sa flotte.
     */
    public function testEveryWarshipTheBasesBuildCanJoinARaid(): void
    {
        $croissance = new ReflectionClass(NpcGrowthService::class);
        $raids = new ReflectionClass(NpcRaidService::class);

        /** @var array<int, array<int, string|int>> $plan */
        $plan = $croissance->getConstant('PLAN_UNITS');
        /** @var array<int, string> $embarquables */
        $embarquables = $raids->getConstant('RAID_SHIPS');

        $defenses = array_map(fn ($o) => $o->machine_name, ObjectService::getDefenseObjects());

        // Les vaisseaux sans valeur militaire restent chez eux : la sonde est fragile, le
        // recycleur sert au champ de debris, le vaisseau de colonisation a l'essaimage, le
        // satellite ne bouge pas et le foreur non plus.
        $sedentaires = ['espionage_probe', 'recycler', 'colony_ship', 'solar_satellite', 'crawler', 'pathfinder'];

        foreach ($plan as [$machineName]) {
            if (in_array($machineName, $defenses, true) || in_array($machineName, $sedentaires, true)) {
                continue;
            }

            $this->assertContains(
                $machineName,
                $embarquables,
                "A base builds {$machineName} but no raid can ever take it: it is missing from RAID_SHIPS."
            );
        }
    }
}
