<?php

namespace Tests\Feature\Lifeforms;

use OGame\Lifeforms\Catalogue\LifeformCatalogue;
use OGame\Lifeforms\Catalogue\LifeformFormulas;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\Catalogue\LifeformObject;
use OGame\Lifeforms\Services\LifeformLevels;
use OGame\Services\HighscoreService;
use OGame\Services\PlayerService;
use Tests\AccountTestCase;

/**
 * **Jusqu ou les points des formes de vie restent-ils exacts ?**
 *
 * ## Une conclusion que j avais donnee, et qui etait fausse
 *
 * J avais ecrit : « l ecart du flottant vaut quelques dizaines de ressources, donc il ne deplace aucun point, qui
 * en vaut mille ». Keven a montre que c est faux en general : avec une division entiere, **+1 ressource sur
 * 999 999 fait passer de 999 a 1 000 points**. Une erreur bien inferieure a mille suffit des qu elle traverse une
 * frontiere. Le temoin exact d un niveau prouvait ce niveau-la, rien d autre.
 *
 * Le balayage refait sur **tout le catalogue** le confirme : 2 811 divergences de points sur 12 000 combinaisons
 * d un objet seul, 952 sur 4 000 cumuls de plusieurs objets et corps.
 *
 * ## La chaine, maillon par maillon
 *
 * - **Colonnes** : `bigInteger` dans les deux tables — 64 bits signes, jusqu a 9 223 372 036 854 775 807.
 * - **PHP** : `Resource` garde sa valeur en `float`. C est le maillon faible, et une somme SQL exacte **ne repare
 *   pas** une approximation deja faite ici.
 * - **Agregation, tri, rangs** : en SQL sur ces colonnes, exacts — mais sur des valeurs deja approchees en amont.
 * - **Affichage** : la page recoit une chaine deja formatee ; le navigateur ne recoit jamais ces scores comme des
 *   nombres JavaScript.
 *
 * ## Les trois seuils, mesures
 *
 * 1. **Sous 2^53 de ressources cumulees** (9 007 199 254 740 992, soit environ 9,0 x 10^12 points) : exact. C est
 *    la zone de tout joueur reel — le meilleur joueur du serveur officiel `en1` porte 35 989 655 471 points en
 *    Lifeform Technology, soit deux cent cinquante fois moins.
 * 2. **Au-dela**, un point peut manquer ou etre en trop. Cas reproductible du catalogue : `research_centre` au
 *    niveau 93, cumul 649 537 492 774 994 001, **reste 1** — le flottant perd cette unite et rend **un point de
 *    moins**.
 * 3. **Au-dela de `PHP_INT_MAX` pour un SEUL niveau**, la formule du jeu elle-meme casse : `academy_of_sciences`
 *    niveau 60 coute a lui seul 11 846 978 929 429 620 736 en metal, et le `(int)` de `LifeformFormulas::cost()` deborde en emettant
 *    un avertissement PHP. `(int)9.3e18` rend −9 146 744 073 709 551 616. **Ce defaut precede cette tranche** : il
 *    vit dans le calcul du cout, donc dans les devis et les files, pas seulement dans le classement. Il est
 *    signale, pas corrige : toucher a la formule du jeu demande l accord de Keven.
 */
class LifeformHighscorePrecisionTest extends AccountTestCase
{
    /**
     * Le cout cumule **exact**, en arithmetique decimale : l entier PHP lui-meme deborde sur ces valeurs.
     *
     * @return numeric-string Une somme de couts, toujours un nombre decimal sans signe.
     */
    private function cumulExact(LifeformObject $objet, int $niveau): string
    {
        $total = '0';

        for ($n = 1; $n <= $niveau; $n++) {
            foreach ([$objet->metal, $objet->crystal, $objet->deuterium] as $base) {
                $total = bcadd($total, sprintf('%.0f', floor($base * ($objet->costFactor ** ($n - 1)) * $n)), 0);
            }
        }

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
     * **Sous 2^53, l exactitude est totale** — c est la zone ou vivent tous les joueurs reels.
     */
    public function testBelowTwoToTheFiftyThreeThePointsAreExact(): void
    {
        $techno = LifeformCatalogue::byId(11201);
        $niveau = 60;

        $cumul = $this->cumulExact($techno, $niveau);
        $this->assertSame(-1, bccomp($cumul, (string)(2 ** 53), 0), 'Premisse : ce cumul doit rester sous 2^53.');

        $this->poser($techno->id, LifeformKind::Technology, $niveau);

        $this->assertSame(
            bcdiv($cumul, '1000', 0),
            (string)resolve(HighscoreService::class)->getPlayerScoreLifeformTechnology($this->joueur()),
            'Sous 2^53, le chemin flottant rend exactement la somme decimale.'
        );
    }

    /**
     * **Au-dela de 2^53, un point PEUT manquer.** Ce temoin epingle le cas mesure, pour que la limite reelle ne
     * bouge pas en silence : si la chaine devient exacte un jour, il tombera et il faudra le redire.
     */
    public function testAboveTwoToTheFiftyThreeASinglePointCanBeLost(): void
    {
        $batiment = LifeformCatalogue::byId(11103);   // research_centre
        $niveau = 93;

        $cumul = $this->cumulExact($batiment, $niveau);
        $this->assertSame('1', bcmod($cumul, '1000'), 'Premisse : ce cumul tombe a une unite d une frontiere de mille.');
        $this->assertSame(1, bccomp($cumul, (string)(2 ** 53), 0), 'Premisse : il depasse 2^53.');

        $this->poser($batiment->id, LifeformKind::Building, $niveau);

        $attendu = bcdiv($cumul, '1000', 0);
        $rendu = (string)resolve(HighscoreService::class)->getPlayerScoreLifeformEconomy($this->joueur());

        $this->assertSame(
            '-1',
            bcsub($rendu, $attendu, 0),
            'Le flottant perd l unite qui franchissait la frontiere : un point de moins. '
            . 'Attendu ' . $attendu . ', rendu ' . $rendu . '.'
        );
    }

    /**
     * **La formule du cout elle-meme deborde de l entier a tres haut niveau.** Defaut anterieur a cette tranche :
     * il vit dans `LifeformFormulas::cost()`, donc partout ou un cout est calcule. On le constate sans le corriger.
     */
    public function testTheCostFormulaItselfOverflowsTheIntegerAtVeryHighLevels(): void
    {
        $objet = LifeformCatalogue::byId(11104);   // academy_of_sciences, facteur 1,7
        $niveau = 60;

        $brut = $objet->metal * ($objet->costFactor ** ($niveau - 1)) * $niveau;
        $this->assertGreaterThan(
            (float)PHP_INT_MAX,
            $brut,
            'Premisse : a ce niveau, le cout d un seul palier depasse deja la capacite d un entier.'
        );

        // L avertissement « not representable as an int » est le symptome meme : on le laisse passer pour lire la
        // valeur rendue, qui n est plus celle du calcul.
        $cout = @LifeformFormulas::cost($objet, $niveau, 0.0);

        $this->assertNotSame(
            sprintf('%.0f', floor($brut)),
            sprintf('%.0f', $cout->metal->get()),
            'Le cout rendu n est plus celui du calcul : la conversion en entier a deborde. '
            . 'Ce constat est signale a Keven, pas corrige — la formule du jeu ne se touche pas sans son accord.'
        );
    }
}
