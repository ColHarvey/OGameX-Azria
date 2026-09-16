<?php

namespace Tests\Feature\Lifeforms;

use Illuminate\Support\Facades\Date;
use OGame\Lifeforms\Catalogue\LifeformCatalogue;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\Demography\DemographicClock;
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
use Tests\AccountTestCase;
use Tests\Support\PinsSettings;
use Tests\Support\PlacesLifeformSlots;

/**
 * La mise a jour d une planete peuplee, par `PlanetService::update()` : l horloge coupe aux
 * revisions de vitesse et aux echeances des travaux, et ne fait jamais deux fois la meme chose.
 */
final class LifeformPlanetUpdaterTest extends AccountTestCase
{
    use PinsSettings;
    use PlacesLifeformSlots;

    private const int RESIDENTIAL = 11101;

    private const int FARM = 11102;

    private const int RESEARCH_CENTRE = 11103;

    private const int ACADEMY = 11104;

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

    public function testGrowthIsIntegratedPieceByPieceAroundASpeedRevision(): void
    {
        $debut = (int)Date::now()->timestamp;
        $niveaux = resolve(LifeformLevels::class);
        $niveaux->setLevel($this->planetService->getPlanetId(), LifeformKind::Building, self::RESIDENTIAL, 5);
        $niveaux->setLevel($this->planetService->getPlanetId(), LifeformKind::Building, self::FARM, 5);
        $revisions = resolve(LifeformRuleRevisions::class);
        $revisions->recordIfChanged($debut, null, 'banc');

        // Une heure plus tard, l economie passe de x8 a x2 ; deux heures plus tard, la page se charge.
        $this->travelTo(Date::createFromTimestamp($debut + 3600));
        $this->pinSettings(['economy_speed' => 2]);
        $this->assertNotNull($revisions->recordIfChanged($debut + 3600, null, 'banc'));
        $this->travelTo(Date::createFromTimestamp($debut + 7200));

        $this->planetService->update();

        $horloge = new DemographicClock();
        $depart = new DemographicState(210.0, 0.0, $debut);
        $levels = [self::RESIDENTIAL => 5, self::FARM => 5];
        $attendu = $horloge->advance($depart, PlanetLifeformProfile::fromLevels(Species::Humans, $levels, 8.0), $debut + 3600);
        $attendu = $horloge->advance($attendu, PlanetLifeformProfile::fromLevels(Species::Humans, $levels, 2.0), $debut + 7200);
        $tout = $horloge->advance($depart, PlanetLifeformProfile::fromLevels(Species::Humans, $levels, 2.0), $debut + 7200);

        $etat = LifeformPlanet::query()->where('planet_id', $this->planetService->getPlanetId())->firstOrFail();
        $this->assertEqualsWithDelta($attendu->population, $etat->population, 1e-6, 'Une heure a x8 puis une heure a x2.');
        $this->assertEqualsWithDelta($attendu->food, $etat->food, 1e-6);
        $this->assertSame($debut + 7200, $etat->calculated_at);
        $this->assertNotEqualsWithDelta($tout->population, $etat->population, 1e-3, 'Appliquer x2 a toute l absence aurait donne autre chose.');

        // Recharger la page ne refait rien.
        $this->planetService->update();
        $this->assertEqualsWithDelta($attendu->population, LifeformPlanet::query()->where('planet_id', $this->planetService->getPlanetId())->value('population'), 1e-6);
    }

    public function testADeliveredFarmChangesTheRatesFromItsDeadlineOn(): void
    {
        $debut = (int)Date::now()->timestamp;
        // Un logement de niveau 2 offre 922 places : sans ferme, les 210 de base ne croissent pas.
        resolve(LifeformLevels::class)->setLevel($this->planetService->getPlanetId(), LifeformKind::Building, self::RESIDENTIAL, 2);
        $element = resolve(LifeformQueueService::class)->add($this->planetService, self::FARM, $debut);
        $this->assertSame($debut + 6, $element->time_end, 'A x8 : 40 × 1,25 ÷ 8 = 6,25 → 6 s.');

        $this->travelTo(Date::createFromTimestamp($debut + 3606));
        $this->planetService->update();

        $horloge = new DemographicClock();
        $sansFerme = $horloge->advance(new DemographicState(210.0, 0.0, $debut), PlanetLifeformProfile::fromLevels(Species::Humans, [self::RESIDENTIAL => 2], 8.0), $debut + 6);
        $this->assertSame(210.0, $sansFerme->population, 'Sans ferme, rien ne croit.');
        $attendu = $horloge->advance($sansFerme, PlanetLifeformProfile::fromLevels(Species::Humans, [self::RESIDENTIAL => 2, self::FARM => 1], 8.0), $debut + 3606);

        $etat = LifeformPlanet::query()->where('planet_id', $this->planetService->getPlanetId())->firstOrFail();
        $this->assertSame('done', $element->refresh()->status);
        $this->assertGreaterThan(210.0, $etat->population, 'La ferme livree a nourri la croissance.');
        $this->assertEqualsWithDelta($attendu->population, $etat->population, 1e-6, 'Six secondes sans ferme, puis une heure avec.');
        $this->assertEqualsWithDelta($attendu->food, $etat->food, 1e-6);
    }

