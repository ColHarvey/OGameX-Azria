<?php

namespace Tests\Unit;

use OGame\Exceptions\UnrepresentableWholeUnits;
use OGame\Support\WholeUnits;
use Tests\UnitTestCase;

/**
 * Le domaine des entiers que cette plateforme porte reellement.
 *
 * ## Le trou que « fini, positif, entier » laissait
 *
 * Trois controles ne suffisaient pas. `1e30` est fini, positif, et egal a son plancher : il les
 * traversait tous les trois, puis le transtypage le rendait negatif ou nul selon la plateforme. Un
 * credit devenait un debit, et une condition « superieur a zero » le laissait tomber en silence.
 *
 * La borne ne se compare pas a `PHP_INT_MAX` : celui-ci n'est pas representable exactement en
 * flottant, et le comparer a un flottant compare en realite a deux puissance soixante-trois. Le
 * refus porte donc sur cette puissance-la, exactement representable, puis la conversion verifie son
 * propre resultat par un aller-retour.
 */
class WholeUnitsTest extends UnitTestCase
{
    /**
     * Cette plateforme a bien des entiers de soixante-quatre bits.
     *
     * Sans ce temoin, les bornes suivantes ne voudraient rien dire : sur une plateforme 32 bits,
     * elles seraient toutes hors domaine et les essais passeraient pour de mauvaises raisons.
     */
    public function testThePlatformCarriesSixtyFourBitIntegers(): void
    {
        $this->assertSame(8, PHP_INT_SIZE, 'This platform does not carry 64-bit integers: the bounds below prove nothing.');
    }

    /**
     * Le plus grand flottant strictement sous deux puissance soixante-trois passe, et revient exact.
     */
    public function testTheLargestFloatBelowTheLimitIsAccepted(): void
    {
        // Le voisin inferieur de 2^63 en double : 2^63 - 2^10. Cet ecart n'est pas arbitraire —
        // c'est le pas de la representation a cette echelle, donc la premiere valeur distincte
        // sous la borne.
        $dernier = WholeUnits::INTEGER_DOMAIN_LIMIT - 1024.0;

        $this->assertNotSame(WholeUnits::INTEGER_DOMAIN_LIMIT, $dernier, 'The step is too small to be representable here: the neighbour equals the limit.');
        $this->assertTrue(WholeUnits::representable($dernier));

        $entier = WholeUnits::of($dernier, 'metal');
        $this->assertSame($dernier, (float)$entier, 'The conversion did not come back to the value it was given.');
    }

    /**
     * Deux puissance soixante-trois elle-meme est refusee.
     */
    public function testTheLimitItselfIsRefused(): void
    {
        $this->assertFalse(WholeUnits::representable(WholeUnits::INTEGER_DOMAIN_LIMIT));

        $this->expectException(UnrepresentableWholeUnits::class);
        $this->expectExceptionMessage('hors du domaine des entiers signes');

        WholeUnits::of(WholeUnits::INTEGER_DOMAIN_LIMIT, 'metal');
    }

    /**
     * Le voisin immediatement superieur est refuse aussi.
     *
     * Le pas de la representation vaut 2048 a cette echelle : c'est la premiere valeur distincte
     * au-dessus de la borne, et non son double. Un essai qui doublerait la borne prouverait quelque
     * chose de bien plus large, et laisserait le voisin — le cas qui compte — hors de portee.
     */
    public function testTheNextRepresentableValueAboveTheLimitIsRefused(): void
    {
        $suivant = WholeUnits::INTEGER_DOMAIN_LIMIT + 2048.0;

        $this->assertNotSame(WholeUnits::INTEGER_DOMAIN_LIMIT, $suivant, 'The chosen step is smaller than the representation allows: this is not the next value.');
        $this->assertFalse(WholeUnits::representable($suivant));

        $this->expectException(UnrepresentableWholeUnits::class);
        WholeUnits::of($suivant, 'metal');
    }

