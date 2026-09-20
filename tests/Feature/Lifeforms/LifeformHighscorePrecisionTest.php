<?php

namespace Tests\Feature\Lifeforms;

use OGame\Lifeforms\Catalogue\LifeformCatalogue;
use OGame\Lifeforms\Catalogue\LifeformFormulas;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\Catalogue\LifeformObject;
use OGame\Lifeforms\LifeformRefused;
use OGame\Lifeforms\Services\LifeformLevels;
use OGame\Services\HighscoreService;
use OGame\Services\PlayerService;
use Tests\AccountTestCase;

/**
 * **Jusqu ou les points des formes de vie sont-ils exacts ? La reponse honnete est : pas partout, et pas la ou je
 * l avais dit.**
 *
 * ## Deux conclusions a moi, toutes deux fausses, et ce qui les a corrigees
 *
 * 1. « L ecart du flottant vaut quelques dizaines de ressources, donc il ne deplace aucun point, qui en vaut
 *    mille. » Keven : faux en general — avec une division entiere, **+1 ressource sur 999 999 fait passer de 999
 *    a 1 000 points**. Ce n est pas la TAILLE de l erreur qui compte, c est le fait qu elle traverse une frontiere.
 * 2. « Le defaut n apparait qu au-dela de 2^53. » Faux aussi, et de loin : il apparait au **niveau 3**.
 *
 * ## Trois references, et une seule est la bonne
 *
 * Il a fallu les separer, parce que la mauvaise donnait un bilan renverse :
 *
 * - **R1, le decimal nominal** : `costFactor: 1.4` tel que `app/Lifeforms/Catalogue/Data/*.php` l ecrit. C est ce
 *   que la formule VEUT DIRE, et c est la reference retenue. On la relit sur le flottant du catalogue par la plus
 *   courte ecriture decimale qui y revient, et on **verifie** ce retour.
 * - **R2, la valeur exacte du double** : 1,4 en binaire64 vaut exactement
 *   1,399999999999999911182158029987476766109466552734375. C est ce que la formule calculerait sans jamais
 *   rearrondir. **Ce n est pas la reference** : au niveau 2 de `neuro_calibration_centre`, R2 rend 169 999 la ou le
 *   decimal nominal et le jeu rendent tous deux 170 000 — le rearrondi du flottant ramene le resultat sur l entier
 *   voulu. Compare a R2, le jeu aurait paru fautif sur dix-neuf lignes du catalogue des le niveau 2.
 * - **R3, le chemin flottant du jeu**.
 *
 * ## Ce qui est mesure, et **seulement** cela
 *
 * Un balayage des 120 objets du catalogue, chacun de son niveau 1 jusqu a sa borne de representation, compare les
 * POINTS de R1 et de R3. **Aucun objet n est exact jusqu au bout** : les premieres divergences tombent aux niveaux
 * 3, 4, 5, 8, 35, 41, 43, puis plus haut. Les temoins ci-dessous epinglent quatre cas precis de ce balayage.
 *
 * **La reserve n est pas fermee.** Ces temoins disent ce qui a ete compare — un objet seul, sans reduction de
 * cout, sur un corps —, pas ce qui vaut pour toute combinaison de plusieurs objets, de plusieurs corps et d une
 * reduction. La chaine d ecriture (colonnes `bigInteger`, agregation SQL, rangs, tri, affichage) est exacte : le
 * maillon flottant est **en amont**, dans `LifeformFormulas::cost()` puis dans l accumulation des `Resources`, et
 * une somme SQL exacte ne repare pas une approximation deja faite en PHP.
 *
 * Changer la formule du jeu pour la rendre exacte est une decision de Keven, pas une correction : elle deplacerait
 * les couts de construction de tous les joueurs. Ces temoins **mesurent** l ecart, ils ne le corrigent pas.
 */
class LifeformHighscorePrecisionTest extends AccountTestCase
{
    /**
     * Le decimal nominal d un facteur : la plus courte ecriture decimale qui redonne exactement ce flottant.
     *
     * C est ce que la source du catalogue ecrit (`costFactor: 1.4`). Le retour est **verifie**, pas suppose : si un
     * jour un facteur n avait pas d ecriture courte, l essai le dirait au lieu de mesurer contre autre chose.
     *
     * @return numeric-string
     */
    private function decimalNominal(float $facteur): string
    {
        $ecriture = json_encode($facteur);
        // On ETABLIT que c est bien un nombre decimal, au lieu de l affirmer par une annotation : un facteur
        // qui n en serait pas ferait mesurer la suite contre autre chose.
        if (!is_string($ecriture) || !is_numeric($ecriture)) {
            $this->fail('Le facteur du catalogue n a pas d ecriture decimale courte.');
        }
        $this->assertSame($facteur, (float)$ecriture, 'L ecriture decimale ne redonne pas le facteur du catalogue.');

        return $ecriture;
    }

