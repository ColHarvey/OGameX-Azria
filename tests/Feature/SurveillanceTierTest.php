<?php

namespace Tests\Feature;

use OGame\Patrol\Enums\SurveillanceFact;
use OGame\Patrol\Enums\SurveillanceTier;
use Tests\TestCase;

/**
 * Les cinq paliers de surveillance : ce que chacun revele, et lequel gouverne.
 *
 * ## Ce que ce temoin refuse de supposer
 *
 * Trois regles se ressemblent et se confondent facilement :
 *
 *  - **l empilement** — un palier revele aussi tout ce que revelent les paliers inferieurs ;
 *  - **le meilleur, jamais la somme** — deux reseaux de niveau 2 ne valent pas un niveau 4 ;
 *  - **aucun detecteur, aucun renseignement** — l absence de reseau n est pas le palier le plus bas.
 *
 * Un essai qui verifierait seulement « le niveau 5 revele tout » passerait avec les trois regles
 * cassees.
 */
class SurveillanceTierTest extends TestCase
{
    /**
     * Chaque palier revele exactement les faits jusqu au sien, et pas un de plus.
     */
    public function testEachTierRevealsExactlyTheFactsUpToItsOwn(): void
    {
        $attendu = [
            1 => ['position'],
            2 => ['position', 'owner'],
            3 => ['position', 'owner', 'heading'],
            4 => ['position', 'owner', 'heading', 'size_estimate'],
            5 => ['position', 'owner', 'heading', 'size_estimate', 'exact_strength'],
        ];

        foreach (SurveillanceTier::cases() as $palier) {
            $revele = array_map(
                static fn (SurveillanceFact $fait): string => $fait->value,
                $palier->revealedFacts()
            );

            // L ensemble complet, pas la presence : « il revele la position » resterait vrai avec un
            // fait de trop, et c est exactement la faute qu on cherche.
            $this->assertSame($attendu[$palier->value], $revele, 'Tier ' . $palier->value . ' reveals the wrong set.');
        }
    }

    /**
     * Les trois faits qui ne voyagent jamais n ont aucun palier.
     */
    public function testCompositionFuelAndCargoAreNotFactsAtAll(): void
    {
        $noms = array_map(static fn (SurveillanceFact $fait): string => $fait->value, SurveillanceFact::cases());

        foreach (['composition', 'fuel', 'fuel_reserve', 'cargo'] as $interdit) {
            $this->assertNotContains(
                $interdit,
                $noms,
                'A fact named ' . $interdit . ' exists: surveillance would become espionage without a probe.'
            );
        }

        // Et la liste est bien fermee a cinq : un sixieme fait ajoute sans decision se verrait ici.
        $this->assertCount(5, SurveillanceFact::cases());
    }

    /**
     * Le delai d acquisition decroit strictement avec le niveau.
     *
     * La regle du jeu est « un reseau developpe repere plus vite » ; comparer les valeurs deux a
     * deux la porte, la relire une par une ne ferait que recopier la table.
     */
    public function testTheAcquisitionDelayStrictlyDecreasesWithTheTier(): void
    {
        $precedent = null;

        foreach (SurveillanceTier::cases() as $palier) {
            $delai = $palier->acquisitionSeconds();

            $this->assertGreaterThan(0, $delai, 'Tier ' . $palier->value . ' detects instantly: a patrol crossing the system would be seen.');

            if ($precedent !== null) {
                $this->assertLessThan($precedent, $delai, 'Tier ' . $palier->value . ' is not faster than the tier below it.');
            }

            $precedent = $delai;
        }
    }

    /**
     * C est le meilleur reseau qui gouverne, jamais la somme de plusieurs.
     */
    public function testTheBestNetworkGovernsAndNeverTheSum(): void
    {
        // Deux reseaux de niveau 2 ne valent pas un niveau 4 : ils valent un niveau 2.
        $this->assertSame(SurveillanceTier::Identity, SurveillanceTier::bestOf([2, 2]));

        // L ordre de la liste ne change rien.
        $this->assertSame(SurveillanceTier::Estimate, SurveillanceTier::bestOf([1, 4, 2]));
        $this->assertSame(SurveillanceTier::Estimate, SurveillanceTier::bestOf([4, 2, 1]));
    }

    /**
     * Aucun detecteur, aucun renseignement — et zero n est pas le palier le plus bas.
     */
    public function testNoDetectorMeansNoIntelligenceAtAll(): void
    {
        $this->assertNull(SurveillanceTier::bestOf([]), 'A player with no network gets a tier: the leak is open.');
        $this->assertNull(SurveillanceTier::bestOf([0]), 'A network at level zero grants a tier: an unbuilt building would see.');
        $this->assertNull(SurveillanceTier::bestOf([0, 0, 0]), 'Several unbuilt networks add up to a tier.');
        $this->assertNull(SurveillanceTier::fromLevel(0));
        $this->assertNull(SurveillanceTier::fromLevel(-3));
    }

    /**
     * Construire au-dela du dernier palier garde le dernier, et ne fait pas disparaitre le contact.
     */
    public function testBuildingBeyondTheLastTierKeepsTheLastOne(): void
    {
        $this->assertSame(SurveillanceTier::Strength, SurveillanceTier::fromLevel(5));
        $this->assertSame(SurveillanceTier::Strength, SurveillanceTier::fromLevel(6));
        $this->assertSame(SurveillanceTier::Strength, SurveillanceTier::fromLevel(40));
        $this->assertSame(SurveillanceTier::Strength, SurveillanceTier::bestOf([12]));
    }
}