    /**
     * La valeur qui rendait un credit negatif est refusee.
     *
     * C'est le cas concret : `1e30` est fini, positif, egal a son plancher, et le transtyper donnait
     * autre chose que lui-meme.
     */
    public function testAFiniteValueFarBeyondTheDomainIsRefused(): void
    {
        $this->assertFalse(WholeUnits::representable(1e30));

        $this->expectException(UnrepresentableWholeUnits::class);
        WholeUnits::of(1e30, 'metal');
    }

    /**
     * Le plus petit entier de la plateforme passe : la borne basse est **incluse**.
     *
     * Un entier signe de soixante-quatre bits va de `-2^63` a `2^63 - 1`. Les deux bornes ne sont
     * donc pas symetriques, et refuser `-2^63` rejetterait `PHP_INT_MIN` — une valeur que la
     * plateforme porte parfaitement, dont l'aller-retour est exact. Cette classe juge le domaine,
     * pas le signe.
     */
    public function testTheSmallestIntegerOfThePlatformIsAccepted(): void
    {
        $plancher = (float)PHP_INT_MIN;

        $this->assertSame(-WholeUnits::INTEGER_DOMAIN_LIMIT, $plancher, 'PHP_INT_MIN is not exactly -2^63 here: the bound would mean something else.');
        $this->assertTrue(WholeUnits::representable($plancher));
        $this->assertSame(PHP_INT_MIN, WholeUnits::of($plancher, 'metal'));
    }

    /**
     * Au-dela de la borne basse, le refus revient.
     */
    public function testBelowTheSmallestIntegerTheDomainRefusesAgain(): void
    {
        $trop = -WholeUnits::INTEGER_DOMAIN_LIMIT - 2048.0;

        $this->assertNotSame(-WholeUnits::INTEGER_DOMAIN_LIMIT, $trop, 'The chosen step is smaller than the representation allows.');
        $this->assertFalse(WholeUnits::representable($trop));

        $this->expectException(UnrepresentableWholeUnits::class);
        WholeUnits::of($trop, 'metal');
    }

    /**
     * Ce qui n'est pas un nombre est refuse : les deux infinis et `NaN`.
     */
    public function testWhatIsNotANumberIsRefused(): void
    {
        foreach (['NaN' => NAN, 'INF' => INF, '-INF' => -INF] as $nom => $valeur) {
            $this->assertFalse(WholeUnits::representable($valeur), $nom . ' was accepted as representable.');

            try {
                WholeUnits::of($valeur, 'metal');
                $this->fail($nom . ' was converted to an integer.');
            } catch (UnrepresentableWholeUnits $refus) {
                $this->assertStringContainsString('n est pas un nombre', $refus->getMessage());
            }
        }
    }

    /**
     * Une fraction est refusee : cette primitive garde le domaine, elle n'arrondit pas.
     *
     * L'arrondi est une decision metier — vers le haut pour une reservation, vers le bas pour un
     * plancher. La prendre ici la rendrait invisible a l'appelant.
     */
    public function testAFractionIsRefusedRatherThanRounded(): void
    {
        $this->assertFalse(WholeUnits::representable(10.5));

        $this->expectException(UnrepresentableWholeUnits::class);
        $this->expectExceptionMessage('ne lui correspond plus');

        WholeUnits::of(10.5, 'metal');
    }

    /**
     * Un montant ordinaire traverse sans rien changer.
     */
    public function testAnOrdinaryAmountGoesThrough(): void
    {
        $this->assertTrue(WholeUnits::representable(0.0));
        $this->assertSame(0, WholeUnits::of(0.0, 'metal'));
        $this->assertSame(1_234, WholeUnits::of(1_234.0, 'metal'));
        $this->assertSame(-1_234, WholeUnits::of(-1_234.0, 'metal'), 'The primitive judges the domain, not the sign.');
    }
}
