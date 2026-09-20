<?php

namespace Tests\Feature\Lifeforms;

use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\Lifeforms\Catalogue\LifeformCatalogue;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\Catalogue\LifeformObject;
use OGame\Lifeforms\Score\LifeformScoreOpenRules;
use OGame\Lifeforms\Services\LifeformLevels;
use OGame\Lifeforms\Species;
use OGame\Models\Lifeforms\LifeformQueue;
use OGame\Services\HighscoreService;
use OGame\Services\PlayerService;
use Tests\AccountTestCase;

/**
 * **Les formes de vie donnent enfin leurs points** (journal §173).
 *
 * Avant cette tranche, un compte portant 147 niveaux de batiments et 235 niveaux de technologies de formes de vie
 * recevait **zero point** : aucune des trois fonctions de score ne consultait leur catalogue. Mesure faite le
 * 20 septembre 2026 sur la base de demonstration — vingt niveaux ajoutes, pas un point de plus.
 *
 * ## La regle officielle que ces temoins tiennent
 *
 * Les batiments alimentent `Lifeform Economy`, les technologies `Lifeform Technology`, leur somme forme `Lifeform`,
 * et ce total entre dans le **General**. Ils n entrent **jamais** dans l Economie ni la Recherche classiques.
 *
 * ## Pourquoi les valeurs attendues sont recalculees ici
 *
 * Un essai qui demanderait la valeur au calculateur ne prouverait que sa coherence avec lui-meme. La formule est
 * donc reecrite dans ce fichier — `floor(base × facteur^(n−1) × n)`, sommee de 1 au niveau atteint — et c est
 * elle qui fixe l attendu. Si la formule du jeu change, ces temoins tombent, et c est le but.
 */
class LifeformHighscoreTest extends AccountTestCase
{
    /**
     * Le cout cumule d un objet jusqu a un niveau, recalcule independamment du code mesure.
     */
    private function coutCumule(LifeformObject $objet, int $niveau): int
    {
        $total = 0;

        for ($n = 1; $n <= $niveau; $n++) {
            foreach ([$objet->metal, $objet->crystal, $objet->deuterium] as $base) {
                $total += (int)floor($base * ($objet->costFactor ** ($n - 1)) * $n);
            }
        }

        return $total;
    }

    private function unBatiment(): LifeformObject
    {
        // Le bouclier planetaire : 500 000 de ressources de base. Un secteur residentiel coute 7 metal —
        // sept niveaux n y vaudraient pas un seul point, et l essai ne distinguerait rien.
        return LifeformCatalogue::byId(11112);
    }

    private function uneTechnologie(): LifeformObject
    {
        return LifeformCatalogue::technologiesOf(Species::Humans)[0];
    }

    private function poser(int $planetId, LifeformKind $genre, LifeformObject $objet, int $niveau): void
    {
        resolve(LifeformLevels::class)->setLevel($planetId, $genre, $objet->id, $niveau);
    }

    private function joueur(): PlayerService
    {
        $joueur = $this->planetService->getPlayer();
        $this->assertNotNull($joueur, 'Le banc doit porter un joueur.');

        return $joueur;
    }

    private function classement(): HighscoreService
    {
        return resolve(HighscoreService::class);
    }

    public function testAPlayerWithoutLifeformsScoresExactlyZeroOnTheThreeNewRankings(): void
    {
        $joueur = $this->joueur();

        $this->assertSame(0, $this->classement()->getPlayerScoreLifeformEconomy($joueur));
        $this->assertSame(0, $this->classement()->getPlayerScoreLifeformTechnology($joueur));
        $this->assertSame(0, $this->classement()->getPlayerScoreLifeform($joueur));
    }

    public function testACompletedLifeformBuildingGivesExactlyTheExpectedPoints(): void
    {
        $joueur = $this->joueur();
        $batiment = $this->unBatiment();
        $niveau = 7;

        $avant = $this->classement()->getPlayerScoreLifeformEconomy($joueur);
        $this->poser($this->planetService->getPlanetId(), LifeformKind::Building, $batiment, $niveau);

        $attendu = (int)floor($this->coutCumule($batiment, $niveau) / 1000);
        $this->assertSame(
            $attendu,
            $this->classement()->getPlayerScoreLifeformEconomy($joueur) - $avant,
            'Les points d un batiment de forme de vie valent son cout cumule divise par mille.'
        );
    }

