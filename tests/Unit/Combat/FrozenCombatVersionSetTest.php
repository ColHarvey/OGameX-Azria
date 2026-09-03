<?php

namespace Tests\Unit\Combat;

use LogicException;
use OGame\Combat\Allocation\CappedLoot;
use OGame\Combat\Allocation\ExactLootAllocationV1;
use OGame\Combat\Allocation\LootAllocator;
use OGame\Combat\Allocation\LootAllocatorRegistry;
use OGame\Combat\Causality\CausalEventOrder;
use OGame\Combat\Causality\CausalEventOrderRegistry;
use OGame\Combat\Enums\CombatEventType;
use OGame\Combat\Exceptions\CorruptedRuleVersionSet;
use OGame\Combat\Exceptions\MismatchedRuleVersionSet;
use OGame\Combat\Exceptions\UnknownCausalEventOrderVersion;
use OGame\Combat\MoonDestruction\MoonDestructionRule;
use OGame\Combat\MoonDestruction\MoonDestructionRuleRegistry;
use OGame\Combat\Policies\LootPolicyRegistry;
use OGame\Combat\Policies\LootRateRule;
use OGame\Combat\Projection\SnapshotProjectionRegistry;
use OGame\Combat\Projection\SnapshotProjectionRule;
use OGame\Combat\Support\FrozenCombatVersionSet;
use OGame\Combat\Support\LootPolicy;
use OGame\Combat\Support\ResourceNormalizationDiagnostics;
use OGame\Models\CombatInstance;
use OGame\Models\Resources;
use Tests\UnitTestCase;

/**
 * Les cinq versions d'un combat, choisies une fois et jamais relues.
 *
 * ## Ce que cet ensemble empeche
 *
 * Un combat dure deux heures. Pendant ce temps, une regle peut etre versionnee : l'ordre causal,
 * l'allocateur de butin, la politique de taux, la destruction de lune, la projection de
 * photographie. Un service qui lirait « la
 * version courante » au milieu d'une resolution ferait deriver retroactivement une bataille deja
 * engagee — photographiee sous une regle, reglee sous une autre, et impossible a reproduire.
 *
 * L'ensemble est donc choisi **une seule fois**, a l'ouverture durable, puis persiste. Ensuite, tout
 * lecteur passe par `forVersion()`.
 *
 * ## Ce que ces essais prouvent, et ce qu'ils ne prouvent pas encore
 *
 * Ils prouvent qu'un ensemble V1 recharge reste V1 alors que les courantes ont bouge, que les regles
 * resolues sont bien celles de V1, et que comparer V1 a V2 est refuse.
 *
 * Ils ne prouvent **pas** encore que les decisions, la photographie, le pillage et le plan lunaire
 * d'un combat rejoue sont identiques : cela demande `CombatOpeningService`, qui n'existe pas. Ils
 * couvrent desormais **les cinq** registres, allocateur et projection compris : leurs doubles ne
 * portent qu'une version, et leurs autres methodes levent — ce qui se prouve ici est la
 * selection d'une version, pas un algorithme.
 *
 * Le dire evite de compter ces preuves-la comme acquises.
 */
class FrozenCombatVersionSetTest extends UnitTestCase
{
    /**
     * L'ensemble persiste se relit a l'identique.
     */
    public function testTheSetSurvivesItsOwnStorage(): void
    {
        $choisi = FrozenCombatVersionSet::chosenAtOpening();

        $this->assertSame(
            $choisi->toStorage(),
            FrozenCombatVersionSet::fromStorage($choisi->toStorage())->toStorage(),
            'The version set did not survive a round trip through storage.'
        );
    }