    /**
     * **Un travail dont l heure est passee avant meme le debut du passage est livre, sans effet retroactif.**
     *
     * Les vacances avancent `calculated_at` sans rien livrer : au retour, la file porte un travail dont
     * l echeance precede le depart du passage. Il doit etre livre — sinon il reste en file pour toujours —
     * mais il ne peut pas nourrir une population deja integree : son apport ne compte qu a partir du depart.
     */
    public function testAWorkAlreadyOverdueWhenThePassBeginsIsDeliveredWithoutActingOnThePast(): void
    {
        $debut = (int)Date::now()->timestamp;
        $planetId = $this->planetService->getPlanetId();
        resolve(LifeformLevels::class)->setLevel($planetId, LifeformKind::Building, self::RESIDENTIAL, 2);
        $ferme = resolve(LifeformQueueService::class)->add($this->planetService, self::FARM, $debut);
        $this->assertSame($debut + 6, (int)$ferme->time_end, 'Premisse : la ferme s acheve six secondes apres.');

        // Le compte revient de vacances : l horloge a ete avancee d une heure sans rien livrer.
        LifeformPlanet::query()->where('planet_id', $planetId)->update([
            'population' => 210.0,
            'food' => 0.0,
            'calculated_at' => $debut + 3600,
        ]);
        $this->assertSame('running', $ferme->refresh()->status, 'Premisse : la ferme est echue et toujours en file.');

        $this->travelTo(Date::createFromTimestamp($debut + 7200));
        $this->planetService->update();

        $this->assertSame('done', $ferme->refresh()->status, 'Un travail echu avant le depart du passage n a jamais ete livre.');
        $this->assertSame(1, resolve(LifeformLevels::class)->levelOf($planetId, LifeformKind::Building, self::FARM));

        $attendu = (new DemographicClock())->advance(
            new DemographicState(210.0, 0.0, $debut + 3600),
            PlanetLifeformProfile::fromLevels(Species::Humans, [self::RESIDENTIAL => 2, self::FARM => 1], 8.0),
            $debut + 7200
        );
        $etat = LifeformPlanet::query()->where('planet_id', $planetId)->firstOrFail();
        $this->assertEqualsWithDelta($attendu->population, $etat->population, 1e-6, 'La ferme en retard n apporte rien avant le depart du passage, et tout apres.');
        $this->assertEqualsWithDelta($attendu->food, $etat->food, 1e-6);
    }

