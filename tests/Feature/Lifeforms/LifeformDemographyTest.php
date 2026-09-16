<?php

namespace Tests\Feature\Lifeforms;

use Illuminate\Support\Facades\Date;
use OGame\Combat\Exceptions\UnknownAdmissionHistory;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Lifeforms\Bonuses\LifeformBonusCache;
use OGame\Lifeforms\Bonuses\LifeformBonusResolver;
use OGame\Lifeforms\Catalogue\LifeformCatalogue;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\Combat\LifeformCombatLosses;
use OGame\Lifeforms\Demography\DemographicClock;
use OGame\Lifeforms\Demography\DemographicRules;
use OGame\Lifeforms\Demography\DemographicState;
use OGame\Lifeforms\Demography\LifeformDemography;
use OGame\Lifeforms\Demography\PlanetLifeformProfile;
use OGame\Lifeforms\Rules\LifeformRuleRevisions;
use OGame\Lifeforms\Services\LifeformInstallationService;
use OGame\Lifeforms\Services\LifeformLevels;
use OGame\Lifeforms\Services\LifeformPlanetUpdater;
use OGame\Lifeforms\Services\LifeformQueueService;
use OGame\Lifeforms\Species;
use OGame\Models\Lifeforms\LifeformAccount;
use OGame\Models\Lifeforms\LifeformBuildingLevel;
use OGame\Models\Lifeforms\LifeformPlanet;
use OGame\Models\Lifeforms\LifeformQueue;
use OGame\Models\Lifeforms\LifeformRuleRevision;
use OGame\Models\Lifeforms\LifeformSlot;
use OGame\Models\Lifeforms\LifeformSlotChange;
use OGame\Models\Lifeforms\LifeformSpeciesProgress;
use OGame\Models\Lifeforms\LifeformTechnologyLevel;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use Tests\AccountTestCase;
use Tests\Support\PinsSettings;
use Tests\Support\PlacesLifeformSlots;

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
    use PlacesLifeformSlots;

    private const int RESIDENTIAL = 11101;

    private const int FARM = 11102;

    private const int RESEARCH_CENTRE = 11103;

    private const int ENVOYS = 11201;

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
        LifeformSlot::query()->whereIn('planet_id', $planetes)->delete();
        LifeformSlotChange::query()->whereIn('planet_id', $planetes)->delete();
        LifeformTechnologyLevel::query()->whereIn('planet_id', $planetes)->delete();
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

        // **Les morts sont morts a l instant de la bataille, et les survivants recroissent depuis la.** La
        // population a l horloge vaut la croissance de l abri de cent habitants sur les dix minutes qui restaient,
        // calculee a part ; et un instant posterieur a l attaque mais anterieur a l horloge se rejoue depuis les
        // survivants, jamais depuis une ancre d avant la bataille — c est la question qui ne doit ressusciter
        // personne.
        $horloge = new DemographicClock();
        $survivants = new DemographicState((float)DemographicRules::SHELTERED, $profil->foodStorage, $mort);
        $attenduAlHorloge = $horloge->advance($survivants, $profil, $fin);
        $this->assertEqualsWithDelta($attenduAlHorloge->population, (float)LifeformPlanet::query()->where('planet_id', $planetId)->value('population'), 1e-6, 'La population a l horloge n est pas celle des survivants recrus depuis la bataille.');
        $apres = resolve(LifeformDemography::class)->populationAt($planetId, $mort + 60);
        $this->assertNotNull($apres, 'Un instant de la fenetre d apres la bataille reste lisible.');
        $this->assertEqualsWithDelta($horloge->advance($survivants, $profil, $mort + 60)->population, (float)$apres, 1e-6, 'La relecture a ressuscite les habitants tues : elle rejoue la croissance depuis une ancre d avant la bataille.');
        $this->assertNull(resolve(LifeformDemography::class)->populationAt($planetId, $mort - 60), 'Un instant d avant la bataille n est plus reconstituable : refuse, pas devine.');
    }

    /**
     * **Le temoin decisif** (relance de Codex) : une bataille reglee a l heure, puis dix minutes de croissance des
     * survivants, doit donner **exactement** la meme planete que cette bataille reglee dix minutes en retard.
     *
     * Un reglement tardif doit donc retrouver la population de l instant de la bataille, y appliquer les pertes,
     * puis recalculer la croissance des survivants jusqu a l horloge. Deplacer l ancre ne suffit pas si les pertes
     * sont prises sur la population de l horloge, ou si celle-ci est ensuite redatee a l instant de la bataille.
     * Population, nourriture, bonus et pertes annoncees doivent coincider.
     */
    public function testABattleSettledLateGivesTheSamePlanetAsABattleSettledOnTime(): void
    {
        $debut = (int)Date::now()->timestamp;
        $planetId = $this->planetService->getPlanetId();
        $niveaux = resolve(LifeformLevels::class);
        $niveaux->setLevel($planetId, LifeformKind::Building, self::RESIDENTIAL, 30);
        $niveaux->setLevel($planetId, LifeformKind::Building, self::FARM, 35);
        $niveaux->setLevel($planetId, LifeformKind::Building, self::RESEARCH_CENTRE, 1);
        resolve(LifeformRuleRevisions::class)->recordIfChanged($debut, null, 'banc');
        $this->placeLifeformSlot($planetId, 1, self::ENVOYS, $debut - 10);
        $niveaux->setLevel($planetId, LifeformKind::Technology, self::ENVOYS, 3);

        $profil = PlanetLifeformProfile::fromLevels(Species::Humans, $niveaux->buildingLevelsOf($planetId), 8.0);
        $depart = [
            'population' => 300000.0,
            'food' => $profil->foodStorage,
            'calculated_at' => $debut,
            'previous_population' => 300000.0,
            'previous_food' => $profil->foodStorage,
            'previous_calculated_at' => $debut,
        ];
        $chasseur = ObjectService::getShipObjectByMachineName('light_fighter');
        $victoire = new BattleResult();
        $victoire->attackerUnitsResult = new UnitCollection();
        $victoire->attackerUnitsResult->addUnit($chasseur, 10);
        $victoire->defenderUnitsResult = new UnitCollection();
        $pertes = resolve(LifeformCombatLosses::class);
        $lecture = resolve(LifeformDemography::class);

        // **A l heure** : la bataille a l instant, puis dix minutes de croissance des survivants.
        LifeformPlanet::query()->where('planet_id', $planetId)->update($depart);
        LifeformBonusCache::invalidate();
        $this->travelTo(Date::createFromTimestamp($debut));
        $pertesALHeure = $pertes->applyIfAttackerWon($victoire, $this->planetService, 0.5, $debut);
        $this->assertSame(150000, $pertesALHeure, 'Premisse : la moitie perit a l heure.');
        $this->travelTo(Date::createFromTimestamp($debut + 600));
        $this->planetService->update();
        $aLHeure = $this->planetPhotograph($planetId, $debut + 300);

        // **En retard** : la planete a deja avance de dix minutes quand la bataille s applique a son instant.
        LifeformPlanet::query()->where('planet_id', $planetId)->update($depart);
        LifeformBonusCache::invalidate();
        $this->travelTo(Date::createFromTimestamp($debut + 600));
        $this->planetService->update();
        $this->assertSame($debut + 600, (int)LifeformPlanet::query()->where('planet_id', $planetId)->value('calculated_at'), 'Premisse : l horloge a depasse la bataille.');
        $pertesEnRetard = $pertes->applyIfAttackerWon($victoire, $this->planetService, 0.5, $debut);
        $enRetard = $this->planetPhotograph($planetId, $debut + 300);

        $this->assertSame($pertesALHeure, $pertesEnRetard, 'Les pertes annoncees ne sont pas celles de l instant de la bataille.');
        $this->assertEqualsWithDelta($aLHeure['population'], $enRetard['population'], 1e-6, 'Reglee en retard, la bataille ne donne pas la meme population : les pertes ont ete prises sur la population de l horloge, ou les survivants n ont pas recru.');
        $this->assertEqualsWithDelta($aLHeure['food'], $enRetard['food'], 1e-6, 'La nourriture diverge entre les deux reglements.');
        $this->assertSame($aLHeure['calculated_at'], $enRetard['calculated_at']);
        $this->assertEqualsWithDelta($aLHeure['au_milieu'], $enRetard['au_milieu'], 1e-6, 'La population relue au milieu de la fenetre diverge : l ancre n est pas datee de la bataille.');
        $this->assertSame($aLHeure['bonus'], $enRetard['bonus'], 'Les bonus de technologies divergent entre les deux reglements.');
    }

    /**
     * **Un reglement tardif dont l instant n est plus reconstituable ne devine pas** : il leve l anomalie que le
     * socle des combats sait suspendre, et n ecrit rien.
     */
    public function testALateSettlementBeyondTheWindowRefusesInsteadOfGuessing(): void
    {
        $debut = (int)Date::now()->timestamp;
        $planetId = $this->planetService->getPlanetId();
        $niveaux = resolve(LifeformLevels::class);
        $niveaux->setLevel($planetId, LifeformKind::Building, self::RESIDENTIAL, 30);
        $niveaux->setLevel($planetId, LifeformKind::Building, self::FARM, 35);
        resolve(LifeformRuleRevisions::class)->recordIfChanged($debut, null, 'banc');
        $profil = PlanetLifeformProfile::fromLevels(Species::Humans, $niveaux->buildingLevelsOf($planetId), 8.0);

        // Deux passages apres la bataille : l ancre est passee au-dela de son instant.
        LifeformPlanet::query()->where('planet_id', $planetId)->update([
            'population' => 300000.0, 'food' => $profil->foodStorage, 'calculated_at' => $debut,
            'previous_population' => 300000.0, 'previous_food' => $profil->foodStorage, 'previous_calculated_at' => $debut,
        ]);
        $passage = resolve(LifeformPlanetUpdater::class);
        $passage->update($this->planetService, $debut + 300);
        $passage->update($this->planetService, $debut + 600);
        $avant = LifeformPlanet::query()->where('planet_id', $planetId)->firstOrFail()->only(['population', 'food', 'calculated_at', 'previous_population', 'previous_food', 'previous_calculated_at']);
        $this->assertGreaterThan($debut, (int)$avant['previous_calculated_at'], 'Premisse : l instant de la bataille est sorti de la fenetre.');

        $chasseur = ObjectService::getShipObjectByMachineName('light_fighter');
        $victoire = new BattleResult();
        $victoire->attackerUnitsResult = new UnitCollection();
        $victoire->attackerUnitsResult->addUnit($chasseur, 10);
        $victoire->defenderUnitsResult = new UnitCollection();

        try {
            resolve(LifeformCombatLosses::class)->applyIfAttackerWon($victoire, $this->planetService, 0.5, $debut);
            $this->fail('Un reglement tardif hors fenetre a decide au lieu de se suspendre.');
        } catch (UnknownAdmissionHistory $anomalie) {
            $this->assertStringContainsString((string)$planetId, $anomalie->getMessage());
        }
        $this->assertSame($avant, LifeformPlanet::query()->where('planet_id', $planetId)->firstOrFail()->only(array_keys($avant)), 'Un reglement refuse ne doit rien ecrire.');
    }

    /**
     * Population, nourriture, horloge, population relue au milieu de la fenetre, et bonus de technologies.
     *
     * @return array{population: float, food: float, calculated_at: int, au_milieu: float, bonus: array<string, float>}
     */
    private function planetPhotograph(int $planetId, int $milieu): array
    {
        LifeformBonusCache::invalidate();
        $ligne = LifeformPlanet::query()->where('planet_id', $planetId)->firstOrFail();
        $bonus = [];
        $jeu = resolve(LifeformBonusResolver::class)->forPlayer($this->currentUserId);
        foreach (LifeformCatalogue::byId(self::ENVOYS)->bonuses as $b) {
            $bonus[$b->code . '/' . ($b->target ?? '')] = round($jeu->fraction($b->code, $b->target), 12);
        }
        $auMilieu = resolve(LifeformDemography::class)->populationAt($planetId, $milieu);
        $this->assertNotNull($auMilieu, 'Le milieu de la fenetre doit rester lisible.');

        return [
            'population' => (float)$ligne->population,
            'food' => (float)$ligne->food,
            'calculated_at' => (int)$ligne->calculated_at,
            'au_milieu' => (float)$auMilieu,
            'bonus' => $bonus,
        ];
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
