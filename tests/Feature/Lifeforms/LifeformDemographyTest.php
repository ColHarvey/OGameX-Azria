<?php

namespace Tests\Feature\Lifeforms;

use Illuminate\Support\Facades\Date;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\Combat\LifeformCombatLosses;
use OGame\Lifeforms\Demography\DemographicClock;
use OGame\Lifeforms\Demography\DemographicState;
use OGame\Lifeforms\Demography\LifeformDemography;
use OGame\Lifeforms\Demography\PlanetLifeformProfile;
use OGame\Lifeforms\Rules\LifeformRuleRevisions;
use OGame\Lifeforms\Services\LifeformInstallationService;
use OGame\Lifeforms\Services\LifeformLevels;
use OGame\Lifeforms\Services\LifeformQueueService;
use OGame\Lifeforms\Species;
use OGame\Models\Lifeforms\LifeformAccount;
use OGame\Models\Lifeforms\LifeformBuildingLevel;
use OGame\Models\Lifeforms\LifeformPlanet;
use OGame\Models\Lifeforms\LifeformQueue;
use OGame\Models\Lifeforms\LifeformRuleRevision;
use OGame\Models\Lifeforms\LifeformSpeciesProgress;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use Tests\AccountTestCase;
use Tests\Support\PinsSettings;

/**
 * La relecture demographique : quelle population la planete portait-elle **a un instant** ?
 *
 * ## Pourquoi cette classe existe
 *
 * Un emplacement de recherche ne compte que s il est ouvert, et c est la population qui l ouvre. Le gel
 * d un combat doit donc dater la population, sans quoi une planete qui franchit le seuil entre l arrivee
 * d une flotte et son traitement arme cette flotte retroactivement (relance de Codex, journal §155.11).
 *
 * ## Le risque que ces essais ferment
 *
 * L ecriture (`LifeformPlanetUpdater`) et la relecture (`LifeformDemography`) avancent le meme temps par
 * deux chemins : l une livre les travaux au passage, l autre rejoue sur la file deja reglee. **Deux moteurs
 * de decision qui divergeraient en silence sont exactement ce que le depot interdit** : le premier essai
 * ci-dessous rejoue un passage reel et exige la meme population au flottant pres. Si un jour les deux
 * chemins s ecartent, c est lui qui le dira.
 */
final class LifeformDemographyTest extends AccountTestCase
{
    use PinsSettings;

    private const int RESIDENTIAL = 11101;

    private const int FARM = 11102;

