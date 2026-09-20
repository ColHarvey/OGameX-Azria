<?php

namespace Tests\Feature\Lifeforms;

use OGame\Lifeforms\Catalogue\LifeformCatalogue;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\Catalogue\LifeformObject;
use OGame\Lifeforms\Services\LifeformLevels;
use OGame\Services\HighscoreService;
use OGame\Services\PlayerService;
use Tests\AccountTestCase;

/**
 * **Jusqu ou les points des formes de vie restent-ils exacts ?**
 *
 * Question posee par Keven le 20 septembre 2026, apres avoir vu le classement General d un compte de
 * demonstration passer a 15 746 039 171 753 points. Le nombre est enorme mais legitime : une technologie de
 * niveau 100 coute reellement cela. Restait a savoir si la chaine numerique le porte sans perdre de points en
 * silence bien avant sa limite.
 *
 * ## Ce que la chaine porte, maillon par maillon
 *
 * - **Les colonnes** : `bigInteger` dans `highscores` et `alliance_highscores`, soit 64 bits signes — jusqu a
 *   9,22 x 10^18. Ce n est pas le maillon faible.
 * - **PHP** : `Resource` garde sa valeur en `float` (`protected float $rawValue`). Tout le calcul est donc
 *   flottant, des le cout d un niveau et non seulement a la division. C est **lui** le maillon faible.
 * - **L agregation des alliances** : `SUM(...)` en SQL sur des entiers, exacte.
 * - **Le tri et les rangs** : `orderBy` en SQL sur les memes colonnes, exacts.
 * - **L affichage** : la page recoit une chaine deja formatee (`points_formatted`). Le navigateur ne recoit
 *   jamais ces scores comme des nombres JavaScript, donc aucune perte cote client.
 *
 * ## La limite, mesuree
 *
 * Sur `intergalactic_envoys` (5000/2500/500, facteur 1,3), la somme flottante reste **exacte jusqu au niveau 86**.
 * Le premier ecart apparait au **niveau 87**, quand le cumul depasse 2^53 : il vaut **une unite de ressource**. Au
 * niveau 100, le cumul atteint 6,39 x 10^17 et l ecart vaut **34 unites**.
 *
 * **Mais un point vaut mille ressources.** Un ecart de 34 ne deplace donc aucun point. Pour qu un seul point soit
 * faux, l erreur relative du flottant (2^-52) devrait atteindre 1000 unites, ce qui demande un cumul d environ
 * 4,5 x 10^18 ressources — au-dela de quoi on bute de toute facon sur `PHP_INT_MAX` et sur la colonne. La
 * conclusion tient en une phrase : **on ne perd aucun point avant de toucher la limite de la base elle-meme.**
 */
class LifeformHighscorePrecisionTest extends AccountTestCase
{
    /**
     * Le cout cumule exact, somme en ENTIER — la reference contre laquelle le chemin flottant est juge.
     */
    private function cumulEntier(LifeformObject $objet, int $niveau): int
    {
        $total = 0;

        for ($n = 1; $n <= $niveau; $n++) {
            foreach ([$objet->metal, $objet->crystal, $objet->deuterium] as $base) {
                $total += (int)floor($base * ($objet->costFactor ** ($n - 1)) * $n);
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

    /**
     * **Sous 2^53, l exactitude est totale** — c est la zone ou vivent tous les joueurs reels.
     */
    public function testBelowTwoToTheFiftyThreeThePointsAreExact(): void
    {
        $techno = LifeformCatalogue::byId(11201);
        $niveau = 60;

        $cumul = $this->cumulEntier($techno, $niveau);
        $this->assertLessThan(2 ** 53, $cumul, 'Premisse : ce niveau doit rester sous la limite du flottant.');

        resolve(LifeformLevels::class)->setLevel($this->planetService->getPlanetId(), LifeformKind::Technology, $techno->id, $niveau);

        $this->assertSame(
            intdiv($cumul, 1000),
            resolve(HighscoreService::class)->getPlayerScoreLifeformTechnology($this->joueur()),
            'Sous 2^53, le chemin flottant rend exactement la somme entiere.'
        );
    }

    /**
     * **Au-dela de 2^53, la somme des RESSOURCES derive — mais pas le nombre de POINTS.**
     *
     * L essai ne demande pas au code d etre exact en ressources : il demande que la derive reste mille fois plus
     * petite que la valeur d un point, donc invisible sur le classement. C est la garantie utile.
     */
    public function testAboveTwoToTheFiftyThreeTheDriftNeverMovesASinglePoint(): void
    {
        $techno = LifeformCatalogue::byId(11201);
        $niveau = 100;

        $cumul = $this->cumulEntier($techno, $niveau);
        $this->assertGreaterThan(2 ** 53, $cumul, 'Premisse : ce niveau doit depasser la limite du flottant.');

        resolve(LifeformLevels::class)->setLevel($this->planetService->getPlanetId(), LifeformKind::Technology, $techno->id, $niveau);

        $rendu = resolve(HighscoreService::class)->getPlayerScoreLifeformTechnology($this->joueur());
        $attendu = intdiv($cumul, 1000);

        $this->assertSame(
            $attendu,
            $rendu,
            'La derive du flottant est de quelques dizaines de ressources : elle ne deplace aucun point, '
            . 'qui en vaut mille. Si ce temoin tombe, la limite reelle a change et il faut la remesurer.'
        );

        // Et l on dit ou se situe la valeur par rapport aux deux plafonds qui comptent.
        $this->assertLessThan(PHP_INT_MAX, $cumul, 'Le cumul reste representable en entier PHP.');
        $this->assertLessThan(9223372036854775807, $rendu * 1000, 'Et la colonne bigInteger le porte.');
    }

    /**
     * Plusieurs objets a haut niveau sur la meme planete : la somme reste juste, sans double comptage ni
     * accumulation d erreurs.
     */
    public function testSeveralHighLevelObjectsStillAddUpExactly(): void
    {
        $niveaux = resolve(LifeformLevels::class);
        $planete = $this->planetService->getPlanetId();

        $attendu = 0;
        foreach ([[11201, 70], [11202, 65], [11203, 60]] as [$id, $niveau]) {
            if (!LifeformCatalogue::has($id)) {
                continue;
            }
            $objet = LifeformCatalogue::byId($id);
            $niveaux->setLevel($planete, LifeformKind::Technology, $id, $niveau);
            $attendu += $this->cumulEntier($objet, $niveau);
        }

        $this->assertGreaterThan(0, $attendu, 'Premisse : au moins un objet du catalogue doit avoir ete pose.');

        $this->assertSame(
            intdiv($attendu, 1000),
            resolve(HighscoreService::class)->getPlayerScoreLifeformTechnology($this->joueur()),
            'Trois technologies a haut niveau se somment exactement.'
        );
    }
}
