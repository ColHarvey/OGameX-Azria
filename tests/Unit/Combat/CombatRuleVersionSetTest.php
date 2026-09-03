<?php

namespace Tests\Unit\Combat;

use OGame\Combat\Causality\CausalEventOrder;
use OGame\Combat\Causality\CausalEventOrderRegistry;
use OGame\Combat\Enums\CombatEventType;
use OGame\Combat\Exceptions\MismatchedRuleVersionSet;
use OGame\Combat\Exceptions\UnknownCausalEventOrderVersion;
use OGame\Combat\MoonDestruction\MoonDestructionRule;
use OGame\Combat\MoonDestruction\MoonDestructionRuleRegistry;
use OGame\Combat\Policies\LootPolicyRegistry;
use OGame\Combat\Policies\LootRateRule;
use OGame\Combat\Support\CombatRuleVersionSet;
use OGame\Combat\Support\LootPolicy;
use Tests\UnitTestCase;

/**
 * Les quatre versions d'un combat, choisies une fois et jamais relues.
 *
 * ## Ce que cet ensemble empeche
 *
 * Un combat dure deux heures. Pendant ce temps, une regle peut etre versionnee : l'ordre causal,
 * l'allocateur de butin, la politique de taux, la destruction de lune. Un service qui lirait « la
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
 * ne couvrent pas non plus l'allocateur de butin, dont l'interface porte quatre methodes aux
 * types riches — un factice credible y couterait plus que la preuve n'en vaut, et un factice
 * bacle ferait croire a une couverture qu'il n'a pas.
 *
 * Le dire evite de compter ces preuves-la comme acquises.
 */
class CombatRuleVersionSetTest extends UnitTestCase
{
    /**
     * L'ensemble persiste se relit a l'identique.
     */
    public function testTheSetSurvivesItsOwnStorage(): void
    {
        $choisi = CombatRuleVersionSet::chosenAtOpening();

        $this->assertSame(
            $choisi->toStorage(),
            CombatRuleVersionSet::fromStorage($choisi->toStorage())->toStorage(),
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
        $v1 = CombatRuleVersionSet::chosenAtOpening();
        $persiste = $v1->toStorage();

        // Trois V2 factices deviennent les valeurs courantes.
        //
        // **L'allocateur garde la sienne, et c'est dit plutot que masque.** Son interface porte
        // quatre methodes aux types riches ; un factice credible y demanderait plus de code que la
        // preuve n'en vaut, et un factice bacle ferait croire a une couverture qu'il n'a pas.
        $v2 = CombatRuleVersionSet::chosenAtOpening(
            $this->aCausalRegistryOn('causal_event_order_v2'),
            null,
            $this->aPolicyRegistryOn('cargo_weighted_v2'),
            $this->aMoonRegistryOn('moon_destruction_v2'),
        );

        $this->assertNotSame(
            $v1->toStorage(),
            $v2->toStorage(),
            'The fake V2 registries were not actually different: the test would prove nothing.'
        );

        $recharge = CombatRuleVersionSet::fromStorage($persiste);

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
        $v1 = CombatRuleVersionSet::chosenAtOpening();

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
        $v1 = CombatRuleVersionSet::chosenAtOpening();
        $autre = CombatRuleVersionSet::of('causal_event_order_v2', 'a', 'b', 'c');

        // Le meme ensemble se compare a lui-meme sans rien dire.
        $v1->ensureSameAs(CombatRuleVersionSet::fromStorage($v1->toStorage()));

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
        $v1 = CombatRuleVersionSet::chosenAtOpening();
        $v2 = CombatRuleVersionSet::of('causal_event_order_v2', 'x', 'y', 'z');

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
}