    public function testACompletedLifeformTechnologyGivesExactlyTheExpectedPoints(): void
    {
        $joueur = $this->joueur();
        $techno = $this->uneTechnologie();
        $niveau = 4;

        $this->poser($this->planetService->getPlanetId(), LifeformKind::Technology, $techno, $niveau);

        $this->assertSame(
            (int)floor($this->coutCumule($techno, $niveau) / 1000),
            $this->classement()->getPlayerScoreLifeformTechnology($joueur),
            'Les points d une technologie valent son cout cumule divise par mille.'
        );
    }

    public function testMoreLevelsGiveMorePointsAndFollowTheCumulativeCost(): void
    {
        $joueur = $this->joueur();
        $techno = $this->uneTechnologie();

        $this->poser($this->planetService->getPlanetId(), LifeformKind::Technology, $techno, 2);
        $deux = $this->classement()->getPlayerScoreLifeformTechnology($joueur);

        $this->poser($this->planetService->getPlanetId(), LifeformKind::Technology, $techno, 6);
        $six = $this->classement()->getPlayerScoreLifeformTechnology($joueur);

        $this->assertGreaterThan($deux, $six, 'Six niveaux valent plus que deux.');
        $this->assertSame((int)floor($this->coutCumule($techno, 6) / 1000), $six);
        $this->assertSame((int)floor($this->coutCumule($techno, 2) / 1000), $deux);
    }

    public function testTheLifeformTotalIsTheSumOfTheTwoCategories(): void
    {
        $joueur = $this->joueur();
        $this->poser($this->planetService->getPlanetId(), LifeformKind::Building, $this->unBatiment(), 5);
        $this->poser($this->planetService->getPlanetId(), LifeformKind::Technology, $this->uneTechnologie(), 3);

        $economie = $this->classement()->getPlayerScoreLifeformEconomy($joueur);
        $technologie = $this->classement()->getPlayerScoreLifeformTechnology($joueur);

        $this->assertGreaterThan(0, $economie);
        $this->assertGreaterThan(0, $technologie);
        $this->assertSame(
            $economie + $technologie,
            $this->classement()->getPlayerScoreLifeform($joueur),
            'Lifeform = Lifeform Economy + Lifeform Technology, comme l API officielle le montre.'
        );
    }

    /**
     * **Aucune double addition.** Les formes de vie entrent dans le General et nulle part ailleurs : ni dans
     * l Economie classique, ni dans la Recherche classique.
     */
    public function testLifeformPointsEnterTheGeneralButNeverTheClassicEconomyOrResearch(): void
    {
        $joueur = $this->joueur();

        $generalAvant = $this->classement()->getPlayerScore($joueur);
        $economieAvant = $this->classement()->getPlayerScoreEconomy($joueur);
        $rechercheAvant = $this->classement()->getPlayerScoreResearch($joueur);

        $this->poser($this->planetService->getPlanetId(), LifeformKind::Building, $this->unBatiment(), 9);
        $this->poser($this->planetService->getPlanetId(), LifeformKind::Technology, $this->uneTechnologie(), 5);

        $apporte = $this->classement()->getPlayerScoreLifeform($joueur);
        $this->assertGreaterThan(0, $apporte, 'Premisse : les niveaux poses doivent valoir quelque chose.');

        $this->assertSame(
            $generalAvant + $apporte,
            $this->classement()->getPlayerScore($joueur),
            'Le General augmente exactement du total des formes de vie.'
        );
        $this->assertSame($economieAvant, $this->classement()->getPlayerScoreEconomy($joueur), 'L Economie classique ne bouge pas.');
        $this->assertSame($rechercheAvant, $this->classement()->getPlayerScoreResearch($joueur), 'La Recherche classique ne bouge pas.');
    }

    /**
     * Un travail **en attente** ou **annule** n a rien construit : il ne vaut aucun point.
     */
    public function testAQueuedOrCancelledWorkGivesNoPoints(): void
    {
        $joueur = $this->joueur();
        $batiment = $this->unBatiment();

        foreach (['waiting', 'canceled'] as $statut) {
            LifeformQueue::query()->create([
                'planet_id' => $this->planetService->getPlanetId(),
                'user_id' => $joueur->getId(),
                'kind' => LifeformKind::Building->value,
                'object_id' => $batiment->id,
                'target_level' => 12,
                'metal' => 1000000,
                'crystal' => 1000000,
                'deuterium' => 1000000,
                'energy' => 0,
                'time_start' => 1,
                'time_end' => 2,
                'status' => $statut,
                'catalogue_version' => 1,
            ]);
        }

        $this->assertSame(
            0,
            $this->classement()->getPlayerScoreLifeformEconomy($joueur),
            'Seul un NIVEAU ecrit vaut des points : une file, meme payee, n a encore rien bati.'
        );
    }

