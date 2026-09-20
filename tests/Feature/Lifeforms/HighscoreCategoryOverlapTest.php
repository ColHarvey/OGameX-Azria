<?php

namespace Tests\Feature\Lifeforms;

use OGame\Factories\PlayerServiceFactory;
use OGame\Lifeforms\Catalogue\LifeformCatalogue;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\Services\LifeformLevels;
use OGame\Lifeforms\Species;
use OGame\Services\HighscoreService;
use OGame\Services\ObjectService;
use OGame\Services\PlayerService;
use Tests\AccountTestCase;

/**
 * **« Général = Économie + Recherche + Militaire + Formes de vie » est FAUX, et le code le veut ainsi.**
 *
 * J avais écrit cette égalité comme un témoin. Elle passait — sur un compte qui ne portait ni défense ni vaisseau :
 * le juste et le faux coïncidaient. Keven a demandé de le vérifier avec des transporteurs, des satellites, des
 * foreuses, des vaisseaux militaires et des défenses. La mesure lui donne raison, et montre que le recoupement est
 * **voulu**, pas accidentel — les commentaires de `PlanetService` le disent :
 *
 * | | Général | Économie | Militaire |
 * | --- | --- | --- | --- |
 * | bâtiments et installations | 100 % | 100 % | — |
 * | **défenses** | 100 % | **100 %** | **100 %** |
 * | **vaisseaux civils** | 100 % | **50 %** | **50 %** |
 * | vaisseaux militaires | 100 % | — | 100 % |
 *
 * **Seule la défense déborde.** Elle vaut 100 % dans les deux catégories, donc 200 % dans leur somme contre
 * 100 % au Général. Un vaisseau civil est bien compté dans les deux, mais à 50 % chacune : la somme retombe
 * juste, et il ne crée aucun excès. C est la correction d une première version de ce fichier, où j avais
 * additionné les deux et attendu 413 points d excès là où la mesure en donne 205.
 *
 * Les catégories d OGame ne sont donc pas une partition : ce sont des **points de vue** sur le même
 * patrimoine, et deux points de vue peuvent regarder la même défense. Le Général, lui, compte chaque
 * investissement **une seule fois**.
 *
 * Ce fichier remplace l égalité fausse par les deux invariants qui, eux, sont vrais et qui sont ce que la tranche
 * des formes de vie devait garantir :
 *
 * 1. le Général compte chaque investissement **une fois** — vérifié contre une reconstruction indépendante ;
 * 2. l ajout des formes de vie **ne change pas le total classique préexistant** — ni l Économie, ni la Recherche,
 *    ni le Militaire, et il déplace le Général exactement de ses propres points.
 */
