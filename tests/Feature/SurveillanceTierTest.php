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

            $this->assertGreaterThanOrEqual(0, $delai, 'Tier ' . $palier->value . ' has a negative delay.');

            if ($precedent !== null) {
                $this->assertLessThan($precedent, $delai, 'Tier ' . $palier->value . ' is not faster than the tier below it.');
            }

            $precedent = $delai;
        }
    }

    /**
     * Les cinq durees sont exactement celles qui ont ete transmises.
     *
     * ## Pourquoi les valeurs elles-memes, et pas seulement leur forme
     *
     * La decroissance stricte et le zero final laissent encore passer 14 / 9 / 4 / 1 / 0, ou
     * n importe quelle autre suite decroissante : elles decrivent une forme, jamais la table.
     * Or ces cinq nombres sont une **decision transmise** (revue 122, decision O1, sur accord de
     * Keven), et une decision se verifie par sa valeur. Ce temoin est donc le seul endroit ou un
     * reequilibrage doit etre reecrit sciemment.
     */
    public function testTheFiveDelaysAreTheOnesWritten(): void
    {
        $attendu = [
            1 => 15 * 60,
            2 => 10 * 60,
            3 => 5 * 60,
            4 => 2 * 60,
            5 => 0,
        ];

        $reel = [];

        foreach (SurveillanceTier::cases() as $palier) {
            $reel[$palier->value] = $palier->acquisitionSeconds();
        }

        $this->assertSame($attendu, $reel, 'La table des delais s ecarte de la base transmise 15 / 10 / 5 / 2 / 0.');
    }

    /**
     * Seul le dernier palier detecte a l instant ; les autres n acquierent qu apres leur delai.
     *
     * ## Ce que les autres paliers font, et ne font pas
     *
     * Ils **n empechent pas** une traversee d etre vue : passe leur delai, ils acquierent comme
     * les autres, et une traversee assez longue est donc detectee. Ce qu un delai non nul
     * garantit est plus etroit : un passage plus bref que lui echappe. Dire « les autres
     * laissent traverser » promettrait une immunite qui n existe pas.
     *
     * Le zero du palier maximal est une decision transmise (revue 122, decision O1) : un reseau
     * porte au bout voit des l entree, et c est le seul qui le peut.
     */
    public function testOnlyTheTopTierDetectsAtOnce(): void
    {
        $this->assertSame(0, SurveillanceTier::Strength->acquisitionSeconds(), 'Le palier maximal n est plus instantane : la base transmise dit zero.');

        foreach (SurveillanceTier::cases() as $palier) {
            if ($palier === SurveillanceTier::Strength) {
                continue;
            }

            $this->assertGreaterThan(
                0,
                $palier->acquisitionSeconds(),
                'Le palier ' . $palier->value . ' detecte a l instant : un passage bref ne lui echapperait plus.'
            );
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
