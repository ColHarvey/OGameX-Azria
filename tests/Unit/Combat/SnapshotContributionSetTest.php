<?php

namespace Tests\Unit\Combat;

use OGame\Combat\Enums\SnapshotContribution;
use OGame\Combat\Exceptions\CorruptedSnapshotInclusion;
use OGame\Combat\Support\SnapshotContributionSet;
use PHPUnit\Framework\TestCase;

/**
 * Ce qu'un evenement apporte : un ensemble canonique, jamais une valeur seule.
 *
 * ## Pourquoi un ensemble
 *
 * Les contributions se cumulent, et les cas existent deja dans le domaine : un retour ou un
 * deploiement apporte `DeliveredFleet` **et** `DeliveredCargo` ; l'etat d'une cible apporte
 * `TargetResources`, `TargetDefences` **et** `DefendingFleet`. Une colonne a valeur unique aurait
 * force ces evenements en plusieurs lignes — et la ligne serait devenue l'unite d'unicite a la
 * place de l'evenement.
 *
 * ## Pourquoi canonique
 *
 * Trie, sans doublon, non vide. Ce ne sont pas trois exigences d'esthetique : un doublon compterait
 * ses unites deux fois, un ensemble vide serait un evenement qui n'apporte rien, et une permutation
 * ferait produire deux JSON differents pour un meme fait — le rejeu ne reconnaitrait plus ce qu'il
 * a deja inscrit.
 */
class SnapshotContributionSetTest extends TestCase
{
    /**
     * Un retour charge apporte deux choses, et cela tient dans un seul ensemble.
     */
    public function testALoadedReturnBringsTwoContributions(): void
    {
        $ensemble = SnapshotContributionSet::of([
            SnapshotContribution::DeliveredCargo,
            SnapshotContribution::DeliveredFleet,
        ]);

        $this->assertCount(2, $ensemble->all());
        $this->assertSame(['delivered_cargo', 'delivered_fleet'], $ensemble->toStorage());
    }

    /**
     * L'etat d'une cible en apporte trois.
     */
    public function testATargetStateBringsThreeContributions(): void
    {
        $ensemble = SnapshotContributionSet::of([
            SnapshotContribution::TargetResources,
            SnapshotContribution::TargetDefences,
            SnapshotContribution::DefendingFleet,
        ]);

        $this->assertSame(
            ['defending_fleet', 'target_defences', 'target_resources'],
            $ensemble->toStorage()
        );
    }

    /**
     * L'ordre d'entree ne change pas la forme ecrite.
     *
     * Deux ecritures du meme fait doivent produire le meme JSON, sinon le rejeu ne reconnait pas ce
     * qu'il a deja inscrit.
     */
    public function testTheWrittenFormDoesNotDependOnTheOrderOfEntry(): void
    {
        $premier = SnapshotContributionSet::of([
            SnapshotContribution::TargetResources,
            SnapshotContribution::DefendingFleet,
        ]);

        $second = SnapshotContributionSet::of([
            SnapshotContribution::DefendingFleet,
            SnapshotContribution::TargetResources,
        ]);

        $this->assertSame($premier->toStorage(), $second->toStorage());
        $this->assertTrue($premier->equals($second));
    }

    /**
     * Un doublon est refuse : ses unites seraient comptees deux fois.
     */
    public function testADuplicateIsRefused(): void
    {
        $this->expectException(CorruptedSnapshotInclusion::class);

        SnapshotContributionSet::of([
            SnapshotContribution::AttackingFleet,
            SnapshotContribution::AttackingFleet,
        ]);
    }

    /**
     * Un ensemble vide est refuse : un evenement qui n'apporte rien n'entre pas.
     */
    public function testAnEmptySetIsRefused(): void
    {
        $this->expectException(CorruptedSnapshotInclusion::class);

        SnapshotContributionSet::of([]);
    }

    /**
     * Une permutation persistee est refusee a la relecture.
     *
     * Accepter en silence une forme non canonique rendrait deux ecritures du meme fait
     * indistinguables a l'oeil et differentes a l'octet.
     */
    public function testAStoredPermutationIsRefused(): void
    {
        $this->expectException(CorruptedSnapshotInclusion::class);

        SnapshotContributionSet::fromStorage(['target_resources', 'defending_fleet']);
    }

    /**
     * Une contribution qui n'existe pas est refusee.
     */
    public function testAnUnknownStoredContributionIsRefused(): void
    {
        $this->expectException(CorruptedSnapshotInclusion::class);

        SnapshotContributionSet::fromStorage(['attacking_fleet', 'contribution_inventee']);
    }

    /**
     * Une structure qui n'est pas une liste est refusee.
     */
    public function testAStoredStructureThatIsNotAListIsRefused(): void
    {
        $this->expectException(CorruptedSnapshotInclusion::class);

        SnapshotContributionSet::fromStorage(['a' => 'attacking_fleet']);
    }

    /**
     * Un ensemble canonique fait l'aller-retour sans rien perdre.
     */
    public function testACanonicalSetSurvivesTheRoundTrip(): void
    {
        $ensemble = SnapshotContributionSet::of([
            SnapshotContribution::TargetDefences,
            SnapshotContribution::DefendingFleet,
            SnapshotContribution::TargetResources,
        ]);

        $this->assertTrue(
            $ensemble->equals(SnapshotContributionSet::fromStorage($ensemble->toStorage()))
        );
    }

    /**
     * L'ensemble sait s'il apporte une flotte qui se battra.
     *
     * Seules celles-la peuvent retenir la fenetre de ralliement : des ressources livrees ne font
     * attendre aucune bataille.
     */
    public function testTheSetKnowsWhetherItBringsAFightingFleet(): void
    {
        $this->assertTrue(
            SnapshotContributionSet::ofOne(SnapshotContribution::AttackingFleet)->bringsAFightingFleet()
        );

        $this->assertFalse(
            SnapshotContributionSet::of([
                SnapshotContribution::DeliveredCargo,
                SnapshotContribution::DeliveredFleet,
            ])->bringsAFightingFleet()
        );
    }
}
