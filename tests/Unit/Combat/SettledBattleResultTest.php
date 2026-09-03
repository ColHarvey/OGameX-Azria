<?php

namespace Tests\Unit\Combat;

use OGame\Combat\Allocation\AppliedLootShares;
use OGame\Combat\Allocation\ExactLootAmounts;
use OGame\Combat\Allocation\FrozenLootAllocation;
use OGame\Combat\Allocation\SettledBattleResult;
use OGame\Combat\Allocation\SurvivingFleetCapacity;
use OGame\GameMissions\BattleEngine\Models\AttackerFleetResult;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use Tests\UnitTestCase;

/**
 * Le resultat regle porte l'applique, et l'original n'a pas bouge.
 *
 * ## Pourquoi cet essai existe separement
 *
 * Dans le reglement, toutes les lectures de l'original precedent la copie : retoucher l'original au
 * lieu de le copier n'y change rien d'observable, et une mutation qui remplace la copie par un
 * alias survit a tous les scenarios. La regle est pourtant reelle — la trace figee ne doit pas
 * devenir l'applique en memoire — et c'est ici qu'elle se prouve, en observant les deux objets.
 */
class SettledBattleResultTest extends UnitTestCase
{
    /**
     * La copie porte l'applique et les parts ; l'original garde son potentiel et les siennes.
     */
    public function testTheSettledCopyCarriesTheAppliedAndLeavesTheOriginalAlone(): void
    {
        $original = $this->aResultWithTwoFleets();

        $applique = ExactLootAmounts::of(600, 300, 150);
        $parts = AppliedLootShares::of(
            $applique,
            [SurvivingFleetCapacity::of(41, 10_000, 0), SurvivingFleetCapacity::of(42, 10_000, 0)],
            41,
            FrozenLootAllocation::atOperationStart()
        );

        $reglee = SettledBattleResult::of($original, $applique, $parts);

        $this->assertSame(600.0, $reglee->loot->metal->get(), 'The settled copy does not carry the applied loot.');
        $this->assertSame(300.0, $reglee->loot->crystal->get());
        $this->assertSame(150.0, $reglee->loot->deuterium->get());

        // **L'original n'a pas bouge** : c'est lui que le rejeu doit retrouver.
        $this->assertSame(1000.0, $original->loot->metal->get(), 'The frozen result was rewritten instead of copied.');
        $this->assertSame(500.0, $original->attackerFleetResults[0]->lootShare->metal->get(), 'A fleet share of the frozen result was rewritten.');
        $this->assertSame(500.0, $original->attackerFleetResults[1]->lootShare->metal->get());

        // Chaque part de la copie est celle du reglement, et leur somme vaut l'applique.
        $somme = 0;
        foreach ($reglee->attackerFleetResults as $rang => $flotte) {
            $this->assertSame(
                $parts->forFleet($flotte->fleetMissionId)->metal,
                (int)$flotte->lootShare->metal->get(),
                "Fleet {$rang} of the settled copy does not carry its share."
            );
            $somme += (int)$flotte->lootShare->metal->get();
        }
        $this->assertSame(600, $somme);
    }

    /**
     * Ce qui n'est pas le butin traverse la copie a l'identique.
     *
     * Les unites, les manches et les debris sont les memes dans les deux batailles : une copie qui
     * les perdrait ferait appliquer une autre bataille que celle qui a eu lieu.
     */
    public function testEverythingButTheLootCrossesUnchanged(): void
    {
        $original = $this->aResultWithTwoFleets();

        $reglee = SettledBattleResult::of(
            $original,
            ExactLootAmounts::of(1, 1, 1),
            AppliedLootShares::of(
                ExactLootAmounts::of(1, 1, 1),
                [SurvivingFleetCapacity::of(41, 10_000, 0), SurvivingFleetCapacity::of(42, 10_000, 0)],
                41,
                FrozenLootAllocation::atOperationStart()
            )
        );

        $this->assertSame($original->debris, $reglee->debris);
        $this->assertSame($original->rounds, $reglee->rounds);
        $this->assertSame($original->attackerUnitsResult, $reglee->attackerUnitsResult);
        $this->assertSame($original->defenderUnitsLost, $reglee->defenderUnitsLost);
        $this->assertSame($original->attackerPlanetId, $reglee->attackerPlanetId);

        foreach ($reglee->attackerFleetResults as $rang => $flotte) {
            $this->assertSame($original->attackerFleetResults[$rang]->fleetMissionId, $flotte->fleetMissionId);
            $this->assertSame($original->attackerFleetResults[$rang]->survivingCargo, $flotte->survivingCargo, 'The cargo already aboard was lost in the copy.');
            $this->assertSame($original->attackerFleetResults[$rang]->unitsResult, $flotte->unitsResult);
        }
    }

    /**
     * Un resultat a deux flottes, chacune avec sa part et sa cargaison.
     */
    private function aResultWithTwoFleets(): BattleResult
    {
        $result = new BattleResult();
        $result->loot = new Resources(1000, 500, 250, 0);
        $result->debris = new Resources(300, 150, 0, 0);
        $result->rounds = [];
        $result->attackerUnitsResult = $this->units(['light_fighter' => 8]);
        $result->defenderUnitsLost = $this->units(['rocket_launcher' => 4]);
        $result->attackerPlanetId = 9;

        $result->attackerFleetResults = [
            $this->aFleet(41, 500),
            $this->aFleet(42, 500),
        ];

        return $result;
    }

    private function aFleet(int $missionId, int $part): AttackerFleetResult
    {
        $flotte = new AttackerFleetResult($missionId, 3, $this->units(['light_fighter' => 10]));
        $flotte->unitsResult = $this->units(['light_fighter' => 8]);
        $flotte->unitsLost = $this->units(['light_fighter' => 2]);
        $flotte->lootShare = new Resources($part, $part, $part, 0);
        $flotte->survivingCargo = new Resources(0, 0, 298, 0);
        $flotte->survivingCargoCapacity = 10_000;
        $flotte->completelyDestroyed = false;

        return $flotte;
    }

    /**
     * @param array<string, int> $unites
     */
    private function units(array $unites): UnitCollection
    {
        $collection = new UnitCollection();

        foreach ($unites as $machine => $montant) {
            $collection->addUnit(ObjectService::getUnitObjectByMachineName($machine), $montant);
        }

        return $collection;
    }
}
