<?php

namespace Tests\Unit\Combat;

use InvalidArgumentException;
use OGame\Combat\Allocation\ExactLootAmounts;
use OGame\Combat\Allocation\LootSettlement;
use PHPUnit\Framework\TestCase;

/**
 * Le reglement du butin par minimum, en entiers exacts, composante par composante.
 *
 * ## La regle et sa raison
 *
 *     butin applique = min(butin potentiel gele, ressources reellement restantes)
 *
 * Chacune des deux moities ferme un abus distinct : le potentiel empeche l'attaquant de prendre la
 * production arrivee apres l'ouverture, le restant laisse le defenseur sauver ce qu'il a eu le temps
 * de depenser. C'est une **decision de jeu** — l'alternative etait d'immobiliser la part pillable des
 * l'ouverture, et elle a ete ecartee.
 *
 * ## Pourquoi des entiers, et pourquoi ces bornes-la
 *
 * La premiere version de ces essais affirmait `assertSame(1_000.0, ...)` : le reglement portait des
 * flottants. C'etait une regression silencieuse au dernier maillon d'un pipeline construit pour
 * rester exact.
 *
 * Les bornes eprouvees ici ne sont pas decoratives :
 *
 *     2^53      le premier entier qu'un flottant ne distingue plus de son voisin
 *     2^63 - 1  le plus grand entier que la plateforme porte
 *
 * Un stock de metal peut atteindre le premier sur un serveur ancien. Si le reglement passait par un
 * flottant, le butin annonce ne serait pas celui debite, et l'ecart n'apparaitrait nulle part.
 */
class LootSettlementTest extends TestCase
{
    /**
     * Le premier entier que deux puissance cinquante-trois ne separe plus de son voisin.
     */
    private const int BEYOND_EXACT_FLOAT = 9_007_199_254_740_993;

    /**
     * Une cible intacte paie exactement ce qui etait dû.
     */
    public function testAnUntouchedTargetPaysExactlyWhatWasDue(): void
    {
        $reglement = LootSettlement::of(
            new ExactLootAmounts(1_000, 500, 200),
            new ExactLootAmounts(50_000, 40_000, 30_000),
        );

        $this->assertSame(1_000, $reglement->applied->metal);
        $this->assertSame(500, $reglement->applied->crystal);
        $this->assertSame(200, $reglement->applied->deuterium);

        $this->assertTrue($reglement->wasPaidInFull());
        $this->assertTrue($reglement->shortfall()->isNothing());
    }

    /**
     * Une seule composante manquante ne touche pas les autres.
     *
     * **C'est le cas qui interdit de raisonner en total.** Un defenseur peut avoir vide son metal en
     * gardant son deuterium : un minimum sur la somme autoriserait a prendre le deuterium en echange
     * du metal manquant.
     */
    public function testAShortfallOnOneResourceLeavesTheOthersUntouched(): void
    {
        $reglement = LootSettlement::of(
            new ExactLootAmounts(1_000, 500, 200),
            new ExactLootAmounts(300, 40_000, 30_000),
        );

        $this->assertSame(300, $reglement->applied->metal, 'The metal was not capped by what remained.');
        $this->assertSame(500, $reglement->applied->crystal, 'The crystal was reduced although it was there.');
        $this->assertSame(200, $reglement->applied->deuterium);

        $this->assertSame(700, $reglement->shortfall()->metal);
        $this->assertSame(0, $reglement->shortfall()->crystal);
        $this->assertFalse($reglement->wasPaidInFull());
    }

    /**
     * Une cible vide ne paie rien, et ne doit rien de plus.
     */
    public function testAnEmptyTargetPaysNothing(): void
    {
        $reglement = LootSettlement::of(
            new ExactLootAmounts(1_000, 500, 200),
            ExactLootAmounts::nothing(),
        );

        $this->assertTrue($reglement->applied->isNothing());
        $this->assertSame(1_000, $reglement->shortfall()->metal);
        $this->assertSame(500, $reglement->shortfall()->crystal);
        $this->assertSame(200, $reglement->shortfall()->deuterium);
    }

    /**
     * La production arrivee apres l'ouverture n'augmente jamais le butin.
     */
    public function testProductionAfterTheOpeningNeverRaisesTheLoot(): void
    {
        $reglement = LootSettlement::of(
            new ExactLootAmounts(1_000, 500, 200),
            new ExactLootAmounts(999_999, 999_999, 999_999),
        );

        $this->assertSame(1_000, $reglement->applied->metal, 'The attacker took production that arrived after the opening.');
        $this->assertSame(500, $reglement->applied->crystal);
        $this->assertSame(200, $reglement->applied->deuterium);

        $this->assertTrue($reglement->wasPaidInFull());
    }

