<?php

namespace Tests\Unit\Combat;

use InvalidArgumentException;
use OGame\Combat\Allocation\AppliedLootShares;
use OGame\Combat\Allocation\ExactLootAmounts;
use OGame\Combat\Allocation\FrozenLootAllocation;
use OGame\Combat\Allocation\FrozenLootPotential;
use OGame\Combat\Allocation\LootSettlement;
use OGame\Combat\Allocation\SurvivingFleetCapacity;
use OGame\Combat\Exceptions\ContradictoryLootShares;
use OGame\Combat\Support\FrozenCombatVersionSet;
use OGame\Combat\Support\LiveLootContextFactory;
use OGame\GameMissions\BattleEngine\BattleEngine;
use OGame\GameMissions\BattleEngine\Models\AttackerFleet;
use OGame\GameMissions\BattleEngine\Models\AttackerFleetResult;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameMissions\BattleEngine\Models\DefenderFleet;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use Tests\UnitTestCase;

/**
 * Le butin applique se repartit entre les survivantes exactement comme le moteur l'aurait fait.
 *
 * ## La preuve de parite
 *
 * Le moteur repartit le potentiel a l'instant de l'issue, avec `shareBetweenFleets`. Le reglement
 * differe repartit l'applique plus tard, avec le meme allocateur, les memes poids et la meme place —
 * tous geles avec l'issue. Quand la cible avait tout, `applique = potentiel`, et les deux
 * repartitions doivent etre **identiques a l'unite** : c'est ce que le premier essai etablit en
 * faisant tourner le moteur lui-meme, pas une copie de sa regle.
 *
 * ## L'invariant
 *
 *     somme des parts = butin applique, composante par composante
 *
 * Une somme inferieure serait juste pour un potentiel qui deborde les soutes. Elle ne l'est jamais
 * pour l'applique, qui ne les deborde pas : un ecart signale des faits geles qui ne se correspondent
 * plus, et il s'arrete plutot que de debiter au defenseur ce que personne ne recevrait.
 */
class AppliedLootSharesTest extends UnitTestCase
{
    private const int INITIATOR = 101;