    /**
     * **Un rattrapage ne laisse aucun travail echu derriere lui** (revue de Codex).
     *
     * Le joueur s absente ; deux fermes s enchainent pendant ce temps. La premiere, livree a son echeance,
     * demarre la seconde **a cette echeance** — qui devient echue a son tour dans le meme passage. Si la
     * boucle des coupes ne consomme pas les echeances ajoutees en cours de route, la seconde reste en file
     * alors que son heure est passee, et la demographie a ete integree sans la nourriture qu elle apportait.
     */
    public function testACatchUpDeliversEveryWorkChainedDuringTheAbsence(): void
    {
        $debut = (int)Date::now()->timestamp;
        $planetId = $this->planetService->getPlanetId();
        resolve(LifeformLevels::class)->setLevel($planetId, LifeformKind::Building, self::RESIDENTIAL, 2);
        $file = resolve(LifeformQueueService::class);
        $premiere = $file->add($this->planetService, self::FARM, $debut);
        $seconde = $file->add($this->planetService, self::FARM, $debut);
        $this->assertSame('running', $premiere->status, 'Premisse : la premiere ferme court.');
        $this->assertSame('waiting', $seconde->status, 'Premisse : la seconde attend derriere elle.');

        // Le joueur revient une heure plus tard : les deux fermes ont eu le temps de se construire.
        $this->travelTo(Date::createFromTimestamp($debut + 3606));
        $this->planetService->update();

        $this->assertSame('done', $seconde->refresh()->status, 'La seconde ferme est restee en file alors que son heure etait passee.');
        $this->assertCount(0, $file->dueItems($planetId, $debut + 3606), 'Un travail echu est reste derriere le rattrapage.');
        $this->assertSame(2, resolve(LifeformLevels::class)->levelOf($planetId, LifeformKind::Building, self::FARM), 'Les deux niveaux devaient etre portes.');

        // Et la demographie a bien ete integree en trois morceaux, chacun avec les taux de son moment.
        $horloge = new DemographicClock();
        $finPremiere = (int)$premiere->refresh()->time_end;
        $finSeconde = (int)$seconde->time_end;
        $etape = $horloge->advance(new DemographicState(210.0, 0.0, $debut), PlanetLifeformProfile::fromLevels(Species::Humans, [self::RESIDENTIAL => 2], 8.0), $finPremiere);
        $etape = $horloge->advance($etape, PlanetLifeformProfile::fromLevels(Species::Humans, [self::RESIDENTIAL => 2, self::FARM => 1], 8.0), $finSeconde);
        $attendu = $horloge->advance($etape, PlanetLifeformProfile::fromLevels(Species::Humans, [self::RESIDENTIAL => 2, self::FARM => 2], 8.0), $debut + 3606);

        $etat = LifeformPlanet::query()->where('planet_id', $planetId)->firstOrFail();
        $this->assertEqualsWithDelta($attendu->population, $etat->population, 1e-6, 'La population n a pas ete integree avec la seconde ferme.');
        $this->assertEqualsWithDelta($attendu->food, $etat->food, 1e-6);
    }

    /**
     * **La borne conserve la derniere echeance reellement traitee** (relance de Codex).
     *
     * Si un passage s arrete avant d avoir tout traite, il ne doit pas ecrire « calcule jusqu a maintenant » :
     * la croissance du reste de la periode et les livraisons qui restaient auraient disparu ensemble. Il
     * s arrete ou il en est, et le passage suivant reprend exactement la — **deux passages valent un**.
     *
     * La borne du jeu se derive des donnees et reste hors d atteinte ; le banc la baisse a un tour pour voir
     * la garde tomber, sur le vrai code et le vrai algorithme.
     */
    public function testAStoppedPassKeepsItsClockOnTheLastDeadlineItProcessed(): void
    {
        $debut = (int)Date::now()->timestamp;
        $planetId = $this->planetService->getPlanetId();
        resolve(LifeformLevels::class)->setLevel($planetId, LifeformKind::Building, self::RESIDENTIAL, 2);
        $file = resolve(LifeformQueueService::class);
        $premiere = $file->add($this->planetService, self::FARM, $debut);
        $seconde = $file->add($this->planetService, self::FARM, $debut);
        $this->travelTo(Date::createFromTimestamp($debut + 3606));

        // Un passage borne a un seul tour : il livre la premiere ferme et s arrete.
        $borne = new LifeformPlanetUpdater(
            resolve(LifeformQueueService::class),
            resolve(LifeformRuleRevisions::class),
            resolve(LifeformDemography::class),
            1
        );
        $borne->update($this->planetService, $debut + 3606);

        $finPremiere = (int)$premiere->refresh()->time_end;
        $etat = LifeformPlanet::query()->where('planet_id', $planetId)->firstOrFail();
        $this->assertSame('done', $premiere->status, 'Le tour unique a livre la premiere ferme.');
        $this->assertSame('running', $seconde->refresh()->status, 'La seconde reste echue : c est le cas que la garde protege.');
        $this->assertSame($finPremiere, (int)$etat->calculated_at, 'L horloge a saute jusqu a maintenant en laissant un travail echu derriere elle.');

        // **Le passage suivant reprend, et rien n est perdu** : meme etat qu un passage unique et complet.
        resolve(LifeformPlanetUpdater::class)->update($this->planetService, $debut + 3606);
        $reprise = LifeformPlanet::query()->where('planet_id', $planetId)->firstOrFail();
        $this->assertSame('done', $seconde->refresh()->status, 'La reprise a livre ce qui restait.');
        $this->assertSame($debut + 3606, (int)$reprise->calculated_at);
        $this->assertSame(2, resolve(LifeformLevels::class)->levelOf($planetId, LifeformKind::Building, self::FARM));

        $horloge = new DemographicClock();
        $finSeconde = (int)$seconde->time_end;
        $etape = $horloge->advance(new DemographicState(210.0, 0.0, $debut), PlanetLifeformProfile::fromLevels(Species::Humans, [self::RESIDENTIAL => 2], 8.0), $finPremiere);
        $etape = $horloge->advance($etape, PlanetLifeformProfile::fromLevels(Species::Humans, [self::RESIDENTIAL => 2, self::FARM => 1], 8.0), $finSeconde);
        $attendu = $horloge->advance($etape, PlanetLifeformProfile::fromLevels(Species::Humans, [self::RESIDENTIAL => 2, self::FARM => 2], 8.0), $debut + 3606);
        $this->assertEqualsWithDelta($attendu->population, $reprise->population, 1e-6, 'Deux passages ne valent pas un : de la croissance a ete perdue en chemin.');
        $this->assertEqualsWithDelta($attendu->food, $reprise->food, 1e-6);
    }

