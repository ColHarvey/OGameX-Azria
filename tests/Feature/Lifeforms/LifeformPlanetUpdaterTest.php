<?php

namespace Tests\Feature\Lifeforms;

use Illuminate\Support\Facades\Date;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\Demography\DemographicClock;
use OGame\Lifeforms\Demography\DemographicState;
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
use Tests\AccountTestCase;
use Tests\Support\PinsSettings;

/**
 * La mise a jour d une planete peuplee, par `PlanetService::update()` : l horloge coupe aux
 * revisions de vitesse et aux echeances des travaux, et ne fait jamais deux fois la meme chose.
 */
final class LifeformPlanetUpdaterTest extends AccountTestCase
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

    public function testAnUnpopulatedPlanetIsLeftAlone(): void
    {
        LifeformPlanet::query()->where('planet_id', $this->planetService->getPlanetId())->delete();
        $this->travelTo(Date::now()->addHours(3));
        $this->planetService->update();
        $this->assertSame(0, LifeformPlanet::query()->where('planet_id', $this->planetService->getPlanetId())->count());
    }
}
