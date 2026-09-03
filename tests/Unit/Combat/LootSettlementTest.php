<?php

namespace Tests\Unit\Combat;

use InvalidArgumentException;
use OGame\Combat\Allocation\ExactLootAmounts;
use OGame\Combat\Allocation\LootSettlement;
use OGame\Combat\Exceptions\CorruptedFrozenLootAmounts;
use PHPUnit\Framework\TestCase;
use stdClass;
use Throwable;

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
 *
 * `PHP_INT_MAX`, lui, n'est pas une valeur qu'une colonne `double` produirait : il eprouve la
 * **capacite du type entier** du reglement, rien d'autre. La frontiere vivante garde ses propres
 * regles — refus a partir de 2^63, precision degradee dite au-dela de 2^53 — et c'est elle qui
 * decide ce qui entre ici, pas cet essai.
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
            ExactLootAmounts::of(1_000, 500, 200),
            ExactLootAmounts::of(50_000, 40_000, 30_000),
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
            ExactLootAmounts::of(1_000, 500, 200),
            ExactLootAmounts::of(300, 40_000, 30_000),
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
            ExactLootAmounts::of(1_000, 500, 200),
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
            ExactLootAmounts::of(1_000, 500, 200),
            ExactLootAmounts::of(999_999, 999_999, 999_999),
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
            ExactLootAmounts::of(self::BEYOND_EXACT_FLOAT, self::BEYOND_EXACT_FLOAT, self::BEYOND_EXACT_FLOAT),
            ExactLootAmounts::of(self::BEYOND_EXACT_FLOAT - 1, self::BEYOND_EXACT_FLOAT, self::BEYOND_EXACT_FLOAT + 1),
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
            ExactLootAmounts::of(PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX),
            ExactLootAmounts::of(PHP_INT_MAX, 0, PHP_INT_MAX - 1),
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

        ExactLootAmounts::of(-1, 0, 0);
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
                ExactLootAmounts::of(...$montants);

                $this->fail('A negative amount was accepted at position ' . $rang . '.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Aucun scalaire convertible ne franchit la frontiere.
     *
     * ## Le defaut que cet essai ferme
     *
     * `public function __construct(int $metal, ...)` **ne refuse pas les flottants**. Aucun fichier
     * du depot ne declare `strict_types`, et cette regle se decide au **site d'appel** : en mode
     * coercitif, `1.0`, `'1'` et `true` traversent la frontiere et deviennent `1`.
     *
     * Pire, `1.5` la traverse aussi. PHP emet un avertissement de perte de precision — et rien ne
     * l'arrete. Un montant de butin serait alors arrondi en silence entre le calcul et le debit.
     *
     * La promesse « aucun flottant a la frontiere publique » etait donc fausse tant qu'elle reposait
     * sur la signature. C'est `is_int()` qui la tient, pas le typage.
     */
    public function testNoCoercibleScalarCrossesTheBoundary(): void
    {
        $refuses = [
            'flottant entier' => 1.0,
            'flottant avec perte de precision' => 1.5,
            'chaine numerique' => '1',
            'chaine vide' => '',
            'booleen vrai' => true,
            'booleen faux' => false,
            'nul' => null,
            'tableau' => [1],
            'objet' => new stdClass(),
        ];

        foreach ($refuses as $quoi => $valeur) {
            try {
                ExactLootAmounts::of($valeur, 0, 0);

                $this->fail('A ' . $quoi . ' crossed the boundary and was silently converted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Les trois composantes refusent, pas seulement la premiere.
     *
     * En verifier une seule laisserait les deux autres convertir en silence.
     */
    public function testEachOfTheThreeComponentsRefusesACoercibleScalar(): void
    {
        foreach ([[1.5, 0, 0], [0, '1', 0], [0, 0, true]] as $rang => $montants) {
            try {
                ExactLootAmounts::of(...$montants);

                $this->fail('A coercible scalar was accepted at position ' . $rang . '.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Les entiers legitimes passent sans conversion.
     *
     * **La contrepartie du refus.** Un type qui refuse tout passerait les essais ci-dessus tout
     * aussi bien.
     */
    public function testLegitimateIntegersPassUntouched(): void
    {
        $montants = ExactLootAmounts::of(0, self::BEYOND_EXACT_FLOAT, PHP_INT_MAX);

        $this->assertSame(0, $montants->metal);
        $this->assertSame(self::BEYOND_EXACT_FLOAT, $montants->crystal);
        $this->assertSame(PHP_INT_MAX, $montants->deuterium);
    }

    /**
     * La relecture persistee n'hydrate jamais par coercition.
     *
     * Une chaine numerique lue depuis une colonne, un flottant rendu par un pilote de base : les
     * accepter en les convertissant ferait dependre le butin du pilote plutot que de ce qui a ete
     * ecrit.
     */
    public function testStoredAmountsAreNeverHydratedByCoercion(): void
    {
        $refuses = [
            'chaine numerique' => ['metal' => '1', 'crystal' => 0, 'deuterium' => 0],
            'flottant' => ['metal' => 1.0, 'crystal' => 0, 'deuterium' => 0],
            'negatif' => ['metal' => -1, 'crystal' => 0, 'deuterium' => 0],
            'clef manquante' => ['metal' => 1, 'crystal' => 0],
            'clef inconnue' => ['metal' => 1, 'crystal' => 0, 'deuterium' => 0, 'antimatiere' => 5],
            'structure absente' => null,
            'chaine au lieu d une structure' => 'metal=1',
        ];

        foreach ($refuses as $quoi => $stocke) {
            try {
                ExactLootAmounts::fromStorage($stocke);

                $this->fail('A stored value of kind « ' . $quoi . ' » was hydrated by coercion.');
            } catch (CorruptedFrozenLootAmounts) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Une clef manquante se dit comme telle, et non comme un montant mal type.
     *
     * ## Pourquoi le message compte ici
     *
     * Une mutation qui supprimait le controle de presence a **survecu** : sans lui, lire une clef
     * absente rend `null`, que le controle de type refuse ensuite. L'exception partait donc quand
     * meme, et l'essai passait — pour une autre raison que celle qu'il annonce.
     *
     * Mais le diagnostic accusait alors la mauvaise cause : « le montant deuterium est un null »
     * au lieu de « la clef deuterium manque ». Cette exception existe pour etre **exploitable** ;
     * un diagnostic qui pointe ailleurs fait chercher au mauvais endroit.
     */
    public function testAMissingKeyIsReportedAsMissingAndNotAsAWrongType(): void
    {
        try {
            ExactLootAmounts::fromStorage(['metal' => 1, 'crystal' => 2]);

            $this->fail('A missing key was accepted.');
        } catch (CorruptedFrozenLootAmounts $arret) {
            $this->assertStringContainsString(
                'manque',
                $arret->defect,
                'A missing key was reported as a badly typed amount: the diagnosis points at the wrong cause.'
            );
            $this->assertStringContainsString('deuterium', $arret->defect);
        }
    }

    /**
     * Une relecture corrompue leve une exception distincte d'une faute d'appelant.
     *
     * Les deux disent « ces montants ne sont pas valides », mais elles n'appellent pas la meme
     * suite : un bogue se corrige et se redeploie, une donnee gelee corrompue se constate et se
     * traite. Les confondre priverait le cycle operationnel de cette distinction.
     */
    public function testACorruptedReadIsNotTheSameErrorAsACallerFault(): void
    {
        // **La meme valeur, deux portes, deux types d'erreur.**
        //
        // Deux versions de cet essai ont ete refusees avant celle-ci, et chacune pour une bonne
        // raison. `assertNotInstanceOf` sur deux classes connues : la reponse etait lisible dans le
        // code, donc l'assertion ne verifiait rien a l'execution. Un `catch (InvalidArgumentException)`
        // autour de la relecture : PHPStan l'a declare mort, et il l'etait.
        //
        // Ce qui se verifie reellement, c'est que la chaine « 1 » — refusee des deux cotes — ne
        // produit pas la meme erreur selon la porte empruntee.
        $parAppelant = null;

        try {
            ExactLootAmounts::of('1', 0, 0);
        } catch (Throwable $faute) {
            $parAppelant = $faute::class;
        }

        $parRelecture = null;

        try {
            ExactLootAmounts::fromStorage(['metal' => '1', 'crystal' => 0, 'deuterium' => 0]);
        } catch (Throwable $faute) {
            $parRelecture = $faute::class;
        }

        $this->assertSame(InvalidArgumentException::class, $parAppelant);
        $this->assertSame(CorruptedFrozenLootAmounts::class, $parRelecture);

        $this->assertNotSame(
            $parAppelant,
            $parRelecture,
            'A corrupted frozen read and a caller fault report the same error: operations cannot tell '
            . 'them apart, and a bug is fixed while corrupted data is handled.'
        );
    }

    /**
     * Des montants ecrits puis relus rendent exactement les memes entiers.
     */
    public function testAmountsSurviveTheRoundTrip(): void
    {
        $montants = ExactLootAmounts::of(1_000, self::BEYOND_EXACT_FLOAT, 0);

        $relus = ExactLootAmounts::fromStorage($montants->toStorage());

        $this->assertTrue($relus->equals($montants));
        $this->assertSame($montants->toStorage(), $relus->toStorage());
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
        $grand = ExactLootAmounts::of(1_000, 500, 200);
        $petit = ExactLootAmounts::of(100, 50, 20);

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
                    ExactLootAmounts::of($du, $du, $du),
                    ExactLootAmounts::of($restant, $restant, $restant),
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
        $potentiel = ExactLootAmounts::of(1_000, 500, 200);
        $restant = ExactLootAmounts::of(300, 40_000, 30_000);

        $reglement = LootSettlement::of($potentiel, $restant);

        $this->assertTrue($reglement->potential->equals($potentiel));
        $this->assertTrue($reglement->remaining->equals($restant));
    }
}
