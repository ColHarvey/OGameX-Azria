<?php

namespace Tests\Unit\Combat;

use InvalidArgumentException;
use OGame\Combat\Causality\CausalEventOrder;
use OGame\Combat\Causality\CausalEventOrderRegistry;
use OGame\Combat\Causality\CausalEventOrderV1;
use OGame\Combat\Enums\CombatEventType;
use OGame\Combat\Exceptions\MismatchedCausalEventOrder;
use OGame\Combat\Exceptions\UnknownCausalEventOrderVersion;
use OGame\Combat\Support\EffectOrderKey;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\UnitTestCase;

/**
 * L'ordre des effets simultanes, sa version, et ce qui ne varie jamais.
 *
 * ## Une seule des quatre regles est versionnee
 *
 *     heure planifiee d'abord           -> invariant
 *     barriere avant tout evenement     -> invariant
 *     departage par identite persistee  -> invariant
 *     ordre entre genres                -> **versionne**
 *
 * Les trois invariants ont leurs essais permanents ici. Les melanger au contrat versionne
 * laisserait croire qu'une v2 pourrait faire passer un effet avant sa propre barriere : ce n'est
 * pas une variation de regle, c'est une incoherence.
 */
class CausalEventOrderTest extends UnitTestCase
{
    /**
     * La v1 classe les quatre genres, exhaustivement et sans branche par defaut.
     *
     * Le `match` leverait une `UnhandledMatchError` sur un genre nouveau. Ce parcours la declenche
     * dans la suite d'essais plutot qu'en production, sur le premier evenement du genre oublie.
     */
    public function testTheFirstVersionRanksEveryEventKind(): void
    {
        $ordre = new CausalEventOrderV1();
        $rangs = [];

        foreach (CombatEventType::cases() as $genre) {
            $rang = $ordre->rankOf($genre);

            $this->assertGreaterThan(
                0,
                $rang,
                "The kind « {$genre->value} » carries rank zero or less, which belongs to barriers alone."
            );

            $rangs[$genre->value] = $rang;
        }

        $this->assertCount(
            count($rangs),
            array_unique($rangs),
            'Two kinds share a rank, so their order at the same second is left to chance.'
        );
    }

    /**
     * L'ordre decide, et ce qu'il decide se lit en jeu.
     *
     * - **recherche avant chantier** : l'unite construite se bat avec la technologie nouvelle ;
     * - **chantier avant missile** : la defense achevee existe deja, et **le missile peut la
     *   detruire** ;
     * - **missile avant arrivee** : l'arrivee voit la cible telle que le missile l'a laissee.
     */
    public function testWhatTheOrderDecidesIsReadableInTheGame(): void
    {
        $ordre = new CausalEventOrderV1();

        $this->assertLessThan(
            $ordre->rankOf(CombatEventType::QueueCompletion),
            $ordre->rankOf(CombatEventType::ResearchCompletion),
            'A unit finished with a research of the same second would fight with the old technology.'
        );

        $this->assertLessThan(
            $ordre->rankOf(CombatEventType::MissileImpact),
            $ordre->rankOf(CombatEventType::QueueCompletion),
            'A defence finished at the same second as an impact could no longer be destroyed by it.'
        );

        $this->assertLessThan(
            $ordre->rankOf(CombatEventType::FleetArrival),
            $ordre->rankOf(CombatEventType::MissileImpact),
            'An arriving fleet would see the target as it was before the missile.'
        );
    }

    /**
     * Une barriere precede tout evenement de la meme seconde, dans toutes les versions.
     */
    public function testABarrierComesBeforeEveryEventOfTheSameSecond(): void
    {
        foreach ([new CausalEventOrderV1(), $this->aReversedSecondVersion()] as $ordre) {
            $barriere = EffectOrderKey::barrierAt(1_000, $ordre);

            $this->assertTrue($barriere->isBarrier());

            foreach (CombatEventType::cases() as $rang => $genre) {
                $evenement = EffectOrderKey::forEvent(1_000, $genre, $rang + 1, $ordre);

                $this->assertTrue(
                    $barriere->isBefore($evenement),
                    'A ' . $genre->value . ' slipped before the barrier of its own second under '
                    . $ordre->version() . '.'
                );

                $this->assertFalse($evenement->isBarrier());
            }
        }
    }

    /**
     * L'heure planifiee prime sur le genre, toujours.
     */
    public function testThePlannedInstantOutranksTheKind(): void
    {
        $ordre = new CausalEventOrderV1();

        // Une arrivee — le rang le plus eleve — mais une seconde plus tot.
        $tot = EffectOrderKey::forEvent(1_000, CombatEventType::FleetArrival, 1, $ordre);
        $tard = EffectOrderKey::forEvent(1_001, CombatEventType::ResearchCompletion, 2, $ordre);

        $this->assertTrue($tot->isBefore($tard), 'The kind ranking overtook the planned instant.');
    }