    public function testTwoConsecutiveComputationsGiveTheSameResult(): void
    {
        $joueur = $this->joueur();
        $this->poser($this->planetService->getPlanetId(), LifeformKind::Building, $this->unBatiment(), 6);

        $premier = $this->classement()->getPlayerScoreLifeform($joueur);
        $second = $this->classement()->getPlayerScoreLifeform($joueur);

        $this->assertSame($premier, $second, 'Le calcul se relit, il ne s accumule pas.');
        $this->assertGreaterThan(0, $premier);
    }

    /**
     * **Provisoire, en attente d une mesure sur un compte OGame.** Les points se calculent aujourd hui sur le cout
     * **nominal**, sans aucune reduction. Ce n est pas une decision : c est l absence de decision, et ce temoin
     * existe pour que personne ne la change sans savoir qu elle etait ouverte.
     */
    public function testProvisionalUntilMeasuredCostReductionsUseTheNominalCost(): void
    {
        $this->assertSame(
            0.0,
            LifeformScoreOpenRules::SCORING_REDUCTION,
            'Tant que la mesure n est pas faite, le score ignore les reductions : ' . LifeformScoreOpenRules::COST_REDUCTIONS
        );

        $joueur = $this->joueur();
        $batiment = $this->unBatiment();
        $this->poser($this->planetService->getPlanetId(), LifeformKind::Building, $batiment, 8);

        $this->assertSame(
            (int)floor($this->coutCumule($batiment, 8) / 1000),
            $this->classement()->getPlayerScoreLifeformEconomy($joueur),
            'La valeur suit le catalogue, pas une facture reduite.'
        );
    }

    /**
     * **Provisoire, en attente d une mesure sur un compte OGame.** Une technologie dont l emplacement a ete
     * reinitialise garde ses niveaux en base ; ils comptent encore. Meme statut : ouvert, nomme, epingle.
     */
    public function testProvisionalUntilMeasuredDispossessedTechnologyStillCounts(): void
    {
        $joueur = $this->joueur();
        $techno = $this->uneTechnologie();
        $this->poser($this->planetService->getPlanetId(), LifeformKind::Technology, $techno, 5);

        // Aucun emplacement ne porte cette technologie : seuls les niveaux existent.
        $this->assertSame(
            (int)floor($this->coutCumule($techno, 5) / 1000),
            $this->classement()->getPlayerScoreLifeformTechnology($joueur),
            'Les niveaux enregistres comptent, emplacement ou non. ' . LifeformScoreOpenRules::DISPOSSESSED_TECHNOLOGY
        );
    }

    public function testSeveralPlanetsAddUpWithoutCountingAnythingTwice(): void
    {
        $joueur = $this->joueur();
        $batiment = $this->unBatiment();

        $premiere = $this->planetService->getPlanetId();
        $this->poser($premiere, LifeformKind::Building, $batiment, 4);
        $uneSeule = $this->classement()->getPlayerScoreLifeformEconomy($joueur);

        $seconde = $this->uneSecondePlanete();
        if ($seconde === null) {
            $this->markTestSkipped('Le compte de banc n a qu une planete : la somme sur plusieurs corps se mesure ailleurs.');
        }

        $this->poser($seconde, LifeformKind::Building, $batiment, 4);
        $joueur = resolve(PlayerServiceFactory::class)->make($joueur->getId(), true);

        $this->assertSame(
            2 * $uneSeule,
            $this->classement()->getPlayerScoreLifeformEconomy($joueur),
            'Deux corps au meme niveau valent exactement le double, jamais plus.'
        );
    }

    /**
     * L identifiant d une seconde planete du compte, s il en existe une.
     */
    private function uneSecondePlanete(): int|null
    {
        $planetes = $this->joueur()->planets->all();

        foreach ($planetes as $planete) {
            if ($planete->getPlanetId() !== $this->planetService->getPlanetId()) {
                return $planete->getPlanetId();
            }
        }

        // Un compte de banc n a parfois qu un corps : l essai le dit et passe son chemin plutot que d en
        // fabriquer un, ce qui melangerait la creation d une planete a la mesure d un score.
        return null;
    }
}