    private int $revisionsAvant = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->revisionsAvant = (int)(LifeformRuleRevision::query()->max('id') ?? 0);
        $this->pinSettings(['lifeforms_enabled' => 1, 'economy_speed' => 8, 'research_speed' => 1]);
        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Humans, (int)Date::now()->timestamp);
        $this->planetAddResources(new Resources(100000, 100000, 100000, 0));
    }

    protected function tearDown(): void
    {
        $planetes = Planet::query()->where('user_id', $this->currentUserId)->pluck('id');
        LifeformQueue::query()->whereIn('planet_id', $planetes)->delete();
        LifeformBuildingLevel::query()->whereIn('planet_id', $planetes)->delete();
        LifeformPlanet::query()->whereIn('planet_id', $planetes)->delete();
        LifeformAccount::query()->where('user_id', $this->currentUserId)->delete();
        LifeformSpeciesProgress::query()->where('user_id', $this->currentUserId)->delete();
        LifeformRuleRevision::query()->where('id', '>', $this->revisionsAvant)->delete();
        $this->restorePinnedSettings();
        parent::tearDown();
    }

    /**
     * **Rejouer un passage reel rend exactement ce que ce passage a ecrit.**
     *
     * Le passage traverse une livraison de ferme et une revision de vitesse : les deux sortes de coupe.
     */
    public function testReplayingARealPassGivesExactlyWhatThatPassWrote(): void
    {
        $debut = (int)Date::now()->timestamp;
        $planetId = $this->planetService->getPlanetId();
        $niveaux = resolve(LifeformLevels::class);
        $niveaux->setLevel($planetId, LifeformKind::Building, self::RESIDENTIAL, 5);
        $revisions = resolve(LifeformRuleRevisions::class);
        $revisions->recordIfChanged($debut, null, 'banc');
        resolve(LifeformQueueService::class)->add($this->planetService, self::FARM, $debut);

        // Une revision de vitesse a mi-chemin, puis le joueur revient deux heures plus tard.
        $this->travelTo(Date::createFromTimestamp($debut + 3600));
        $this->pinSettings(['economy_speed' => 2]);
        $this->assertNotNull($revisions->recordIfChanged($debut + 3600, null, 'banc'));
        $this->travelTo(Date::createFromTimestamp($debut + 7200));
        $this->planetService->update();

        $ligne = LifeformPlanet::query()->where('planet_id', $planetId)->firstOrFail();
        $this->assertSame($debut, (int)$ligne->previous_calculated_at, 'Le passage garde l etat d ou il est parti.');
        $this->assertSame($debut + 7200, (int)$ligne->calculated_at);

        $rejeu = resolve(LifeformDemography::class)->replay(
            $planetId,
            Species::Humans,
            new DemographicState((float)$ligne->previous_population, (float)$ligne->previous_food, (int)$ligne->previous_calculated_at),
            (int)$ligne->calculated_at
        );

        $this->assertEqualsWithDelta((float)$ligne->population, $rejeu->population, 1e-9, 'La relecture et l ecriture ont diverge sur la population.');
        $this->assertEqualsWithDelta((float)$ligne->food, $rejeu->food, 1e-9, 'La relecture et l ecriture ont diverge sur la nourriture.');
    }

    /**
     * **La population d un instant traverse par le passage se relit exactement**, et elle est monotone :
     * plus tard dans le passage, jamais moins.
     */
    public function testThePopulationOfAnInstantInsideThePassIsReadBack(): void
    {
        $debut = (int)Date::now()->timestamp;
        $planetId = $this->planetService->getPlanetId();
        $niveaux = resolve(LifeformLevels::class);
        $niveaux->setLevel($planetId, LifeformKind::Building, self::RESIDENTIAL, 5);
        $niveaux->setLevel($planetId, LifeformKind::Building, self::FARM, 5);
        resolve(LifeformRuleRevisions::class)->recordIfChanged($debut, null, 'banc');

        $lecture = resolve(LifeformDemography::class);
        $milieu = $debut + 3600;
        $this->travelTo(Date::createFromTimestamp($debut + 7200));
        $this->planetService->update();

        $ligne = LifeformPlanet::query()->where('planet_id', $planetId)->firstOrFail();
        $auMilieu = $lecture->populationAt($planetId, $milieu);
        $this->assertNotNull($auMilieu, 'Un instant traverse par le passage doit se relire.');
        $this->assertGreaterThan(210.0, $auMilieu, 'Premisse : la population a bien cru pendant l heure.');
        $this->assertLessThan((float)$ligne->population, $auMilieu, 'A mi-chemin, elle est inferieure a celle de la fin.');
        $this->assertEqualsWithDelta((float)$ligne->population, (float)$lecture->populationAt($planetId, $debut + 7200), 1e-9, 'A la fin du passage, c est la colonne.');
    }

    /**
     * **Un batiment livre au milieu du passage n a pas nourri la population d avant son echeance.**
     *
     * C est la version absolue de la relecture : l instant demande tombe **avant** la livraison de la ferme,
     * et la population qui revient doit etre celle d une planete qui n avait pas encore cette ferme. Comparer
     * l ecriture au rejeu ne suffisait pas — les deux passent par le meme calcul, donc une faute commune y
     * serait invisible. Ici la valeur attendue est calculee a part, a la main de l horloge.
     */
    public function testABuildingDeliveredMidPassDidNotFeedThePopulationBeforeItsDeadline(): void
    {
        $debut = (int)Date::now()->timestamp;
        $planetId = $this->planetService->getPlanetId();
        resolve(LifeformLevels::class)->setLevel($planetId, LifeformKind::Building, self::RESIDENTIAL, 5);
        resolve(LifeformRuleRevisions::class)->recordIfChanged($debut, null, 'banc');

        // La ferme s acheve une heure apres le debut : avant elle, la planete ne nourrit personne.
        $ferme = resolve(LifeformQueueService::class)->add($this->planetService, self::FARM, $debut);
        LifeformQueue::query()->whereKey($ferme->id)->update(['time_end' => $debut + 3600]);

        $this->travelTo(Date::createFromTimestamp($debut + 7200));
        $this->planetService->update();
        $this->assertSame('done', $ferme->refresh()->status, 'Premisse : la ferme a bien ete livree pendant le passage.');

        $horloge = new DemographicClock();
        $sansFerme = $horloge->advance(
            new DemographicState(210.0, 0.0, $debut),
            PlanetLifeformProfile::fromLevels(Species::Humans, [self::RESIDENTIAL => 5], 8.0),
            $debut + 1800
        );

        $lecture = resolve(LifeformDemography::class);
        $this->assertEqualsWithDelta(
            $sansFerme->population,
            (float)$lecture->populationAt($planetId, $debut + 1800),
            1e-6,
            'La relecture a nourri la population avec une ferme qui n existait pas encore.'
        );
        $this->assertGreaterThan(
            (float)$lecture->populationAt($planetId, $debut + 1800),
            (float)$lecture->populationAt($planetId, $debut + 7200),
            'Apres la ferme, la population a repris sa croissance.'
        );
    }

    /**
     * **Une population d avant l instant demande n est pas la population de cet instant** (relance de Codex).
     *
     * Quand l horloge de la planete est en retard sur l instant, rendre la colonne telle quelle revient a dater
     * la reponse de la derniere actualisation, pas de l instant demande. Une colonie qui a franchi son seuil
     * entre les deux n apporterait alors pas son bonus — et le resultat dependrait de la date a laquelle
     * quelqu un a charge une page. **Une valeur ancienne n est pas forcement la bonne valeur.**
     */
    public function testAPopulationOlderThanTheInstantIsNotThePopulationOfThatInstant(): void
    {
        $debut = (int)Date::now()->timestamp;
        $planetId = $this->planetService->getPlanetId();
        $niveaux = resolve(LifeformLevels::class);
        $niveaux->setLevel($planetId, LifeformKind::Building, self::RESIDENTIAL, 45);
        $niveaux->setLevel($planetId, LifeformKind::Building, self::FARM, 48);
        resolve(LifeformRuleRevisions::class)->recordIfChanged($debut, null, 'banc');

        $profil = PlanetLifeformProfile::fromLevels(Species::Humans, $niveaux->buildingLevelsOf($planetId), 8.0);
        LifeformPlanet::query()->where('planet_id', $planetId)->update([
            'population' => 1000000.0,
            'food' => $profil->foodStorage,
            'calculated_at' => $debut,
            'previous_population' => 1000000.0,
            'previous_food' => $profil->foodStorage,
            'previous_calculated_at' => $debut,
        ]);

        $attendu = (new DemographicClock())->advance(
            new DemographicState(1000000.0, $profil->foodStorage, $debut),
            $profil,
            $debut + 600
        );
        $this->assertGreaterThan(1000000.0, $attendu->population, 'Premisse : la population croit sur ces dix minutes.');

        $this->assertEqualsWithDelta(
            $attendu->population,
            (float)resolve(LifeformDemography::class)->populationAt($planetId, $debut + 600),
            1e-6,
            'La relecture rend la population de la derniere actualisation au lieu de celle de l instant demande.'
        );
    }

    /**
     * **Des habitants morts au combat ne reviennent pas par la relecture** (relance de Codex).
     *
     * Les pertes civiles diminuent la population sans etre un evenement que l horloge connait : la relecture
     * depuis l ancre ne rejoue que les travaux et les revisions de vitesse. Pour un instant posterieur a une
     * attaque meurtriere, elle retrouverait donc des habitants morts, et rendrait leurs bonus.
     */
    public function testTheDeadOfABattleDoNotComeBackThroughTheReplay(): void
    {
        $debut = (int)Date::now()->timestamp;
        $planetId = $this->planetService->getPlanetId();
        $niveaux = resolve(LifeformLevels::class);
        $niveaux->setLevel($planetId, LifeformKind::Building, self::RESIDENTIAL, 45);
        $niveaux->setLevel($planetId, LifeformKind::Building, self::FARM, 48);
        resolve(LifeformRuleRevisions::class)->recordIfChanged($debut, null, 'banc');

        $profil = PlanetLifeformProfile::fromLevels(Species::Humans, $niveaux->buildingLevelsOf($planetId), 8.0);
        LifeformPlanet::query()->where('planet_id', $planetId)->update([
            'population' => 1000000.0,
            'food' => $profil->foodStorage,
            'calculated_at' => $debut,
            'previous_population' => 1000000.0,
            'previous_food' => $profil->foodStorage,
            'previous_calculated_at' => $debut,
        ]);

        // **Le monde a d abord avance jusqu a la fin de la fenetre** : l ancre reste au depart, l horloge est
        // loin devant. C est l etat ordinaire d une planete dont quelqu un a charge une page.
        $fin = $debut + 1200;
        $this->travelTo(Date::createFromTimestamp($fin));
        $this->planetService->update();
        $ligne = LifeformPlanet::query()->where('planet_id', $planetId)->firstOrFail();
        $this->assertSame($debut, (int)$ligne->previous_calculated_at, 'Premisse : l ancre precede la bataille.');
        $this->assertSame($fin, (int)$ligne->calculated_at);

        // **Une attaque reussie a un instant deja depasse par l horloge** — un combat durable se regle a son
        // echeance, que la planete a pu franchir entre-temps. Sans Bouclier, seul l abri survit.
        $chasseur = ObjectService::getShipObjectByMachineName('light_fighter');
        $victoire = new BattleResult();
        $victoire->attackerUnitsResult = new UnitCollection();
        $victoire->attackerUnitsResult->addUnit($chasseur, 10);
        $victoire->defenderUnitsResult = new UnitCollection();
        $mort = $debut + 600;
        $perdus = resolve(LifeformCombatLosses::class)->applyIfAttackerWon($victoire, $this->planetService, 0.0, $mort);
        $this->assertGreaterThan(0, $perdus, 'Premisse : l attaque a bien tue des habitants.');
        $this->assertLessThan(1000.0, (float)LifeformPlanet::query()->where('planet_id', $planetId)->value('population'), 'Premisse : il ne reste que l abri.');

        // **La question qui doit ne jamais ressusciter personne** : un instant posterieur a l attaque, mais
        // anterieur a l horloge — donc rejoue depuis l ancre, qui ne connait pas la bataille.
        $apres = resolve(LifeformDemography::class)->populationAt($planetId, $mort + 60);
        $this->assertTrue(
            $apres === null || $apres < 10000.0,
            'La relecture a ressuscite les habitants tues : elle rejoue la croissance depuis une ancre d avant la bataille. Rendu : ' . var_export($apres, true)
        );
    }

    /**
     * **Un instant que l horloge n a pas encore atteint est rejoue en avant** — la colonne n est pas la reponse ;
     * **un instant saute sans instantane rend null**, et l appelant le dit au lieu de deviner.
     */
    public function testAnInstantTheClockHasNotReachedIsReplayedAndAnUnreachableOneSaysSo(): void
    {
        $debut = (int)Date::now()->timestamp;
        $planetId = $this->planetService->getPlanetId();
        $lecture = resolve(LifeformDemography::class);

        LifeformPlanet::query()->where('planet_id', $planetId)->update([
            'population' => 4242.0,
            'calculated_at' => $debut,
            'previous_population' => null,
            'previous_food' => null,
            'previous_calculated_at' => null,
        ]);
        $this->assertSame(4242.0, $lecture->populationAt($planetId, $debut), 'A l instant exact du calcul, c est la colonne.');

        // **Cinq cents secondes plus loin, la colonne n est plus la reponse** : l horloge est rejouee jusque la.
        // Sur une planete sans logement ni ferme, quatre mille habitants ne tiennent pas — ils redescendent a la
        // population de base, et c est bien ce que la planete portait a cet instant.
        $attendu = (new DemographicClock())->advance(
            new DemographicState(4242.0, 0.0, $debut),
            PlanetLifeformProfile::fromLevels(Species::Humans, resolve(LifeformLevels::class)->buildingLevelsOf($planetId), 8.0),
            $debut + 500
        );
        $this->assertNotEqualsWithDelta(4242.0, $attendu->population, 1.0, 'Premisse : la population de cet instant differe de celle de la colonne.');
        $this->assertEqualsWithDelta($attendu->population, (float)$lecture->populationAt($planetId, $debut + 500), 1e-9, 'La colonne a ete rendue telle quelle au lieu d etre rejouee.');

        // L horloge a depasse l instant, et l instantane du dernier passage ne le couvre pas.
        LifeformPlanet::query()->where('planet_id', $planetId)->update([
            'calculated_at' => $debut + 1000,
            'previous_population' => 4000.0,
            'previous_food' => 0.0,
            'previous_calculated_at' => $debut + 900,
        ]);
        $this->assertNull($lecture->populationAt($planetId, $debut + 500), 'Un instant plus ancien que l instantane ne se reconstitue pas : le dire, pas le deviner.');
        $this->assertNotNull($lecture->populationAt($planetId, $debut + 950), 'Un instant couvert par l instantane se reconstitue.');
    }
}