final class HighscoreCategoryOverlapTest extends AccountTestCase
{
    private function joueur(): PlayerService
    {
        return resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);
    }

    private function classement(): HighscoreService
    {
        return resolve(HighscoreService::class);
    }

    /**
     * Un patrimoine qui couvre exactement ce que Keven a demandé de vérifier.
     *
     * @return array<string, int> nom machine => quantité
     */
    private function poserUnPatrimoineComplet(): array
    {
        $unites = [
            // Transporteurs et autres civils.
            'small_cargo' => 7,
            'large_cargo' => 3,
            'colony_ship' => 1,
            'recycler' => 2,
            'espionage_probe' => 11,
            // Satellite solaire et foreuse : civils, mais posés sur la planète et jamais en vol.
            'solar_satellite' => 5,
            'crawler' => 9,
            // Militaires.
            'light_fighter' => 13,
            'cruiser' => 4,
            'battle_ship' => 2,
            // Défenses.
            'rocket_launcher' => 25,
            'light_laser' => 12,
            'gauss_cannon' => 3,
            'small_shield_dome' => 1,
        ];

        foreach ($unites as $nom => $quantite) {
            $this->planetService->addUnit($nom, $quantite);
        }
        $this->planetService->save();

        return $unites;
    }

    /**
     * **Premisse du fichier** : le patrimoine posé contient bien des unités des trois familles, lues sur le
     * catalogue du jeu et non sur ma liste. Sans elle, tout ce qui suit pourrait mesurer un compte vide.
     */
    public function testTheBenchReallyCarriesCivilShipsMilitaryShipsAndDefences(): void
    {
        $this->poserUnPatrimoineComplet();

        $compte = static fn (array $objets): int => count($objets);
        $porte = function (array $objets): int {
            $total = 0;
            foreach ($objets as $objet) {
                $total += $this->planetService->getObjectAmount($objet->machine_name);
            }

            return $total;
        };

        $this->assertGreaterThan(0, $porte(ObjectService::getCivilShipObjects()), 'Aucun vaisseau civil sur le banc.');
        $this->assertGreaterThan(0, $porte(ObjectService::getMilitaryShipObjects()), 'Aucun vaisseau militaire sur le banc.');
        $this->assertGreaterThan(0, $porte(ObjectService::getDefenseObjects()), 'Aucune defense sur le banc.');
        $this->assertGreaterThan(0, $compte(ObjectService::getDefenseObjects()), 'Le catalogue des defenses est vide.');

        // Le satellite solaire et la foreuse sont bien des vaisseaux CIVILS pour le classement : si un jour ils
        // changeaient de famille, les tableaux de ce fichier ne diraient plus la verite.
        $civils = array_map(static fn ($o): string => $o->machine_name, ObjectService::getCivilShipObjects());
        $this->assertContains('solar_satellite', $civils, 'Le satellite solaire n est plus compte comme civil.');
        $this->assertContains('crawler', $civils, 'La foreuse n est plus comptee comme civile.');
    }

    /**
     * **Les catégories se recoupent, et l égalité naïve est fausse.** Ce témoin épingle le recoupement pour que
     * personne ne réécrive un jour l identité que j avais posée à tort.
     */
    public function testTheCategoriesOverlapSoTheirSumIsNotTheGeneralScore(): void
    {
        $this->poserUnPatrimoineComplet();
        $joueur = $this->joueur();

        $general = $this->classement()->getPlayerScore($joueur);
        $economie = $this->classement()->getPlayerScoreEconomy($joueur);
        $recherche = $this->classement()->getPlayerScoreResearch($joueur);
        $militaire = $this->classement()->getPlayerScoreMilitary($joueur);
        $formesDeVie = $this->classement()->getPlayerScoreLifeform($joueur);

        $somme = $economie + $recherche + $militaire + $formesDeVie;

        $this->assertGreaterThan(
            $general,
            $somme,
            'La somme des categories devrait DEPASSER le general : une defense y compte deux fois, une fois dans '
            . 'l Economie et une fois dans le Militaire. Somme ' . $somme . ', general ' . $general . '.'
        );

        // **L exces vaut exactement les DEFENSES, et elles seules.** Ma premiere expression y ajoutait les
        // vaisseaux civils : faux. Un civil vaut 100 % au general et 50 % + 50 % dans les deux categories — il
        // est bien compte dans les deux, mais la somme retombe juste. Seule la defense vaut 100 % PARTOUT, donc
        // 200 % dans la somme contre 100 % au general. Mesure : 205 points d exces, pas 413.
        $recoupement = 0.0;
        foreach (ObjectService::getDefenseObjects() as $objet) {
            $quantite = $this->planetService->getObjectAmount($objet->machine_name);
            $recoupement += ObjectService::getObjectRawPrice($objet->machine_name)->multiply($quantite)->sum();
        }

        // Les arrondis se font categorie par categorie : on tolere un point par categorie sommee, pas davantage.
        $this->assertEqualsWithDelta(
            $recoupement / 1000,
            $somme - $general,
            3.0,
            'L exces de la somme sur le general ne vaut pas le recoupement annonce par les commentaires du code.'
        );
    }

    /**
     * **Le Général compte chaque investissement une seule fois.** C est le vrai invariant, et il se prouve contre
     * une reconstruction indépendante : bâtiments et installations cumulés, plus une fois le prix de chaque unité.
     */
    public function testTheGeneralScoreCountsEveryInvestmentExactlyOnce(): void
    {
        $this->poserUnPatrimoineComplet();
        $joueur = $this->joueur();

        $depense = 0.0;
        foreach ([...ObjectService::getBuildingObjects(), ...ObjectService::getStationObjects()] as $objet) {
            $niveau = $this->planetService->getObjectLevel($objet->machine_name);
            if ($niveau > 0) {
                $depense += ObjectService::getObjectCumulativeCost($objet->machine_name, $niveau)->sum();
            }
        }
        foreach ([...ObjectService::getShipObjects(), ...ObjectService::getDefenseObjects()] as $objet) {
            $quantite = $this->planetService->getObjectAmount($objet->machine_name);
            $depense += ObjectService::getObjectRawPrice($objet->machine_name)->multiply($quantite)->sum();
        }

        $attendu = (int)floor($depense / 1000) + $joueur->getResearchScore();

        $this->assertSame(
            $attendu,
            $this->classement()->getPlayerScore($joueur),
            'Le general ne vaut plus une fois chaque investissement : une unite y est comptee deux fois, ou pas du tout.'
        );
    }

    /**
     * **L ajout des formes de vie ne touche pas au total classique.** Mesure avant / après, sur le même compte :
     * l Économie, la Recherche et le Militaire ne bougent pas d un point, et le Général bouge **exactement** des
     * points de formes de vie.
     */
    public function testAddingLifeformLevelsMovesOnlyTheGeneralAndOnlyByItsOwnPoints(): void
    {
        $this->poserUnPatrimoineComplet();

        $avant = $this->classement()->getPlayerScores($this->joueur());
        $this->assertSame(0, $avant['lifeform'], 'Premisse : le compte ne doit porter aucune forme de vie au depart.');

        $corps = $this->planetService->getPlanetId();
        $niveaux = resolve(LifeformLevels::class);
        $niveaux->setLevel($corps, LifeformKind::Building, LifeformCatalogue::byId(11112)->id, 4);
        $niveaux->setLevel($corps, LifeformKind::Technology, LifeformCatalogue::technologiesOf(Species::Humans)[0]->id, 6);

        $apres = $this->classement()->getPlayerScores($this->joueur());

        // Premisse : l ajout doit valoir quelque chose, sinon « ne change rien » serait vrai pour rien.
        $this->assertGreaterThan(0, $apres['lifeform'], 'Les niveaux poses ne valent aucun point : l essai ne distingue rien.');

        $this->assertSame($avant['economy'], $apres['economy'], 'L Economie classique a bouge.');
        $this->assertSame($avant['research'], $apres['research'], 'La Recherche classique a bouge.');
        $this->assertSame($avant['military'], $apres['military'], 'Le Militaire a bouge.');

        $this->assertSame(
            $apres['lifeform'],
            $apres['general'] - $avant['general'],
            'Le general n a pas bouge exactement des points de formes de vie : soit ils y entrent deux fois, soit '
            . 'quelque chose d autre a change.'
        );

        // Et la ventilation reste la somme de ses deux parts.
        $this->assertSame($apres['lifeform'], $apres['lifeform_economy'] + $apres['lifeform_technology']);
    }
}