    /**
     * Au-dela de deux puissance cinquante-trois, chaque unite compte encore.
     *
     * **C'est l'essai que la version flottante ne pouvait pas passer.** Un flottant ne distingue plus
     * cet entier de son voisin : le butin annonce n'aurait pas ete celui debite, et rien ne l'aurait
     * dit.
     */
    public function testAmountsBeyondExactFloatPrecisionStayExact(): void
    {
        $reglement = LootSettlement::of(
            new ExactLootAmounts(self::BEYOND_EXACT_FLOAT, self::BEYOND_EXACT_FLOAT, self::BEYOND_EXACT_FLOAT),
            new ExactLootAmounts(self::BEYOND_EXACT_FLOAT - 1, self::BEYOND_EXACT_FLOAT, self::BEYOND_EXACT_FLOAT + 1),
        );

        $this->assertSame(self::BEYOND_EXACT_FLOAT - 1, $reglement->applied->metal);
        $this->assertSame(self::BEYOND_EXACT_FLOAT, $reglement->applied->crystal);
        $this->assertSame(self::BEYOND_EXACT_FLOAT, $reglement->applied->deuterium);

        // Une seule unite de manque, et elle se voit.
        $this->assertSame(1, $reglement->shortfall()->metal);
        $this->assertFalse($reglement->wasPaidInFull());
    }

    /**
     * Le plus grand entier de la plateforme se regle sans deborder.
     */
    public function testTheLargestPlatformIntegerSettlesWithoutOverflow(): void
    {
        $reglement = LootSettlement::of(
            new ExactLootAmounts(PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX),
            new ExactLootAmounts(PHP_INT_MAX, 0, PHP_INT_MAX - 1),
        );

        $this->assertSame(PHP_INT_MAX, $reglement->applied->metal);
        $this->assertSame(0, $reglement->applied->crystal);
        $this->assertSame(PHP_INT_MAX - 1, $reglement->applied->deuterium);

        $this->assertSame(0, $reglement->shortfall()->metal);
        $this->assertSame(PHP_INT_MAX, $reglement->shortfall()->crystal);
        $this->assertSame(1, $reglement->shortfall()->deuterium);
    }

    /**
     * Un montant negatif est refuse a la frontiere.
     *
     * Un butin negatif rendrait des ressources au defenseur au lieu de lui en prendre.
     */
    public function testANegativeAmountIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ExactLootAmounts(-1, 0, 0);
    }

    /**
     * Chacune des trois composantes refuse le negatif.
     *
     * En verifier une seule laisserait les deux autres hors de la preuve.
     */
    public function testEachOfTheThreeComponentsRefusesANegative(): void
    {
        foreach ([[-1, 0, 0], [0, -1, 0], [0, 0, -1]] as $rang => $montants) {
            try {
                new ExactLootAmounts(...$montants);

                $this->fail('A negative amount was accepted at position ' . $rang . '.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Un manque ne descend jamais sous zero, meme demande a l'envers.
     *
     * ## Pourquoi cet essai vise la methode et non le reglement
     *
     * Une mutation qui retirait le plancher a **survecu** a tous les essais ci-dessus : par
     * construction, le reglement ne demande jamais le manque d'un montant plus grand que sa cible,
     * donc la soustraction y est toujours positive. La valeur juste et la valeur fausse
     * coincidaient.
     *
     * Mais `shortfallTowards()` est publique. Son contrat doit tenir pour tout appelant, pas
     * seulement pour celui d'aujourd'hui — et sans le plancher, un montant negatif ferait lever le
     * constructeur au lieu de rendre zero.
     */
    public function testAShortfallNeverGoesBelowZeroEvenAskedBackwards(): void
    {
        $grand = new ExactLootAmounts(1_000, 500, 200);
        $petit = new ExactLootAmounts(100, 50, 20);

        $manque = $grand->shortfallTowards($petit);

        $this->assertTrue($manque->isNothing(), 'A shortfall towards a smaller target should be nothing, not negative.');
        $this->assertSame(0, $manque->metal);
        $this->assertSame(0, $manque->crystal);
        $this->assertSame(0, $manque->deuterium);
    }

    /**
     * L'invariant, sur des valeurs quelconques : le pris est entre zero et le dû.
     *
     * Une propriete plutot qu'un exemple : elle tient pour toutes les combinaisons essayees, et
     * c'est ce qu'un rapport, une deduction et une cargaison de retour supposent tous.
     */
    public function testTheAppliedAmountAlwaysSitsBetweenZeroAndWhatWasDue(): void
    {
        $montants = [0, 1, 7, 250, 9_999, 1_000_000, self::BEYOND_EXACT_FLOAT, PHP_INT_MAX];

        foreach ($montants as $du) {
            foreach ($montants as $restant) {
                $reglement = LootSettlement::of(
                    new ExactLootAmounts($du, $du, $du),
                    new ExactLootAmounts($restant, $restant, $restant),
                );

                foreach (['metal', 'crystal', 'deuterium'] as $composante) {
                    $pris = $reglement->applied->{$composante};

                    $this->assertGreaterThanOrEqual(0, $pris);
                    $this->assertLessThanOrEqual($du, $pris, 'The applied loot exceeded the frozen potential.');
                    $this->assertLessThanOrEqual($restant, $pris, 'The applied loot exceeded what the target still held.');
                }
            }
        }
    }

    /**
     * Le reglement ne recalcule aucune des deux bornes.
     *
     * Le potentiel vient des faits geles, le restant d'une lecture sous verrou. Les recalculer ici
     * rendrait le reglement dependant du moment ou il s'execute.
     */
    public function testNeitherBoundIsRecomputed(): void
    {
        $potentiel = new ExactLootAmounts(1_000, 500, 200);
        $restant = new ExactLootAmounts(300, 40_000, 30_000);

        $reglement = LootSettlement::of($potentiel, $restant);

        $this->assertTrue($reglement->potential->equals($potentiel));
        $this->assertTrue($reglement->remaining->equals($restant));
    }
}