    /**
     * Le cumul **exact** d un objet jusqu a un niveau, en arithmetique decimale.
     *
     * Aucun flottant n intervient dans le calcul : ni puissance, ni produit, ni somme. Le seul flottant touche est
     * le facteur du catalogue, converti une fois en decimal et verifie. C est ce que Keven exigeait — « partir des
     * valeurs exactes du catalogue, sans calcul flottant intermediaire ».
     *
     * @return numeric-string
     */
    private function cumulExact(LifeformObject $objet, int $niveau): string
    {
        $facteur = $this->decimalNominal($objet->costFactor);
        $decimales = strlen(substr($facteur, (int)strpos($facteur, '.') + 1));

        $total = '0';
        $puissance = '1';
        for ($n = 1; $n <= $niveau; $n++) {
            // La puissance exacte de rang n porte au plus n fois les decimales du facteur : on garde cette echelle,
            // plus une marge, pour qu aucune multiplication ne soit tronquee.
            bcscale($decimales * $n + 20);
            foreach ([$objet->metal, $objet->crystal, $objet->deuterium] as $base) {
                $produit = bcmul(bcmul((string)$base, $puissance), (string)$n);
                $total = bcadd($total, bcadd($produit, '0', 0), 0);
            }
            $puissance = bcmul($puissance, $facteur);
        }

        /** @var numeric-string $total */
        return $total;
    }

    private function joueur(): PlayerService
    {
        $joueur = $this->planetService->getPlayer();
        $this->assertNotNull($joueur, 'Le banc doit porter un joueur.');

        return $joueur;
    }

    private function poser(int $objectId, LifeformKind $genre, int $niveau): void
    {
        resolve(LifeformLevels::class)->setLevel($this->planetService->getPlanetId(), $genre, $objectId, $niveau);
    }

    /**
     * **La reference exacte du double n est PAS la reference.** Ce temoin garde la lecon : c est en la prenant pour
     * telle que j avais conclu, a tort, que le jeu perdait un point la ou il n en perdait pas.
     */
    public function testTheExactValueOfTheStoredDoubleIsATrapAndNotTheReference(): void
    {
        $objet = LifeformCatalogue::byMachineName('neuro_calibration_centre');   // facteur 1,7, base 50 000
        $niveau = 2;

        // R1, le decimal nominal : 50 000 x 1,7 x 2 vaut exactement 170 000.
        $nominal = $this->cumulExact($objet, $niveau);

        // R3, le jeu.
        $jeu = 0;
        for ($n = 1; $n <= $niveau; $n++) {
            $jeu += (int)LifeformFormulas::cost($objet, $n, 0.0)->sum();
        }

        // R2, la valeur exacte du double stocke : elle tombe JUSTE SOUS l entier, et le plancher perd une unite.
        $exactDuDouble = $this->valeurExacteDuDouble($objet->costFactor);
        bcscale(120);
        $parR2 = '0';
        for ($n = 1; $n <= $niveau; $n++) {
            foreach ([$objet->metal, $objet->crystal, $objet->deuterium] as $base) {
                $parR2 = bcadd($parR2, bcadd(bcmul(bcmul((string)$base, bcpow($exactDuDouble, (string)($n - 1))), (string)$n), '0', 0), 0);
            }
        }

        $this->assertSame($nominal, (string)$jeu, 'Le jeu et le decimal nominal disent la meme chose a ce niveau.');
        $this->assertSame(-1, bccomp($parR2, $nominal, 0), 'Premisse : la valeur exacte du double tombe SOUS le nominal.');
        $this->assertSame(
            '-3',
            bcsub($parR2, $nominal, 0),
            'Une unite par ressource : c est cet ecart-la qui aurait fait declarer le jeu fautif des le niveau 2.'
        );
    }

