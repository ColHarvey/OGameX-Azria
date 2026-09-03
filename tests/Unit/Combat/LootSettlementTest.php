<?php

namespace Tests\Unit\Combat;

use OGame\Combat\Allocation\LootSettlement;
use OGame\Combat\Support\LootEnvelope;
use PHPUnit\Framework\TestCase;

/**
 * Le reglement du butin par minimum, composante par composante.
 *
 * ## La regle et sa raison
 *
 *     butin applique = min(butin potentiel gele, ressources reellement restantes)
 *
 * Chacune des deux moities ferme un abus distinct : le potentiel empeche l'attaquant de prendre la
 * production arrivee apres l'ouverture, le restant laisse le defenseur sauver ce qu'il a eu le temps
 * de depenser.
 *
 * C'est une **decision de jeu** — l'alternative etait d'immobiliser la part pillable des l'ouverture,
 * et elle a ete ecartee. Ces essais sont ce qui rend la decision verifiable.
 */
class LootSettlementTest extends TestCase
{
    /**
     * Une cible intacte paie exactement ce qui etait dû.
     */
    public function testAnUntouchedTargetPaysExactlyWhatWasDue(): void
    {
        $reglement = LootSettlement::of(
            new LootEnvelope(1_000, 500, 200),
            new LootEnvelope(50_000, 40_000, 30_000),
        );

        $this->assertSame(1_000.0, $reglement->applied->metal);
        $this->assertSame(500.0, $reglement->applied->crystal);
        $this->assertSame(200.0, $reglement->applied->deuterium);

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
            new LootEnvelope(1_000, 500, 200),
            new LootEnvelope(300, 40_000, 30_000),
        );

        $this->assertSame(300.0, $reglement->applied->metal, 'The metal was not capped by what remained.');
        $this->assertSame(500.0, $reglement->applied->crystal, 'The crystal was reduced although it was there.');
        $this->assertSame(200.0, $reglement->applied->deuterium);

        $this->assertSame(700.0, $reglement->shortfall()->metal);
        $this->assertSame(0.0, $reglement->shortfall()->crystal);
        $this->assertFalse($reglement->wasPaidInFull());
    }

    /**
     * Une cible vide ne paie rien, et ne doit rien de plus.
     *
     * Le manque est constate pour le rapport ; il ne se prend pas ailleurs.
     */
    public function testAnEmptyTargetPaysNothing(): void
    {
        $reglement = LootSettlement::of(
            new LootEnvelope(1_000, 500, 200),
            LootEnvelope::nothing(),
        );

        $this->assertTrue($reglement->applied->isNothing());
        $this->assertSame(1_000.0, $reglement->shortfall()->metal);
        $this->assertSame(500.0, $reglement->shortfall()->crystal);
        $this->assertSame(200.0, $reglement->shortfall()->deuterium);
    }

    /**
     * La production arrivee apres l'ouverture n'augmente jamais le butin.
     *
     * **L'autre moitie de la regle.** Un combat de deux heures produit des ressources ; elles
     * appartiennent au defenseur, et le potentiel a ete gele sans elles.
     */
    public function testProductionAfterTheOpeningNeverRaisesTheLoot(): void
    {
        $reglement = LootSettlement::of(
            new LootEnvelope(1_000, 500, 200),
            new LootEnvelope(999_999, 999_999, 999_999),
        );

        $this->assertSame(1_000.0, $reglement->applied->metal, 'The attacker took production that arrived after the opening.');
        $this->assertSame(500.0, $reglement->applied->crystal);
        $this->assertSame(200.0, $reglement->applied->deuterium);

        $this->assertTrue($reglement->wasPaidInFull());
    }

    /**
     * L'invariant, sur des valeurs quelconques : le pris est entre zero et le dû.
     *
     * Une propriete plutot qu'un exemple : elle tient pour toutes les combinaisons essayees, et
     * c'est ce qu'un rapport, une deduction et une cargaison de retour supposent tous.
     */
    public function testTheAppliedAmountAlwaysSitsBetweenZeroAndWhatWasDue(): void
    {
        $montants = [0, 1, 7, 250, 9_999, 1_000_000];

        foreach ($montants as $du) {
            foreach ($montants as $restant) {
                $reglement = LootSettlement::of(
                    new LootEnvelope($du, $du, $du),
                    new LootEnvelope($restant, $restant, $restant),
                );

                foreach (['metal', 'crystal', 'deuterium'] as $composante) {
                    $pris = $reglement->applied->{$composante};

                    $this->assertGreaterThanOrEqual(0.0, $pris);
                    $this->assertLessThanOrEqual((float)$du, $pris, 'The applied loot exceeded the frozen potential.');
                    $this->assertLessThanOrEqual((float)$restant, $pris, 'The applied loot exceeded what the target still held.');
                }
            }
        }
    }

    /**
     * Le reglement ne recalcule aucune des deux bornes.
     *
     * Elles sont rendues telles qu'elles ont ete donnees : le potentiel vient des faits geles, le
     * restant d'une lecture sous verrou. Les recalculer ici rendrait le reglement dependant du
     * moment ou il s'execute.
     */
    public function testNeitherBoundIsRecomputed(): void
    {
        $potentiel = new LootEnvelope(1_000, 500, 200);
        $restant = new LootEnvelope(300, 40_000, 30_000);

        $reglement = LootSettlement::of($potentiel, $restant);

        $this->assertTrue($reglement->potential->equals($potentiel));
        $this->assertTrue($reglement->remaining->equals($restant));
    }
}