    /**
     * Un combat ouvert en V1 reste en V1, meme quand les courantes passent en V2.
     *
     * **C'est la garantie centrale.** Sans elle, une mise a jour de regle changerait l'issue de
     * toutes les batailles en cours.
     */
    public function testAV1CombatStaysV1WhenTheCurrentSetMovesToV2(): void
    {
        $v1 = FrozenCombatVersionSet::chosenAtOpening();
        $persiste = $v1->toStorage();

        // Cinq V2 factices deviennent les valeurs courantes.
        //
        // **Les cinq bougent, la projection comprise.** En laisser une seule en V1 laisserait un
        // cinquieme de l'ensemble hors de la preuve : un combat aurait pu changer de regle sans
        // que rien ici ne s'en apercoive.
        $v2 = FrozenCombatVersionSet::chosenAtOpening(
            $this->aCausalRegistryOn('causal_event_order_v2'),
            $this->anAllocatorRegistryOn('exact_loot_allocation_v2'),
            $this->aPolicyRegistryOn('cargo_weighted_v2'),
            $this->aMoonRegistryOn('moon_destruction_v2'),
            $this->aProjectionRegistryOn('projection_v2'),
        );

        // **Champ par champ, et non l'ensemble entier.** Comparer les deux ensembles d'un bloc
        // restait vrai avec quatre changements sur cinq : une mutation qui rendait l'allocateur a
        // sa V1 survivait a cet essai, et la part manquante serait restee hors de la preuve.
        foreach ($v1->toStorage() as $regle => $version) {
            $this->assertNotSame(
                $version,
                $v2->toStorage()[$regle],
                'The fake V2 registry for ' . $regle . ' was not actually different: part of the set stays unproven.'
            );
        }

        $recharge = FrozenCombatVersionSet::fromStorage($persiste);

        $this->assertSame(
            $v1->toStorage(),
            $recharge->toStorage(),
            'A combat opened under V1 was reloaded under something else.'
        );
    }

    /**
     * La regle rehydratee est celle du combat, pas la courante.
     */
    public function testTheRuleIsRehydratedFromTheStoredVersion(): void
    {
        $v1 = FrozenCombatVersionSet::chosenAtOpening();

        // Un registre qui connait V1 **et** une V2 courante.
        $registre = CausalEventOrderRegistry::default();

        $this->assertSame(
            $v1->causalOrder,
            $registre->forVersion($v1->causalOrder)->version(),
            'The rule resolved from the stored version is not the one the combat began with.'
        );
    }

    /**
     * Une version inconnue arrete le rejeu au lieu de se rabattre sur la courante.
     *
     * Se rabattre produirait un resultat different de celui qui avait ete calcule, sous le meme
     * identifiant de combat. Mieux vaut un rejeu qui s'arrete qu'un rejeu qui ment.
     */
    public function testAnUnknownVersionStopsTheReplay(): void
    {
        $this->expectException(UnknownCausalEventOrderVersion::class);

        CausalEventOrderRegistry::default()->forVersion('causal_event_order_v_inexistante');
    }

    /**
     * Deux ensembles differents ne se comparent pas : ils s'excluent.
     */
    public function testTwoDifferentSetsRefuseToBeCompared(): void
    {
        $v1 = FrozenCombatVersionSet::chosenAtOpening();
        $autre = FrozenCombatVersionSet::of('causal_event_order_v2', 'a', 'b', 'c', 'proj_v1');

        // Le meme ensemble se compare a lui-meme sans rien dire.
        $v1->ensureSameAs(FrozenCombatVersionSet::fromStorage($v1->toStorage()));

        $this->expectException(MismatchedRuleVersionSet::class);

        $v1->ensureSameAs($autre);
    }

    /**
     * Les versions font partie de l'identite du resultat.
     *
     * Sans elles dans l'empreinte, deux calculs sous deux regles differentes partageraient une
     * empreinte — et un rejeu passerait pour un doublon deja applique.
     */
    public function testTheVersionsBelongToTheFingerprint(): void
    {
        $v1 = FrozenCombatVersionSet::chosenAtOpening();
        $v2 = FrozenCombatVersionSet::of('causal_event_order_v2', 'x', 'y', 'z', 'proj_v1');

        $this->assertNotSame(
            $v1->fingerprintFacts(),
            $v2->fingerprintFacts(),
            'Two different rule sets produce the same fingerprint facts, so a replay would look like a duplicate.'
        );

        $this->assertSame(
            $v1->toStorage(),
            $v1->fingerprintFacts(),
            'The fingerprint carries something other than the persisted versions.'
        );
    }