    /**
     * **La divergence commence au niveau 3**, pas au-dela de 2^53. Cas mesure du balayage complet.
     */
    public function testTheDivergenceStartsAtLevelThreeWithVerySmallNumbers(): void
    {
        $objet = LifeformCatalogue::byMachineName('obsidian_shield_reinforcement');   // facteur 1,4, base 250 000 x 3

        // Au niveau 2, le jeu est exact.
        $this->poser($objet->id, LifeformKind::Technology, 2);
        $this->assertSame(
            bcdiv($this->cumulExact($objet, 2), '1000', 0),
            (string)resolve(HighscoreService::class)->getPlayerScoreLifeformTechnology($this->joueur()),
            'Au niveau 2 le chemin flottant rend exactement la somme decimale.'
        );

        // Au niveau 3, `1,4^2` vaut en binaire un cheveu sous 1,96 : le plancher perd une unite par ressource,
        // et ces trois unites franchissent une frontiere de mille.
        $exact = $this->cumulExact($objet, 3);
        $this->assertSame('7260000', $exact, 'La valeur exacte de ce cumul a change : le reste du temoin ne vaut plus.');
        $this->assertSame(-1, bccomp($exact, (string)(2 ** 53), 0), 'Premisse : on est TRES loin sous 2^53.');

        $this->poser($objet->id, LifeformKind::Technology, 3);
        $rendu = (string)resolve(HighscoreService::class)->getPlayerScoreLifeformTechnology($this->joueur());

        $this->assertSame('7260', bcdiv($exact, '1000', 0), 'Le cumul exact vaut 7 260 points.');
        $this->assertSame('7259', $rendu, 'Le jeu en rend 7 259 : un point de moins, des le niveau 3.');
    }

    /**
     * **Ce n est pas la taille de l erreur qui deplace un point, c est la frontiere.** Deux niveaux voisins du meme
     * objet : 220 ressources d ecart ne changent rien, 271 changent un point.
     */
    public function testWhatMovesAPointIsTheBoundaryAndNotTheSizeOfTheError(): void
    {
        $objet = LifeformCatalogue::byId(11103);   // research_centre, facteur 1,3

        $sansEffet = $this->ecartAuNiveau($objet, 85);
        $avecEffet = $this->ecartAuNiveau($objet, 86);

        $this->assertSame('220', $sansEffet['ecart'], 'L ecart mesure au niveau 85 a change.');
        $this->assertSame('0', $sansEffet['ecartDePoints'], '220 ressources d ecart ne deplacent aucun point.');

        $this->assertSame('271', $avecEffet['ecart'], 'L ecart mesure au niveau 86 a change.');
        $this->assertSame('1', $avecEffet['ecartDePoints'], '271 ressources d ecart en deplacent un : la frontiere est franchie.');

        // Et l erreur est ici en FAVEUR du joueur : le jeu rend un point de plus que la valeur exacte.
        $this->assertSame(1, bccomp($avecEffet['jeu'], $avecEffet['exact'], 0), 'Le sens de l ecart a change.');
    }

    /**
     * **2^53 ne decide de rien.** Un cumul de 1,5 x 10^19 — au-dessus meme de `PHP_INT_MAX` — rend le point juste,
     * pendant qu un cumul de 7 260 000 en perd un. Le seuil garantit la representation d un ENTIER, pas
     * l exactitude de la formule qui y mene.
     */
    public function testTwoToTheFiftyThreeDecidesNothingAtAll(): void
    {
        $objet = LifeformCatalogue::byMachineName('rune_shields');   // facteur 1,5
        $mesure = $this->ecartAuNiveau($objet, 63);

        $this->assertSame(1, bccomp($mesure['exact'], (string)(2 ** 53), 0), 'Premisse : ce cumul depasse 2^53.');
        $this->assertSame(1, bccomp($mesure['exact'], (string)PHP_INT_MAX, 0), 'Premisse : il depasse meme PHP_INT_MAX.');
        $this->assertSame('436', $mesure['ecart'], 'L ecart mesure a ce niveau a change.');
        $this->assertSame('0', $mesure['ecartDePoints'], 'Malgre 436 ressources d ecart, le point est juste.');
    }

