<?php

namespace Tests\Unit\Combat;

use OGame\Combat\Exceptions\UnrepresentableResourceAmount;
use OGame\Combat\Support\AttackerCargoShare;
use OGame\Combat\Support\LootBound;
use OGame\Combat\Support\LootEnvelope;
use OGame\Combat\Support\LootPolicy;
use Tests\UnitTestCase;

/**
 * La borne reservee : ce qu'un combat immobilise sans rien reveler de son issue.
 *
 * Le calcul est pur — ressources et politique entrent, une borne sort — donc entierement
 * verifiable ici, sans base ni moteur de bataille.
 */
class LootBoundTest extends UnitTestCase
{
    /**
     * Une cible active : la moitie de chaque ressource, separement.
     */
    public function testAnActiveTargetIsBoundedAtHalfOfEachResource(): void
    {
        $borne = LootBound::upperBoundFor(
            new LootEnvelope(1_000.0, 501.0, 3.0),
            $this->policy(false, 0, 100_000)
        );

        $this->assertSame(500.0, $borne->metal);
        $this->assertSame(250.0, $borne->crystal);
        $this->assertSame(1.0, $borne->deuterium);
    }

    /**
     * Une cible inactive attaquee par des Decouvreurs seuls : trois quarts.
     */
    public function testAnInactiveTargetAttackedOnlyByDiscoverersIsBoundedAtThreeQuarters(): void
    {
        $borne = LootBound::upperBoundFor(
            new LootEnvelope(1_000.0, 999.0, 0.0),
            $this->policy(true, 100_000, 100_000)
        );

        $this->assertSame(750.0, $borne->metal);
        $this->assertSame(749.0, $borne->crystal);
        $this->assertSame(0.0, $borne->deuterium);
    }

    /**
     * Le fret partage en deux : soixante-deux et demi pour cent, arrondi vers le bas.
     */
    public function testTheRateFollowsTheShareOfDiscovererCargo(): void
    {
        $borne = LootBound::upperBoundFor(
            new LootEnvelope(1_000.0, 0.0, 0.0),
            $this->policy(true, 50_000, 100_000)
        );

        $this->assertSame(625.0, $borne->metal, 'Half the cargo being Discoverer must give 62,5 %.');
    }

    /**
     * Une ressource fractionnaire est arrondie vers le haut **avant** la multiplication.
     *
     * Le cas qui impose cette regle : 1,9 de metal a 75 %. Le butin reel peut valoir une unite ;
     * une borne prise sur la partie entiere en donnerait zero, et le reglage n'aurait rien a
     * prelever.
     */
    public function testAFractionalStockIsRoundedUpBeforeTheRateIsApplied(): void
    {
        $politique = $this->policy(true, 100_000, 100_000);

        $this->assertSame(1.0, LootBound::upperBoundFor(new LootEnvelope(1.9, 0.0, 0.0), $politique)->metal);
        $this->assertSame(3.0, LootBound::upperBoundFor(new LootEnvelope(4.4, 0.0, 0.0), $politique)->metal);
        $this->assertSame(0.0, LootBound::upperBoundFor(new LootEnvelope(0.0, 0.0, 0.0), $politique)->metal);

        // Arrondir la ressource vers le haut ne cree pas d'unite pour autant : trois quarts d'une
        // seule unite valent toujours zero une fois le taux applique.
        $this->assertSame(0.0, LootBound::upperBoundFor(new LootEnvelope(0.1, 0.0, 0.0), $politique)->metal);
    }

    /**
     * La borne couvre toujours le butin que le moteur calculerait.
     *
     * **C'est la propriete qui rend la reservation sure.** Le moteur multiplie la ressource par un
     * pourcentage flottant, puis n'attribue que des unites entieres : la borne doit couvrir ce
     * resultat sur chaque ressource separement, y compris sur des stocks fractionnaires.
     *
     * Le balayage traverse expres les valeurs qui tombent juste sous une unite entiere, la ou un
     * arrondi vers le bas trahirait.
     */
    public function testTheBoundAlwaysCoversWhatTheEngineWouldCompute(): void
    {
        $verifies = 0;

        foreach ([[false, 0, 100_000, 50], [true, 100_000, 100_000, 75]] as [$inactive, $decouvreur, $total, $pourcentage]) {
            $politique = $this->policy($inactive, $decouvreur, $total);

            for ($entier = 0; $entier <= 200; $entier++) {
                foreach ([0.0, 0.1, 0.25, 0.4, 0.5, 0.6, 0.75, 0.9, 0.99, 0.999] as $fraction) {
                    $stock = $entier + $fraction;

                    // Exactement ce que fait `LootService::calculateLootCapacityConstrained()`.
                    $butinMoteur = (int)floor(max(0, $stock) * ($pourcentage / 100));
                    $borne = LootBound::upperBoundFor(new LootEnvelope($stock, $stock, $stock), $politique);

                    $this->assertGreaterThanOrEqual(
                        $butinMoteur,
                        (int)$borne->metal,
                        "A stock of {$stock} at {$pourcentage} % is under-reserved: the settlement would find nothing to take."
                    );

                    // Et jamais plus large que d'une unite : une borne trop genereuse immobilise
                    // sans raison les ressources d'un joueur qui n'a rien fait.
                    $this->assertLessThanOrEqual(
                        $butinMoteur + 1,
                        (int)$borne->metal,
                        "A stock of {$stock} at {$pourcentage} % is over-reserved by more than one unit."
                    );

                    $verifies++;
                }
            }
        }

        $this->assertSame(4_020, $verifies, 'The sweep no longer covers what it claims to cover.');
    }