    /**
     * Une clef manquante s'arrete, elle ne devient pas une chaine vide.
     *
     * ## Le defaut que ces essais ferment
     *
     * `fromStorage()` remplacait une clef absente ou mal typee par `''`. C'est le meme defaut que
     * celui de la photographie d'alliance, applique aux regles : **une corruption de persistance
     * serait devenue une regle indeterminee**, et cette chaine vide serait entree dans l'empreinte
     * du combat — le rendant comparable a n'importe quel autre combat aussi corrompu.
     *
     * Ne pas savoir sous quelles regles un combat s'est ouvert n'est pas savoir qu'il n'en avait
     * pas.
     */
    public function testAMissingKeyIsRefused(): void
    {
        $this->expectException(CorruptedRuleVersionSet::class);

        FrozenCombatVersionSet::fromStorage([
            'causal_order' => 'causal_event_order_v1',
            'loot_allocator' => 'a1',
            'loot_policy' => 'p1',
        ]);
    }

    /**
     * Une clef inconnue signale que ce n'est pas cet ensemble qu'on relit.
     */
    public function testAnUnknownKeyIsRefused(): void
    {
        $this->expectException(CorruptedRuleVersionSet::class);

        FrozenCombatVersionSet::fromStorage([
            'causal_order' => 'causal_event_order_v1',
            'loot_allocator' => 'a1',
            'loot_policy' => 'p1',
            'moon_destruction' => 'm1',
            'inconnue' => 'x',
        ]);
    }

    /**
     * Chacune des quatre versions est refusee si elle n'est pas une chaine.
     */
    public function testEachOfTheFiveVersionsIsRefusedWhenItIsNotAString(): void
    {
        foreach (['causal_order', 'loot_allocator', 'loot_policy', 'moon_destruction', 'projection'] as $clef) {
            $stocke = [
                'causal_order' => 'causal_event_order_v1',
                'loot_allocator' => 'a1',
                'loot_policy' => 'p1',
                'moon_destruction' => 'm1',
                'projection' => 'proj_v1',
            ];

            $stocke[$clef] = 42;

            try {
                FrozenCombatVersionSet::fromStorage($stocke);

                $this->fail('A non-string version was accepted for ' . $clef . '.');
            } catch (CorruptedRuleVersionSet $arret) {
                $this->assertStringContainsString($clef, $arret->defect);
            }
        }
    }

