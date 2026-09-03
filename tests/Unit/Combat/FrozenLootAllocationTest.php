<?php

namespace Tests\Unit\Combat;

use LogicException;
use OGame\Combat\Allocation\CappedLoot;
use OGame\Combat\Allocation\ExactLootAllocationV1;
use OGame\Combat\Allocation\FrozenLootAllocation;
use OGame\Combat\Allocation\LootAllocator;
use OGame\Combat\Allocation\LootAllocatorRegistry;
use OGame\Combat\Exceptions\UnknownLootAllocatorVersion;
use OGame\Combat\Support\FrozenCombatVersionSet;
use OGame\Combat\Support\ResourceNormalizationDiagnostics;
use OGame\Models\Resources;
use Tests\UnitTestCase;

/**
 * Un combat garde l'allocateur sous lequel il s'est ouvert.
 *
 * ## Le chemin de derive que ces essais ferment
 *
 * `LootService::distribute()` demandait au registre son allocateur courant **au milieu du calcul**.
 * Chaque plafonnement d'une meme resolution reposait donc la question : un deploiement survenu entre
 * deux appels aurait plafonne la premiere moitie d'une bataille sous une regle et la seconde sous
 * une autre. Le resultat n'aurait ete reproductible par personne, et rien ne l'aurait signale.
 *
 * ## Le rejeu, et ce qu'il exige
 *
 * Un combat ouvert sous V1 doit se rejouer sous V1, **meme quand V2 est devenue la version
 * courante**. C'est exactement ce que le dernier essai installe : une V2 courante, un ensemble gele
 * V1, et l'allocateur rendu reste celui de l'ouverture.
 */
class FrozenLootAllocationTest extends UnitTestCase
{
    /**
     * L'allocateur vient des faits geles, pas de la version courante.
     */
    public function testTheAllocatorComesFromTheFrozenFactsAndNotFromTheCurrentVersion(): void
    {
        $registre = LootAllocatorRegistry::of(
            [$this->anAllocatorOn('alloc_v1'), $this->anAllocatorOn('alloc_v2')],
            'alloc_v2'
        );

        $geles = FrozenCombatVersionSet::of('causal_event_order_v1', 'alloc_v1', 'p1', 'm1', 'proj_v1');

        $allocation = FrozenLootAllocation::fromFrozenSet($geles, $registre);

        $this->assertSame(
            'alloc_v1',
            $allocation->version,
            'The combat was replayed under the current allocator instead of the one it began with.'
        );

        $this->assertSame('alloc_v1', $allocation->allocator()->version());
    }

    /**
     * Une version gelee que le registre ne connait plus arrete le rejeu.
     *
     * Se rabattre sur la version courante produirait un resultat different de celui qui avait ete
     * calcule, sous le meme identifiant de combat.
     */
    public function testAFrozenVersionTheRegistryNoLongerKnowsStopsTheReplay(): void
    {
        $registre = LootAllocatorRegistry::of([$this->anAllocatorOn('alloc_v2')], 'alloc_v2');

        $geles = FrozenCombatVersionSet::of('causal_event_order_v1', 'alloc_disparue', 'p1', 'm1', 'proj_v1');

        $this->expectException(UnknownLootAllocatorVersion::class);

        FrozenLootAllocation::fromFrozenSet($geles, $registre);
    }

    /**
     * L'operation instantanee choisit la version courante, une fois, a son entree.
     *
     * C'est la frontiere explicite du chemin instantane : la lecture a un endroit nomme, verifiable
     * par la garde architecturale, au lieu d'etre dispersee dans les services de calcul.
     */
    public function testAnInstantOperationPicksTheCurrentVersionOnceAtItsStart(): void
    {
        $registre = LootAllocatorRegistry::of(
            [$this->anAllocatorOn('alloc_v1'), $this->anAllocatorOn('alloc_v2')],
            'alloc_v2'
        );

        $allocation = FrozenLootAllocation::atOperationStart($registre);

        $this->assertSame('alloc_v2', $allocation->version);
    }

    /**
     * Le plafonnement passe par l'allocateur de cette operation, et par lui seul.
     */
    public function testTheCapGoesThroughTheAllocatorOfThisOperation(): void
    {
        $registre = LootAllocatorRegistry::default();
        $allocation = FrozenLootAllocation::atOperationStart($registre);

        $plafonne = $allocation->capByCargo(new Resources(10, 20, 30, 0), 1_000);

        $this->assertSame(60.0, $plafonne->resources->sum(), 'Nothing was to be capped here.');
    }

    /**
     * Un allocateur factice, qui ne porte qu'une version.
     *
     * Ses trois autres methodes levent : ce qui se prouve ici est la **selection** d'une version,
     * pas un algorithme d'allocation. Si l'une d'elles etait appelee, ces essais feraient bien plus
     * que ce qu'ils annoncent, et il vaut mieux l'apprendre par une exception que par un resultat
     * plausible.
     */
    private function anAllocatorOn(string $version): LootAllocator
    {
        return new class ($version) implements LootAllocator {
            public function __construct(private string $version)
            {
            }

            public function version(): string
            {
                return $this->version;
            }

            public function lootableAmount(
                float $inStock,
                int $rateInBasisPoints,
                string $phase,
                ResourceNormalizationDiagnostics &$diagnostics,
            ): int {
                throw new LogicException('Ce double ne porte qu une version : rien ne doit lui demander de piller.');
            }

            public function capByCargo(Resources $loot, int $totalCargoCapacity, string $phase = ExactLootAllocationV1::PHASE_TARGET_LOOT, string $subject = ''): CappedLoot
            {
                throw new LogicException('Ce double ne porte qu une version : rien ne doit lui demander de plafonner.');
            }

            /**
             * @param array<int, int> $weights
             * @param array<int, int> $remainingCapacity
             * @return array<int, int>
             */
            public function shareBetweenFleets(
                int $amount,
                array $weights,
                array $remainingCapacity,
                int $initiatorFleetMissionId,
            ): array {
                throw new LogicException('Ce double ne porte qu une version : rien ne doit lui demander de repartir.');
            }
        };
    }
}
