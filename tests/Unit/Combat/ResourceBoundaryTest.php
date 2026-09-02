<?php

namespace Tests\Unit\Combat;

use OGame\Combat\Exceptions\CorruptedResourceAmount;
use OGame\Combat\Exceptions\UnrepresentableResourceAmount;
use OGame\Combat\Support\ResourceBoundary;
use OGame\Combat\Support\ResourceNormalizationDiagnostics;
use Tests\UnitTestCase;

/**
 * La frontiere entre les soldes flottants du jeu et les faits entiers du combat.
 *
 * ## Trois categories, et pourquoi la troisieme n'est pas une erreur
 *
 * Un `double` cesse de distinguer les entiers voisins au-dela de deux puissance cinquante-trois.
 * **Refuser ces valeurs donnerait une immunite economique** : une planete assez riche ferait echouer
 * toute attaque, toute collecte, tout recyclage — un verrou gagne en jouant.
 *
 * La conversion a donc lieu, vers l'entier canonique que le `double` represente reellement. Elle ne
 * recupere pas l'unite deja perdue par la colonne ; elle empeche toute **nouvelle** divergence
 * pendant le combat.
 *
 * La vraie limite dure est celle ou la conversion entiere elle-meme cesse d'etre sure — et elle ne
 * se teste pas en comparant a `(float) PHP_INT_MAX`, qui n'est pas representable exactement.
 */
class ResourceBoundaryTest extends UnitTestCase
{
    /**
     * Les valeurs ordinaires traversent, arrondies vers le bas.
     */
    public function testOrdinaryAmountsCrossRoundedDown(): void
    {
        foreach ([
            '100,00' => [100.00, 100],
            '100,99' => [100.99, 100],
            '0,99' => [0.99, 0],
            '0,00' => [0.00, 0],
        ] as $quoi => [$valeur, $attendu]) {
            $normalise = ResourceBoundary::wholeUnitsOfLivingStock($valeur, 'metal');

            $this->assertSame($attendu, $normalise->units, "« {$quoi} » did not convert as expected.");
            $this->assertFalse($normalise->diagnostics->any(), "« {$quoi} » raised a diagnostic it should not have.");
        }
    }

    /**
     * Un petit artefact negatif est ramene a zero, avec un diagnostic.
     *
     * Le moteur rencontre ces soldes depuis toujours. Les refuser ferait echouer des combats qui se
     * deroulent aujourd'hui sans incident ; les ramener en silence les rendrait invisibles.
     */
    public function testASmallNegativeArtifactIsClampedAndReported(): void
    {
        foreach (['-0,000001' => -0.000001, '-0,999999' => -0.999999, 'zero negatif' => -0.0] as $quoi => $valeur) {
            $this->assertSame(0, ResourceBoundary::wholeUnitsOfLivingStock($valeur, 'metal')->units, "« {$quoi} » did not clamp to zero.");
        }

        // Le zero negatif n'est pas une dette : il ne merite aucun signalement.
        $zeroNegatif = ResourceBoundary::wholeUnitsOfLivingStock(-0.0, 'metal');
        $this->assertFalse($zeroNegatif->diagnostics->any(), 'Negative zero is not a debt.');

        // Un vrai artefact, lui, laisse une trace.
        $artefact = ResourceBoundary::wholeUnitsOfLivingStock(-0.5, 'metal');
        $this->assertSame(
            ResourceNormalizationDiagnostics::NEGATIVE_ARTIFACT_NORMALIZED,
            array_values($artefact->diagnostics->occurrences)[0]->code
        );
    }