    /**
     * L'ordre dans lequel les participants ont ete additionnes ne change pas la borne.
     *
     * C'etait le defaut d'origine : le moteur lisait la classe du premier attaquant de la
     * collection, et une sonde en tete de liste faisait passer tout le butin de 50 % a 75 %.
     */
    public function testThePlayerOrderCannotChangeTheBound(): void
    {
        $participants = [[3_000, true], [17_500, false], [500, true], [29_000, false]];
        $inverse = array_reverse($participants);

        $sommer = static function (array $liste): AttackerCargoShare {
            $part = AttackerCargoShare::none();

            foreach ($liste as [$fret, $estDecouvreur]) {
                $part = $part->plus($fret, $estDecouvreur);
            }

            return $part;
        };

        $stock = new LootEnvelope(1_234_567.0, 89_012.0, 3_456.0);

        $premiere = LootBound::upperBoundFor($stock, new LootPolicy(true, $sommer($participants)));
        $seconde = LootBound::upperBoundFor($stock, new LootPolicy(true, $sommer($inverse)));

        $this->assertTrue($premiere->equals($seconde), 'Permuting the attackers changed the reserved bound.');
    }

    /**
     * Un pirate ne beneficie d'aucun bonus, meme contre une cible inactive.
     */
    public function testAServerDrivenAttackerGetsNoBonus(): void
    {
        $borne = LootBound::upperBoundFor(new LootEnvelope(1_000.0, 0.0, 0.0), LootPolicy::forNpcAttacker(true));

        $this->assertSame(500.0, $borne->metal, 'A pirate must not inherit the Discoverer bonus.');
    }

    /**
     * Une cible vide donne une borne nulle, et surtout aucune division par zero.
     */
    public function testAnEmptyTargetYieldsNothing(): void
    {
        $borne = LootBound::upperBoundFor(LootEnvelope::nothing(), $this->policy(true, 0, 0));

        $this->assertTrue($borne->isNothing(), 'An empty target must reserve nothing at all.');
    }

    /**
     * Un stock hors de portee d'un entier s'arrete, plutot que de produire une borne absurde.
     *
     * **Et le refus dit lequel des deux problemes se pose.** Un stock de dix puissance trente est
     * une quantite reelle qu'aucun entier de la plateforme ne porte : ce n'est pas une donnee
     * abimee, c'est un domaine trop etroit. Les confondre ferait chercher une corruption la ou il
     * n'y a qu'une limite.
     */
    public function testAStockBeyondIntegerRangeIsRefused(): void
    {
        $this->expectException(UnrepresentableResourceAmount::class);

        LootBound::upperBoundFor(new LootEnvelope(1e30, 0.0, 0.0), $this->policy(false, 0, 1_000));
    }

    /**
     * Une fortune au-dela de la precision exacte reste pillable.
     *
     * **C'est ce qui evite une immunite economique.** Refuser les stocks superieurs a deux puissance
     * cinquante-trois rendrait une planete assez riche impossible a piller : un verrou gagne en
     * jouant. La borne est calculee sur l'entier canonique que la colonne represente reellement.
     */
    public function testAFortuneBeyondExactPrecisionRemainsLootable(): void
    {
        $enorme = 9007199254740992.0;
        $borne = LootBound::upperBoundFor(new LootEnvelope($enorme, 0.0, 0.0), $this->policy(false, 0, 1_000));

        $this->assertSame(4503599627370496.0, $borne->metal, 'Half of a fortune this size must still be reservable.');
    }

    /**
     * Une politique de pillage.
     *
     * @param bool $inactive
     * @param int $fretDecouvreur
     * @param int $fretTotal
     * @return LootPolicy
     */
    private function policy(bool $inactive, int $fretDecouvreur, int $fretTotal): LootPolicy
    {
        return new LootPolicy($inactive, new AttackerCargoShare($fretDecouvreur, $fretTotal));
    }
}