    /**
     * Le cas que j avais epingle, **avec les bons chiffres cette fois**. L ancien temoin exigeait « un point de
     * moins » contre une reference qui partait elle-meme des couts flottants : il mesurait l ecart entre deux
     * quantites issues du meme flottant. Le jeu rend en realite **un point de plus**, et l ecart vaut 1 896.
     */
    public function testTheResearchCentreAtLevelNinetyThreePinnedWithTheRightFigures(): void
    {
        $mesure = $this->ecartAuNiveau(LifeformCatalogue::byId(11103), 93);

        $this->assertSame('649537492774992024', $mesure['exact'], 'Le total exact a change.');
        $this->assertSame('649537492774993920', $mesure['jeu'], 'Le total employe par le classement a change.');
        $this->assertSame('1896', $mesure['ecart'], 'L ecart a change.');
        $this->assertSame('649537492774992', $mesure['pointsExacts']);
        $this->assertSame('649537492774993', $mesure['pointsDuJeu']);
        $this->assertSame('1', $mesure['ecartDePoints'], 'Un point de PLUS, pas de moins.');
    }

    /**
     * **Au-dela de la borne de representation, la formule refuse au lieu de deborder.** C etait le troisieme
     * constat de l ancienne version de ce fichier, et il est desormais corrige — voir `LifeformCostBoundTest` pour
     * le parcours joueur complet.
     */
    public function testBeyondTheRepresentableBoundTheFormulaRefusesInsteadOfOverflowing(): void
    {
        $objet = LifeformCatalogue::byId(11104);   // academy_of_sciences, facteur 1,7
        $niveau = 60;

        $brut = $objet->metal * ($objet->costFactor ** ($niveau - 1)) * $niveau;
        $this->assertGreaterThan((float)PHP_INT_MAX, $brut, 'Premisse : a ce niveau un seul palier depasse l entier.');

        $this->expectException(LifeformRefused::class);
        LifeformFormulas::cost($objet, $niveau, 0.0);
    }

    /**
     * L ecart entre la reference nominale et le total que le classement emploie, a un niveau donne.
     *
     * @return array{exact: numeric-string, jeu: numeric-string, ecart: numeric-string, pointsExacts: numeric-string, pointsDuJeu: numeric-string, ecartDePoints: numeric-string}
     */
    private function ecartAuNiveau(LifeformObject $objet, int $niveau): array
    {
        $exact = $this->cumulExact($objet, $niveau);

        // Le total tel que le classement l accumule : les memes `Resources` que `LifeformScoreCalculator`.
        $jeu = new \OGame\Models\Resources(0, 0, 0, 0);
        for ($n = 1; $n <= $niveau; $n++) {
            $jeu->add(LifeformFormulas::cost($objet, $n, 0.0));
        }
        $totalDuJeu = sprintf('%.0F', $jeu->sum());

        /** @var numeric-string $totalDuJeu */
        return [
            'exact' => $exact,
            'jeu' => $totalDuJeu,
            'ecart' => bcsub($totalDuJeu, $exact, 0),
            'pointsExacts' => bcdiv($exact, '1000', 0),
            'pointsDuJeu' => bcdiv($totalDuJeu, '1000', 0),
            'ecartDePoints' => bcsub(bcdiv($totalDuJeu, '1000', 0), bcdiv($exact, '1000', 0), 0),
        ];
    }

    /**
     * La valeur exacte d un double positif normal, lue sur ses BITS — signe, exposant, mantisse, puis `m x 2^e`.
     *
     * Un aller-retour `(float)sprintf(...) === $f` ne prouverait rien : « 1,4 » revient au meme double sans etre sa
     * valeur. Cette lecture-ci se demontre, et aucun flottant n intervient dans le calcul.
     *
     * @return numeric-string
     */
    private function valeurExacteDuDouble(float $f): string
    {
        $bits = unpack('J', pack('E', $f));
        $this->assertIsArray($bits);
        $exposant = ($bits[1] >> 52) & 0x7FF;
        $mantisse = $bits[1] & 0xFFFFFFFFFFFFF;
        $this->assertNotSame(0, $exposant, 'Double sous-normal : hors du perimetre de cette lecture.');

        $entier = bcadd((string)(1 << 52), (string)$mantisse, 0);
        $puissance = $exposant - 1075;
        if ($puissance >= 0) {
            /** @var numeric-string $valeur */
            $valeur = bcmul($entier, bcpow('2', (string)$puissance, 0), 0);

            return $valeur;
        }

        // 2^-k vaut exactement 5^k / 10^k : la division tombe juste, sans arrondi.
        $k = -$puissance;
        /** @var numeric-string $valeur */
        $valeur = bcdiv(bcmul($entier, bcpow('5', (string)$k, 0), 0), bcpow('10', (string)$k, 0), $k);

        return $valeur;
    }
}