    /**
     * **Un travail demarre pendant le rattrapage voit la population de son echeance** (relance de Codex).
     *
     * Le passage avance la population **en memoire** et ne l ecrit qu a la fin. Entre-temps, une livraison
     * demarre le travail suivant, dont les prerequis se lisaient en base — donc sur la population d **avant**
     * l absence. Un travail pouvait etre annule pour population insuffisante alors que le seuil avait ete
     * franchi pendant l absence, et l inverse etait vrai aussi.
     */
    public function testAWorkStartedDuringTheCatchUpSeesThePopulationOfItsDeadline(): void
    {
        $debut = (int)Date::now()->timestamp;
        $planetId = $this->planetService->getPlanetId();
        $niveaux = resolve(LifeformLevels::class);
        $niveaux->setLevel($planetId, LifeformKind::Building, self::RESIDENTIAL, 45);
        $niveaux->setLevel($planetId, LifeformKind::Building, self::FARM, 52);
        $niveaux->setLevel($planetId, LifeformKind::Building, self::RESEARCH_CENTRE, 1);

        $profil = PlanetLifeformProfile::fromLevels(Species::Humans, $niveaux->buildingLevelsOf($planetId), 8.0);
        LifeformPlanet::query()->where('planet_id', $planetId)->update([
            'population' => 21000000.0,
            'food' => $profil->foodStorage,
            'calculated_at' => $debut,
            'previous_population' => 21000000.0,
            'previous_food' => $profil->foodStorage,
            'previous_calculated_at' => $debut,
        ]);
        $this->planetAddResources(new Resources(100000000, 100000000, 100000000, 0));

        // **L Academie des sciences** : vingt millions d habitants au premier niveau, vingt-deux au second. Les
        // deux travaux ont ete inscrits quand ils etaient admissibles ; c est leur demarrage pendant le
        // rattrapage qui est en cause.
        LifeformQueue::query()->create([
            'planet_id' => $planetId, 'user_id' => $this->currentUserId, 'kind' => LifeformKind::Building->value,
            'object_id' => self::ACADEMY, 'target_level' => 1, 'metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'energy' => 0,
            'time_start' => $debut, 'time_end' => $debut + 60, 'status' => 'running',
            'catalogue_version' => LifeformCatalogue::VERSION,
        ]);
        $second = LifeformQueue::query()->create([
            'planet_id' => $planetId, 'user_id' => $this->currentUserId, 'kind' => LifeformKind::Building->value,
            'object_id' => self::ACADEMY, 'target_level' => 2, 'metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'energy' => 0,
            'time_start' => null, 'time_end' => null, 'status' => 'waiting',
            'catalogue_version' => LifeformCatalogue::VERSION,
        ]);
        // **A l echeance du premier travail la population a depasse le seuil**, alors que la colonne, elle,
        // porte encore les vingt et un millions d avant l absence.
        $aLEcheance = (new DemographicClock())->advance(
            new DemographicState(21000000.0, $profil->foodStorage, $debut),
            PlanetLifeformProfile::fromLevels(Species::Humans, $niveaux->buildingLevelsOf($planetId), 8.0),
            $debut + 60
        );
        $this->assertGreaterThan(22000000.0, $aLEcheance->population, 'Premisse : le seuil du second niveau est franchi a l echeance du premier.');
        $this->assertSame(21000000, (int)LifeformPlanet::query()->where('planet_id', $planetId)->value('population'), 'Premisse : la colonne est restee en arriere.');

        // Le joueur revient une heure plus tard : la population a largement passe les vingt-deux millions.
        $this->travelTo(Date::createFromTimestamp($debut + 3600));
        $this->planetService->update();

        $this->assertSame(
            'running',
            $second->refresh()->status,
            'Le second niveau a ete annule pour population insuffisante alors que le seuil etait franchi a son echeance.'
        );
    }