    private const int ALLY = 102;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAndSetPlanetModel(['metal' => 0, 'crystal' => 0, 'deuterium' => 0]);
        $this->createAndSetUserTechModel([]);
    }

    /**
     * Quand la cible avait tout, le reglement differe rend les memes parts que le moteur.
     *
     * **Le moteur tourne reellement.** Comparer ma repartition a une copie de sa regle ne prouverait
     * rien ; la comparer a ce qu'il inscrit dans `lootShare` prouve que le chemin differe et le
     * chemin instantane ne divergent pas d'une unite.
     */
    public function testWhenTheTargetHadEverythingTheSharesMatchTheEngineExactly(): void
    {
        [$result, $versions] = $this->anEngineOutcomeWithTwoSurvivingFleets(new Resources(3_000, 1_200, 700, 0));

        $potentiel = FrozenLootPotential::frozenFrom($result, $versions);
        $reglement = LootSettlement::of($potentiel->amounts, $potentiel->amounts);

        $parts = AppliedLootShares::of(
            $reglement->applied,
            $this->capacitiesOf($result),
            self::INITIATOR,
            FrozenLootAllocation::fromFrozenSet($versions)
        );

        foreach ($result->attackerFleetResults as $fleetResult) {
            $duMoteur = ExactLootAmounts::of(
                (int)$fleetResult->lootShare->metal->get(),
                (int)$fleetResult->lootShare->crystal->get(),
                (int)$fleetResult->lootShare->deuterium->get(),
            );

            $this->assertTrue(
                $parts->forFleet($fleetResult->fleetMissionId)->equals($duMoteur),
                'Fleet ' . $fleetResult->fleetMissionId . ' would receive a different share from the deferred '
                . 'settlement than from the engine: the two paths diverge.'
            );
        }

        $this->assertTrue($parts->total->equals($potentiel->amounts));
    }

    /**
     * Quand la cible n'avait plus tout, chaque flotte recoit sa part reduite, et rien ne se perd.
     */
    public function testWhenTheTargetHadLessEachFleetGetsAReducedShareAndNothingIsLost(): void
    {
        [$result, $versions] = $this->anEngineOutcomeWithTwoSurvivingFleets(new Resources(3_000, 0, 0, 0));

        $potentiel = FrozenLootPotential::frozenFrom($result, $versions);
        $reglement = LootSettlement::of($potentiel->amounts, ExactLootAmounts::of(1_000, 0, 0));

        $parts = AppliedLootShares::of(
            $reglement->applied,
            $this->capacitiesOf($result),
            self::INITIATOR,
            FrozenLootAllocation::fromFrozenSet($versions)
        );

        $somme = 0;

        foreach ($parts->byFleet as $part) {
            $somme += $part->metal;
        }

        $this->assertSame(1_000, $somme, 'The reduced shares do not add up to what was applied.');
        $this->assertSame(1_000, $parts->total->metal);

        // Les deux flottes recoivent quelque chose : la reduction est proportionnelle, pas une
        // suppression de la plus petite.
        $this->assertGreaterThan(0, $parts->forFleet(self::INITIATOR)->metal);
        $this->assertGreaterThan(0, $parts->forFleet(self::ALLY)->metal);
    }

    /**
     * Des parts qui ne font pas la somme de l'applique arretent le reglement.
     *
     * Ici la place restante ne peut pas porter l'applique : ce cas est impossible avec des faits
     * geles coherents, puisque l'applique ne depasse jamais le potentiel, deja ramene aux soutes.
     * S'il se produit, ce sont les faits qui ne se correspondent plus — et continuer debiterait au
     * defenseur ce que personne ne recevrait.
     */
    public function testSharesThatDoNotAddUpToTheAppliedAmountStopTheSettlement(): void
    {
        $this->expectException(ContradictoryLootShares::class);

        AppliedLootShares::of(
            ExactLootAmounts::of(3_000, 0, 0),
            [
                SurvivingFleetCapacity::of(self::INITIATOR, 100, 0),
                SurvivingFleetCapacity::of(self::ALLY, 50, 0),
            ],
            self::INITIATOR,
            FrozenLootAllocation::atOperationStart()
        );
    }

    /**
     * Une flotte presentee deux fois recevrait deux parts : refusee.
     */
    public function testAFleetPresentedTwiceIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AppliedLootShares::of(
            ExactLootAmounts::of(100, 0, 0),
            [
                SurvivingFleetCapacity::of(self::INITIATOR, 1_000, 0),
                SurvivingFleetCapacity::of(self::INITIATOR, 1_000, 0),
            ],
            self::INITIATOR,
            FrozenLootAllocation::atOperationStart()
        );
    }

    /**
     * Une flotte sans place ne recoit rien, et n'empeche pas les autres de recevoir.
     */
    public function testAFleetWithoutRoomReceivesNothing(): void
    {
        $parts = AppliedLootShares::of(
            ExactLootAmounts::of(900, 0, 0),
            [
                SurvivingFleetCapacity::of(self::INITIATOR, 1_000, 0),
                // Soutes pleines : la capacite survit, mais tout est deja a bord.
                SurvivingFleetCapacity::of(self::ALLY, 1_000, 1_000),
            ],
            self::INITIATOR,
            FrozenLootAllocation::atOperationStart()
        );

        $this->assertSame(900, $parts->forFleet(self::INITIATOR)->metal);
        $this->assertTrue($parts->forFleet(self::ALLY)->isNothing());
    }

    /**
     * La place se consomme au fil des ressources, comme dans le moteur.
     *
     * ## La mutation qui a rendu cet essai necessaire
     *
     * Sa premiere version repartissait 600 de chaque ressource dans deux flottes de 1 000 de place
     * et verifiait que personne ne depassait sa place. Retirer la consommation de place entre les
     * ressources y a **survecu** : avec 900 unites pour 1 000 de place, la place n'etait jamais le
     * facteur limitant, et la valeur juste et la valeur fausse coincidaient.
     *
     * Ici, le metal remplit deja les soutes. Ce qui reste de place ne peut pas porter le cristal
     * demande, et la repartition doit **s'arreter** — le moteur n'aurait jamais produit ce potentiel,
     * donc un applique qui l'exige contredit les faits geles. Sans consommation entre ressources, la
     * meme demande passerait en silence, et chaque flotte se retrouverait chargee au-dela de sa
     * capacite reelle.
     */
    public function testRoomIsConsumedAcrossResourcesLikeTheEngineDoes(): void
    {
        $flottes = [
            // 1 000 de fret, 300 deja a bord : 700 de place chacune, 1 400 en tout.
            SurvivingFleetCapacity::of(self::INITIATOR, 1_000, 300),
            SurvivingFleetCapacity::of(self::ALLY, 1_000, 300),
        ];

        // Ce qui tient : 1 000 de metal, puis 400 de cristal dans les 400 de place restants.
        $parts = AppliedLootShares::of(
            ExactLootAmounts::of(1_000, 400, 0),
            $flottes,
            self::INITIATOR,
            FrozenLootAllocation::atOperationStart()
        );

        foreach ([self::INITIATOR, self::ALLY] as $mission) {
            $part = $parts->forFleet($mission);

            $this->assertSame(
                700,
                $part->metal + $part->crystal,
                'Fleet ' . $mission . ' was not filled exactly to its remaining room.'
            );
        }

        // Une unite de cristal de plus, et il n'y a plus de place : la repartition s'arrete.
        $this->expectException(ContradictoryLootShares::class);

        AppliedLootShares::of(
            ExactLootAmounts::of(1_000, 401, 0),
            $flottes,
            self::INITIATOR,
            FrozenLootAllocation::atOperationStart()
        );
    }

    /**
     * Une issue reelle du moteur, avec deux flottes qui survivent partiellement.
     *
     * @return array{0: BattleResult, 1: FrozenCombatVersionSet}
     */
    private function anEngineOutcomeWithTwoSurvivingFleets(Resources $loot): array
    {
        $smallCargo = ObjectService::getUnitObjectByMachineName('small_cargo');

        $initiatorUnits = new UnitCollection();
        $initiatorUnits->addUnit($smallCargo, 3);

        $allyUnits = new UnitCollection();
        $allyUnits->addUnit($smallCargo, 2);

        $initiator = $this->anAttackerFleet(self::INITIATOR, $initiatorUnits, new Resources(1_500, 0, 0, 0), true);
        $ally = $this->anAttackerFleet(self::ALLY, $allyUnits, new Resources(0, 400, 0, 0), false);

        $versions = FrozenCombatVersionSet::chosenAtOpening();
        $allocation = FrozenLootAllocation::fromFrozenSet($versions);

        $engine = new AppliedLootSharesEngineHarness(
            [$initiator, $ally],
            $this->planetService,
            [DefenderFleet::fromPlanet($this->planetService)],
            $this->settingsService,
            LiveLootContextFactory::forBattle([$initiator, $ally], $this->planetService, $allocation)
        );

        $result = new BattleResult();
        $result->loot = $loot;
        $result->lootAllocatorVersion = $versions->lootAllocator;
        $result->lootPolicyVersion = $versions->lootPolicy;

        // L'initiateur perd un vaisseau sur trois ; l'allie garde les deux siens.
        $initiatorResult = new AttackerFleetResult(self::INITIATOR, $initiator->ownerId, $initiatorUnits);
        $initiatorResult->unitsResult = new UnitCollection();
        $initiatorResult->unitsResult->addUnit($smallCargo, 2);
        $initiatorResult->completelyDestroyed = false;

        $allyResult = new AttackerFleetResult(self::ALLY, $ally->ownerId, $allyUnits);
        $allyResult->unitsResult = clone $allyUnits;
        $allyResult->completelyDestroyed = false;

        $result->attackerFleetResults = [$initiatorResult, $allyResult];

        $engine->runDistributeResources($result);

        return [$result, $versions];
    }

    /**
     * Les capacites survivantes, telles que le moteur les a figees.
     *
     * @return array<int, SurvivingFleetCapacity>
     */
    private function capacitiesOf(BattleResult $result): array
    {
        return array_map(
            static fn (AttackerFleetResult $r): SurvivingFleetCapacity => SurvivingFleetCapacity::fromFleetResult($r),
            $result->attackerFleetResults
        );
    }

    private function anAttackerFleet(int $fleetMissionId, UnitCollection $units, Resources $cargo, bool $initiator): AttackerFleet
    {
        $attacker = new AttackerFleet();
        $attacker->units = clone $units;
        $attacker->player = $this->playerService;
        $attacker->fleetMissionId = $fleetMissionId;
        $attacker->ownerId = $this->playerService->getId();
        $attacker->cargoResources = $cargo;
        $attacker->isInitiator = $initiator;
        $attacker->fleetMission = null;

        return $attacker;
    }
}

/**
 * Expose la repartition du moteur, sans combattre.
 */
class AppliedLootSharesEngineHarness extends BattleEngine
{
    public function runDistributeResources(BattleResult $result): void
    {
        $this->distributeResources($result);
    }

    protected function fightBattleRounds(BattleResult $result): array
    {
        return [];
    }
}