    /**
     * Chacune des quatre versions est refusee si elle est vide.
     *
     * Y compris par `of()` : une version vide passee a la construction serait persistee telle
     * quelle, et le defaut ne se verrait qu'a la relecture d'un combat deja ouvert.
     */
    public function testEachOfTheFiveVersionsIsRefusedWhenItIsEmpty(): void
    {
        $completes = ['causal_event_order_v1', 'a1', 'p1', 'm1', 'proj_v1'];

        foreach ([0, 1, 2, 3, 4] as $rang) {
            $versions = $completes;
            $versions[$rang] = '';

            try {
                FrozenCombatVersionSet::of(...$versions);

                $this->fail('An empty version was accepted at position ' . $rang . '.');
            } catch (CorruptedRuleVersionSet $arret) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Un ensemble complet fait l'aller-retour sans rien perdre.
     */
    public function testACompleteSetSurvivesTheRoundTrip(): void
    {
        $ensemble = FrozenCombatVersionSet::of('causal_event_order_v1', 'a1', 'p1', 'm1', 'proj_v1');

        $this->assertSame(
            $ensemble->toStorage(),
            FrozenCombatVersionSet::fromStorage($ensemble->toStorage())->toStorage()
        );
    }

    /**
     * Un registre d'allocateurs pose sur cette version, et rien d'autre.
     *
     * ## Pourquoi ce double existe enfin
     *
     * Cet essai laissait l'allocateur en V1 pendant que les trois autres passaient en V2, et le
     * disait franchement : son interface porte quatre methodes aux types riches, et un factice
     * credible aurait coute plus que la preuve n'en valait.
     *
     * C'etait une mauvaise lecture de ce qu'il fallait prouver. **Ce qui se teste ici, c'est la
     * selection d'une version, pas un algorithme d'allocation.** Une seule methode est donc reelle —
     * celle qui porte la version — et les trois autres levent : si l'une d'elles etait appelee, cet
     * essai ferait bien plus que ce qu'il annonce, et il vaut mieux l'apprendre par une exception
     * que par un resultat plausible.
     */
    private function anAllocatorRegistryOn(string $version): LootAllocatorRegistry
    {
        $allocateur = new class ($version) implements LootAllocator {
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

        return LootAllocatorRegistry::of([$allocateur], $version);
    }

    /**
     * Un registre de projection pose sur cette version.
     *
     * Comme les autres doubles : seule `version()` est reelle. Ce qui se prouve ici est la selection
     * d'une version, pas la facon dont une photographie se lit.
     */
    private function aProjectionRegistryOn(string $version): SnapshotProjectionRegistry
    {
        $regle = new class ($version) implements SnapshotProjectionRule {
            public function __construct(private string $version)
            {
            }

            public function version(): string
            {
                return $this->version;
            }
        };

        return SnapshotProjectionRegistry::of([$regle], $version);
    }

    /**
     * Un registre causal dont la version courante est celle-ci.
     */
    private function aCausalRegistryOn(string $version): CausalEventOrderRegistry
    {
        return CausalEventOrderRegistry::of([$this->aCausalOrder($version)], $version);
    }

    /**
     * Un ordre causal factice, qui ne sert qu'a porter une version.
     */
    private function aCausalOrder(string $version): CausalEventOrder
    {
        return new class ($version) implements CausalEventOrder {
            public function __construct(private string $version)
            {
            }

            public function version(): string
            {
                return $this->version;
            }

            public function rankOf(CombatEventType $type): int
            {
                return 1;
            }
        };
    }

    /**
     * Un registre de politiques dont la version courante est celle-ci.
     */
    private function aPolicyRegistryOn(string $version): LootPolicyRegistry
    {
        $regle = new class ($version) implements LootRateRule {
            public function __construct(private string $version)
            {
            }

            public function version(): string
            {
                return $this->version;
            }

            public function rateInBasisPoints(LootPolicy $facts): int
            {
                return 5_000;
            }
        };

        return LootPolicyRegistry::of([$regle], $version);
    }

    /**
     * Un registre de regles lunaires dont la version courante est celle-ci.
     */
    private function aMoonRegistryOn(string $version): MoonDestructionRuleRegistry
    {
        $regle = new class ($version) implements MoonDestructionRule {
            public function __construct(private string $version)
            {
            }

            public function version(): string
            {
                return $this->version;
            }

            public function destructionChance(int $moonDiameter, int $deathstarCount): float
            {
                return 0.0;
            }

            public function deathstarLossChance(int $moonDiameter): float
            {
                return 0.0;
            }

            public function succeeds(int $roll, float $chance): bool
            {
                return false;
            }

            public function thresholdFor(float $chance): int
            {
                return 0;
            }
        };

        return MoonDestructionRuleRegistry::of([$regle], $version);
    }

    /**
     * Les cinq colonnes d'une instance se relisent en un ensemble, dans le bon ordre.
     */
    public function testAnInstanceIsReadFromItsFiveColumns(): void
    {
        $combat = new CombatInstance([
            'causal_order_version' => 'causal_order_v1',
            'loot_allocator_version' => 'exact_loot_allocation_v1',
            'loot_policy_version' => 'loot_policy_v1',
            'moon_destruction_rule_version' => 'moon_destruction_v1',
            'projection_version' => 'projection_v1',
        ]);

        $versions = FrozenCombatVersionSet::fromInstance($combat);

        $this->assertSame('causal_order_v1', $versions->causalOrder);
        $this->assertSame('exact_loot_allocation_v1', $versions->lootAllocator);
        $this->assertSame('loot_policy_v1', $versions->lootPolicy);
        $this->assertSame('moon_destruction_v1', $versions->moonDestruction);
        $this->assertSame('projection_v1', $versions->projection);
    }

    /**
     * Une colonne vide est une corruption nommee, pas une version par defaut.
     *
     * L'ouverture ecrit les cinq colonnes en meme temps : un combat qui n'en porte que quatre n'a
     * pas ete ouvert ici, et le regler sous une version devinee serait regler une autre bataille.
     */
    public function testAnInstanceWithAMissingVersionIsRefused(): void
    {
        $combat = new CombatInstance([
            'causal_order_version' => 'causal_order_v1',
            'loot_allocator_version' => 'exact_loot_allocation_v1',
            'loot_policy_version' => 'loot_policy_v1',
            'moon_destruction_rule_version' => 'moon_destruction_v1',
            'projection_version' => null,
        ]);

        try {
            FrozenCombatVersionSet::fromInstance($combat);
            $this->fail('An instance with a missing version was read as a complete set.');
        } catch (CorruptedRuleVersionSet $refus) {
            $this->assertStringContainsString('projection', $refus->defect, 'The refusal does not name the missing column.');
        }
    }
}