    /**
     * **Une technologie demarree pendant le rattrapage voit l ouverture de son emplacement a l echeance.**
     *
     * Le meme trou que pour un batiment, par l autre porte : une technologie ne se recherche que depuis un
     * emplacement **ouvert**, et c est la population qui l ouvre. Une mutation qui faisait ignorer la
     * population transmise a l ouverture de l emplacement survivait au temoin du batiment ; celui-ci la tue.
     */
    public function testATechnologyStartedDuringTheCatchUpSeesItsSlotOpenAtTheDeadline(): void
    {
        $debut = (int)Date::now()->timestamp;
        $planetId = $this->planetService->getPlanetId();
        $niveaux = resolve(LifeformLevels::class);
        $niveaux->setLevel($planetId, LifeformKind::Building, self::RESIDENTIAL, 30);
        $niveaux->setLevel($planetId, LifeformKind::Building, self::FARM, 35);
        $niveaux->setLevel($planetId, LifeformKind::Building, self::RESEARCH_CENTRE, 1);
        $this->placeLifeformSlot($planetId, 1, self::ENVOYS, $debut - 10);

        // L emplacement 1 exige deux cent mille habitants ; la planete en a cent quatre-vingt-quinze mille au depart.
        $profil = PlanetLifeformProfile::fromLevels(Species::Humans, $niveaux->buildingLevelsOf($planetId), 8.0);
        LifeformPlanet::query()->where('planet_id', $planetId)->update([
            'population' => 195000.0,
            'food' => $profil->foodStorage,
            'calculated_at' => $debut,
            'previous_population' => 195000.0,
            'previous_food' => $profil->foodStorage,
            'previous_calculated_at' => $debut,
        ]);
        $this->planetAddResources(new Resources(100000000, 100000000, 100000000, 0));

        LifeformQueue::query()->create([
            'planet_id' => $planetId, 'user_id' => $this->currentUserId, 'kind' => LifeformKind::Technology->value,
            'object_id' => self::ENVOYS, 'target_level' => 1, 'metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'energy' => 0,
            'time_start' => $debut, 'time_end' => $debut + 60, 'status' => 'running',
            'catalogue_version' => LifeformCatalogue::VERSION,
        ]);
        $second = LifeformQueue::query()->create([
            'planet_id' => $planetId, 'user_id' => $this->currentUserId, 'kind' => LifeformKind::Technology->value,
            'object_id' => self::ENVOYS, 'target_level' => 2, 'metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'energy' => 0,
            'time_start' => null, 'time_end' => null, 'status' => 'waiting',
            'catalogue_version' => LifeformCatalogue::VERSION,
        ]);

        $aLEcheance = (new DemographicClock())->advance(new DemographicState(195000.0, $profil->foodStorage, $debut), $profil, $debut + 60);
        $this->assertGreaterThan(200000.0, $aLEcheance->population, 'Premisse : l emplacement s ouvre avant l echeance de la premiere recherche.');

        $this->travelTo(Date::createFromTimestamp($debut + 3600));
        $this->planetService->update();

        // Une recherche de second niveau est courte a x8 : demarree a l echeance de la premiere, elle a pu
        // finir elle aussi pendant l absence. Ce qui est interdit, c est l annulation.
        $this->assertContains($second->refresh()->status, ['running', 'done'], 'La seconde recherche a ete annulee pour emplacement ferme alors que la population l avait ouvert a son echeance.');
        $this->assertGreaterThanOrEqual(1, resolve(LifeformLevels::class)->levelOf($planetId, LifeformKind::Technology, self::ENVOYS));
    }

    public function testAnUnpopulatedPlanetIsLeftAlone(): void
    {
        LifeformPlanet::query()->where('planet_id', $this->planetService->getPlanetId())->delete();
        $this->travelTo(Date::now()->addHours(3));
        $this->planetService->update();
        $this->assertSame(0, LifeformPlanet::query()->where('planet_id', $this->planetService->getPlanetId())->count());
    }
}