    /**
     * Toutes les permutations d'un meme ensemble donnent le meme ordre.
     */
    public function testEveryPermutationYieldsTheSameOrder(): void
    {
        $ordre = new CausalEventOrderV1();

        $cles = [
            'arrivee' => EffectOrderKey::forEvent(1_020, CombatEventType::FleetArrival, 1, $ordre),
            'missile' => EffectOrderKey::forEvent(1_020, CombatEventType::MissileImpact, 2, $ordre),
            'chantier' => EffectOrderKey::forEvent(1_020, CombatEventType::QueueCompletion, 3, $ordre),
            'recherche' => EffectOrderKey::forEvent(1_020, CombatEventType::ResearchCompletion, 4, $ordre),
        ];

        $attendu = ['recherche', 'chantier', 'missile', 'arrivee'];
        $reference = null;

        foreach ($this->permutationsOf(array_keys($cles)) as $permutation) {
            $trie = $permutation;

            usort($trie, static fn (string $a, string $b): int => $cles[$a]->compareTo($cles[$b]));

            $reference ??= $trie;

            $this->assertSame($attendu, $trie, 'A permutation of the same events produced another order.');
            $this->assertSame($reference, $trie);
        }
    }

    /**
     * Un rang nul ou negatif est refuse pour un evenement reel.
     */
    public function testARankOfZeroIsRefusedForARealEvent(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EffectOrderKey::forEvent(1_000, CombatEventType::FleetArrival, 1, $this->anOrderThatRanksEverythingZero());
    }

    /**
     * Deux cles de versions differentes refusent d'etre comparees.
     *
     * ## Pourquoi ce n'est pas de la prudence excessive
     *
     * Un rang 2 sous v1 designe un chantier ; sous une v2 qui inverserait deux genres, il en
     * designerait un autre. Les mettre sur la meme echelle donnerait un ordre plausible et faux —
     * exactement le genre de defaut qui ne se voit qu'en production, sur deux evenements de la meme
     * seconde.
     */
    public function testTwoKeysOfDifferentVersionsRefuseToBeCompared(): void
    {
        $v1 = EffectOrderKey::forEvent(1_000, CombatEventType::FleetArrival, 1, new CausalEventOrderV1());
        $v2 = EffectOrderKey::forEvent(1_000, CombatEventType::FleetArrival, 1, $this->aReversedSecondVersion());

        $this->expectException(MismatchedCausalEventOrder::class);

        $v1->compareTo($v2);
    }

    /**
     * Une barriere d'une version ne se compare pas non plus a un evenement d'une autre.
     */
    public function testABarrierOfOneVersionDoesNotCompareToAnEventOfAnother(): void
    {
        $barriere = EffectOrderKey::barrierAt(1_000, new CausalEventOrderV1());
        $evenement = EffectOrderKey::forEvent(1_000, CombatEventType::FleetArrival, 1, $this->aReversedSecondVersion());

        $this->expectException(MismatchedCausalEventOrder::class);

        $barriere->isBefore($evenement);
    }

    /**
     * Un combat v1 se relit sous v1, meme quand une v2 est devenue courante.
     *
     * C'est la raison d'etre du registre : la version persistee **selectionne** l'implementation,
     * elle n'est jamais comparee a la version courante.
     */
    public function testAV1CombatIsReadBackUnderV1EvenWhenV2IsCurrent(): void
    {
        $v2 = $this->aReversedSecondVersion();
        $registre = CausalEventOrderRegistry::of([new CausalEventOrderV1(), $v2], $v2->version());

        $this->assertSame($v2->version(), $registre->currentVersion());

        $relu = $registre->forVersion(CausalEventOrderV1::VERSION);

        $this->assertInstanceOf(CausalEventOrderV1::class, $relu);
        $this->assertSame(1, $relu->rankOf(CombatEventType::ResearchCompletion));

        // Et la v2 factice classe bien autrement : sans cela, l'essai passerait meme si le registre
        // rendait toujours la version courante.
        $this->assertNotSame(
            $relu->rankOf(CombatEventType::ResearchCompletion),
            $v2->rankOf(CombatEventType::ResearchCompletion),
            'The fake second version ranks exactly like the first, so this test proves nothing.'
        );
    }

    /**
     * Une version inconnue arrete le traitement.
     */
    public function testAnUnknownVersionStopsTheProcessing(): void
    {
        $this->expectException(UnknownCausalEventOrderVersion::class);

        CausalEventOrderRegistry::default()->forVersion('causal_event_order_v99');
    }

    /**
     * Deux implementations ne peuvent pas se reclamer de la meme version.
     */
    public function testTwoImplementationsMayNotClaimTheSameVersion(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CausalEventOrderRegistry::of(
            [new CausalEventOrderV1(), new CausalEventOrderV1()],
            CausalEventOrderV1::VERSION
        );
    }