    /**
     * Une dette d'une unite ou plus est refusee.
     *
     * La borne d'une unite correspond a la granularite des ressources pillables. L'elargir
     * demanderait de mesurer les artefacts reels d'abord.
     */
    public function testAMateriallyNegativeAmountIsRefused(): void
    {
        foreach (['-1,0' => -1.0, '-500' => -500.0, '-1e9' => -1_000_000_000.0] as $quoi => $valeur) {
            try {
                ResourceBoundary::wholeUnitsOfLivingStock($valeur, 'metal');
                $this->fail("« {$quoi} » was accepted as a rounding artifact.");
            } catch (CorruptedResourceAmount) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Les trois valeurs non finies sont toujours refusees.
     */
    public function testTheThreeNonFiniteValuesAreAlwaysRefused(): void
    {
        foreach (['NaN' => NAN, 'INF' => INF, '-INF' => -INF] as $quoi => $valeur) {
            try {
                ResourceBoundary::wholeUnitsOfLivingStock($valeur, 'metal');
                $this->fail("« {$quoi} » was accepted as a quantity.");
            } catch (CorruptedResourceAmount) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Autour de deux puissance cinquante-trois : la conversion a lieu, et se signale.
     *
     * **Aucune de ces valeurs n'est refusee.** Un stock de cette taille est une fortune reelle ; le
     * refuser rendrait sa planete impillable.
     */
    public function testAroundTheExactIntegerLimitTheConversionHappensAndReportsItself(): void
    {
        $attendus = [
            '2^53 - 1' => [9007199254740991.0, 9007199254740991, false],
            '2^53' => [9007199254740992.0, 9007199254740992, true],
            '2^53 + 2' => [9007199254740994.0, 9007199254740994, true],
        ];

        foreach ($attendus as $quoi => [$valeur, $entier, $degrade]) {
            $normalise = ResourceBoundary::wholeUnitsOfLivingStock($valeur, 'metal');

            $this->assertSame($entier, $normalise->units, "« {$quoi} » did not convert.");
            $this->assertSame(
                $degrade,
                $normalise->diagnostics->any(),
                "« {$quoi} » " . ($degrade ? 'should' : 'should not') . ' have reported a precision loss.'
            );

            if ($degrade) {
                $this->assertSame(
                    ResourceNormalizationDiagnostics::PRECISION_DEGRADED,
                    array_values($normalise->diagnostics->occurrences)[0]->code
                );
            }
        }
    }

    /**
     * Une valeur qu'un `double` ne peut plus distinguer de sa voisine reste convertible.
     *
     * `2^53 + 1` n'est pas representable : le `double` le plus proche vaut `2^53`. La frontiere rend
     * donc l'entier reellement represente, et non une valeur inventee. L'unite est perdue par la
     * colonne, pas par le combat.
     */
    public function testAValueBetweenTwoIndistinguishableIntegersConvertsToWhatIsActuallyStored(): void
    {
        $entreDeux = 9007199254740992.0 + 1.0;

        $this->assertSame(
            9007199254740992.0,
            $entreDeux,
            'This platform distinguishes 2^53 + 1, so the premise of this test no longer holds.'
        );

        $this->assertSame(9007199254740992, ResourceBoundary::wholeUnitsOfLivingStock($entreDeux, 'metal')->units);
    }

    /**
     * La meme valeur relue deux fois donne le meme entier canonique.
     *
     * C'est ce qui empeche une **nouvelle** divergence pendant un combat : la precision perdue l'a
     * ete a l'ecriture, et elle ne s'aggrave plus.
     */
    public function testTheSameValueReadTwiceGivesTheSameCanonicalInteger(): void
    {
        foreach ([1e17, 9007199254740994.0, 4.5e18] as $valeur) {
            $premier = ResourceBoundary::wholeUnitsOfLivingStock($valeur, 'metal');
            $second = ResourceBoundary::wholeUnitsOfLivingStock($valeur, 'metal');

            $this->assertSame($premier->units, $second->units, 'Two reads of the same stock gave two different integers.');
            // Deux objets distincts, mais le meme contenu : c est l egalite de valeur qui compte,
            // pas l identite d instance.
            $this->assertEquals(
                $premier->diagnostics->occurrences,
                $second->diagnostics->occurrences,
                'Two reads of the same stock reported different diagnostics.'
            );
        }
    }

    /**
     * Au-dela du domaine entier sur, le refus est distinct de la corruption.
     *
     * La quantite est reelle ; c'est le domaine qui est trop etroit. Confondre les deux ferait
     * chercher une donnee abimee la ou il n'y a qu'une limite de plateforme.
     */
    public function testBeyondTheSafeIntegerDomainTheRefusalIsNotACorruption(): void
    {
        foreach (['1e30' => 1e30, '2^63' => 9223372036854775808.0, '2^64' => 18446744073709551616.0] as $quoi => $valeur) {
            try {
                ResourceBoundary::wholeUnitsOfLivingStock($valeur, 'metal');
                $this->fail("« {$quoi} » was converted although no integer can hold it.");
            } catch (UnrepresentableResourceAmount) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * La conversion ne rend jamais un entier negatif.
     *
     * Un debordement de transtypage se manifeste par un signe qui s'inverse. Le balayage couvre les
     * grandeurs ou cela se produirait.
     */
    public function testTheConversionNeverTurnsNegative(): void
    {
        foreach ([0.0, 1.0, 1e15, 9007199254740992.0, 4.6e18, 9.2e18] as $valeur) {
            try {
                $entier = ResourceBoundary::wholeUnitsOfLivingStock($valeur, 'metal')->units;
                $this->assertGreaterThanOrEqual(0, $entier, "The conversion of {$valeur} turned negative.");
            } catch (UnrepresentableResourceAmount) {
                // Refuse plutot que converti : c'est l'autre issue acceptable.
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Un fait gele n'accepte aucun negatif, meme minuscule.
     *
     * La correction du petit artefact appartient a la frontiere des modeles vivants. Un objet gele
     * qui porterait un negatif affirmerait un fait que personne n'a observe.
     */
    public function testAFrozenFactToleratesNoNegativeAtAll(): void
    {
        $this->assertSame(100, ResourceBoundary::wholeUnitsOfFrozenFact(100.99, 'carried')->units);

        $this->expectException(CorruptedResourceAmount::class);

        ResourceBoundary::wholeUnitsOfFrozenFact(-0.000001, 'carried');
    }

    /**
     * L'arrondi vers le haut suit les memes trois categories.
     *
     * Seul le sens change : la borne reservee doit couvrir le butin reel, pas le sous-estimer.
     */
    public function testTheCeilingConversionFollowsTheSameThreeCategories(): void
    {
        $this->assertSame(101, ResourceBoundary::ceilingUnitsOfLivingStock(100.01, 'stock')->units);
        $this->assertSame(100, ResourceBoundary::ceilingUnitsOfLivingStock(100.00, 'stock')->units);

        $artefact = ResourceBoundary::ceilingUnitsOfLivingStock(-0.5, 'stock');
        $this->assertSame(0, $artefact->units);
        $this->assertTrue($artefact->diagnostics->any(), 'The negative artifact went unreported.');

        $this->expectException(CorruptedResourceAmount::class);

        ResourceBoundary::ceilingUnitsOfLivingStock(-1.0, 'stock');
    }

    /**
     * La frontiere ne transporte rien d un appel a l autre.
     *
     * ## Le defaut que ce test ferme
     *
     * Une frontiere qui accumulerait dans un champ ferait reapparaitre un diagnostic souleve sur le
     * metal dans le resultat du cristal converti juste apres — et reutiliser la meme instance
     * transporterait tout l appel precedent. Chaque conversion rend donc son propre resultat.
     *
     * C est a l appelant d agreger, avec `mergedWith()`, et a l orchestrateur le plus exterieur de
     * journaliser une fois.
     */
    public function testTheBoundaryCarriesNothingBetweenCalls(): void
    {
        $metal = ResourceBoundary::wholeUnitsOfLivingStock(-0.5, 'metal');
        $cristal = ResourceBoundary::wholeUnitsOfLivingStock(100.0, 'crystal');

        $this->assertTrue($metal->diagnostics->any(), 'The metal artifact was not reported.');
        $this->assertFalse(
            $cristal->diagnostics->any(),
            'A diagnostic raised on metal reappeared on the crystal converted next.'
        );
    }

    /**
     * L agregation fusionne, deduplique et garde un ordre stable.
     */
    public function testDiagnosticsMergeWithoutDuplicatesAndInAStableOrder(): void
    {
        $metal = ResourceBoundary::wholeUnitsOfLivingStock(-0.5, 'metal')->diagnostics;
        $cristal = ResourceBoundary::wholeUnitsOfLivingStock(-0.25, 'crystal')->diagnostics;

        $ensemble = $metal->mergedWith($cristal);

        $this->assertCount(2, $ensemble->occurrences);
        $this->assertSame(
            ['crystal', 'metal'],
            array_map(static fn ($d): string => $d->resource, array_values($ensemble->occurrences))
        );

        // Le meme incident deux fois ne se dit qu une fois.
        $this->assertCount(1, $metal->mergedWith($metal)->occurrences);

        // Et l ordre ne depend pas du sens de la fusion.
        $this->assertSame($ensemble->occurrences, $cristal->mergedWith($metal)->occurrences);
    }

    /**
     * Les diagnostics se lisent regroupes par code puis par ressource.
     */
    public function testDiagnosticsAreReadableGroupedByCode(): void
    {
        $ensemble = ResourceBoundary::wholeUnitsOfLivingStock(-0.5, 'metal', 'target_loot')->diagnostics
            ->mergedWith(ResourceBoundary::wholeUnitsOfLivingStock(9007199254740992.0, 'crystal', 'return_cap')->diagnostics);

        // **Le compte et les provenances sont conserves.** Sans eux, six avertissements deviendraient
        // un avertissement incomplet : on saurait qu une precision s est degradee sur le metal, sans
        // savoir si c est arrive une fois ou six, ni ou.
        $this->assertSame(
            [
                ResourceNormalizationDiagnostics::NEGATIVE_ARTIFACT_NORMALIZED => [
                    'metal' => ['occurrenceCount' => 1, 'units' => [0], 'provenances' => ['target_loot']],
                ],
                ResourceNormalizationDiagnostics::PRECISION_DEGRADED => [
                    'crystal' => ['occurrenceCount' => 1, 'units' => [9007199254740992], 'provenances' => ['return_cap']],
                ],
            ],
            $ensemble->groupedByCode()
        );
    }

    /**
     * Aucun appel au journal, nulle part dans la frontiere.
     *
     * **Une garde architecturale, pas une preuve de purete.** Elle constate qu aucune dependance de
     * journalisation n a ete introduite ; le determinisme et l absence d etat residuel sont, eux,
     * verifies par les essais comportementaux ci-dessus.
     */
    public function testTheBoundaryHasNoLoggingDependency(): void
    {
        $source = (string)file_get_contents(base_path('app/Combat/Support/ResourceBoundary.php'));

        foreach (['Log::', 'DB::', 'now()', 'Date::', 'resolve(', 'app('] as $interdit) {
            $this->assertStringNotContainsString(
                $interdit,
                $source,
                "ResourceBoundary references « {$interdit} »: a calculator with side effects is no longer replayable."
            );
        }
    }
}