    /**
     * `current()` n'est appele que la ou l'ouverture se fixe.
     *
     * ## Garde architecturale, et ce qu'elle protege
     *
     * Un worker reveille apres le deploiement d'une v2 ne doit pas convertir en v2 une ouverture
     * deja fixee sous v1 au seul motif qu'il a travaille en retard. La seule facon d'en etre sur est
     * qu'aucun chemin de fermeture, de resolution ou de worker n'appelle `current()`.
     *
     * La liste blanche est vide aujourd'hui : le chemin d'ouverture n'est pas encore ecrit. Elle
     * grandira d'un fichier — celui de l'ouverture — et pas davantage.
     */
    public function testTheCurrentVersionIsOnlyChosenWhereTheOpeningIsFixed(): void
    {
        // **La frontiere autoritaire, et les deux inscriptions provisoires.**
        //
        // `CombatRuleVersionSet` est faite pour cela : choisir les quatre versions courantes une
        // seule fois, a l'ouverture durable.
        //
        // Les deux autres appartiennent au combat **instantane**, qui se resout a la seconde ou la
        // flotte arrive. Y lire la version courante est correct tant qu'aucun combat ne dure ; elles
        // sortiront de cette liste le jour ou le combat persistant leur passera l'ensemble gele.
        $autorises = [
            'Combat/Support/CombatRuleVersionSet.php',
            'Combat/Support/LiveLootContextFactory.php',
            'GameMissions/BattleEngine/Services/LootService.php',
        ];

        // Les quatre mecanismes versionnes. En surveiller un seul laissait les trois autres deriver.
        $registres = [
            'CausalEventOrderRegistry',
            'LootAllocatorRegistry',
            'LootPolicyRegistry',
            'MoonDestructionRuleRegistry',
        ];

        $appelants = [];

        foreach ($this->phpFilesOf(app_path()) as $fichier) {
            $source = file_get_contents($fichier);

            if ($source === false) {
                continue;
            }

            // **`currentVersion()` autant que `current()`.** Les deux choisissent la version
            // courante ; ne surveiller que la premiere laissait passer exactement l'appel que le
            // service d'ouverture s'appretait a faire.
            if (!str_contains($source, '->current()') && !str_contains($source, '->currentVersion()')) {
                continue;
            }

            foreach ($registres as $registre) {
                if (str_contains($source, $registre)) {
                    $appelants[] = str_replace('\\', '/', substr($fichier, strlen(app_path()) + 1));

                    break;
                }
            }
        }

        sort($appelants);

        $this->assertSame(
            $autorises,
            $appelants,
            'A path outside the durable opening picks a current rule version. A late worker would then '
            . 'resolve under a rule that the combat never began with.'
        );
    }

    /**
     * Les fichiers PHP d'un dossier, recursivement.
     *
     * @param string $directory
     * @return array<int, string>
     */
    private function phpFilesOf(string $directory): array
    {
        $fichiers = [];

        $iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($iterateur as $entree) {
            if ($entree instanceof SplFileInfo && $entree->getExtension() === 'php') {
                $fichiers[] = $entree->getPathname();
            }
        }

        return $fichiers;
    }

    /**
     * Une seconde version, qui inverse deux genres.
     *
     * Elle n'existe que dans cet essai. Sans elle, le controle de rejeu passerait meme si le
     * registre rendait toujours la version courante.
     */
    private function aReversedSecondVersion(): CausalEventOrder
    {
        return new class () implements CausalEventOrder {
            public function version(): string
            {
                return 'causal_event_order_v2_test';
            }

            public function rankOf(CombatEventType $type): int
            {
                return match ($type) {
                    CombatEventType::FleetArrival => 1,
                    CombatEventType::MissileImpact => 2,
                    CombatEventType::QueueCompletion => 3,
                    CombatEventType::ResearchCompletion => 4,
                };
            }
        };
    }

    /**
     * Un ordre qui classerait tout au rang zero : il doit etre refuse.
     */
    private function anOrderThatRanksEverythingZero(): CausalEventOrder
    {
        return new class () implements CausalEventOrder {
            public function version(): string
            {
                return 'causal_event_order_zero_test';
            }

            public function rankOf(CombatEventType $type): int
            {
                return 0;
            }
        };
    }

    /**
     * Toutes les permutations d'une liste.
     *
     * @param array<int, string> $items
     * @return array<int, array<int, string>>
     */
    private function permutationsOf(array $items): array
    {
        if (count($items) <= 1) {
            return [$items];
        }

        $permutations = [];

        foreach ($items as $rang => $item) {
            $reste = $items;
            unset($reste[$rang]);

            foreach ($this->permutationsOf(array_values($reste)) as $permutation) {
                $permutations[] = [$item, ...$permutation];
            }
        }

        return $permutations;
    }
}
