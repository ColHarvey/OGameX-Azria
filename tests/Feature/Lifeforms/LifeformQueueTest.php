<?php

namespace Tests\Feature\Lifeforms;

use Illuminate\Support\Facades\Date;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\LifeformRefused;
use OGame\Lifeforms\Services\LifeformInstallationService;
use OGame\Lifeforms\Services\LifeformLevels;
use OGame\Lifeforms\Services\LifeformQueueService;
use OGame\Lifeforms\Species;
use OGame\Models\Lifeforms\LifeformAccount;
use OGame\Models\Lifeforms\LifeformBuildingLevel;
use OGame\Models\Lifeforms\LifeformPlanet;
use OGame\Models\Lifeforms\LifeformQueue;
use OGame\Models\Lifeforms\LifeformSpeciesProgress;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Queues\QueueCapacity;
use Tests\AccountTestCase;
use Tests\Support\PinsSettings;

/**
 * La file des travaux de formes de vie : devis fige au depart, un travail en cours par genre,
 * refus propres, annulation qui rembourse.
 */
final class LifeformQueueTest extends AccountTestCase
{
    use PinsSettings;

    private const int RESIDENTIAL = 11101;

    private const int FARM = 11102;

    private const int RESEARCH_CENTRE = 11103;

    private const int ACADEMY = 11104;

    private const int MEDITATION_ENCLAVE = 12101;

    protected function setUp(): void
    {
        parent::setUp();
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
        $this->restorePinnedSettings();
        parent::tearDown();
    }

    public function testAddingTheHousingStartsItAtOnceWithTheOfficialPriceAndDuration(): void
    {
        $maintenant = (int)Date::now()->timestamp;
        $metalAvant = $this->metal();

        $element = resolve(LifeformQueueService::class)->add($this->planetService, self::RESIDENTIAL, $maintenant);

        $this->assertSame('running', $element->status);
        $this->assertSame(1, $element->target_level);
        $this->assertSame(7, $element->metal);
        $this->assertSame(2, $element->crystal);
        $this->assertSame(0, $element->deuterium);
        $this->assertSame($maintenant, $element->time_start);
        $this->assertSame($maintenant + 6, $element->time_end, 'A x8 sans robots : 40 × 1,21 ÷ 8 = 6 s.');
        $this->assertSame($metalAvant - 7, $this->metal(), 'Le prix est debite au depart.');
    }

    public function testASecondLevelWaitsBehindTheFirstAndStartsWhenTheFirstIsDelivered(): void
    {
        $maintenant = (int)Date::now()->timestamp;
        $file = resolve(LifeformQueueService::class);
        $premier = $file->add($this->planetService, self::RESIDENTIAL, $maintenant);
        $second = $file->add($this->planetService, self::RESIDENTIAL, $maintenant);
        $this->assertSame('waiting', $second->status);
        $this->assertSame(2, $second->target_level);
        $this->assertNull($second->time_start);

        // Sept secondes plus tard, la mise a jour de la planete livre le premier et demarre le second a l echeance.
        $this->travelTo(Date::createFromTimestamp($maintenant + 7));
        $this->planetService->update();

        $this->assertSame('done', $premier->refresh()->status);
        $this->assertSame(1, resolve(LifeformLevels::class)->levelOf($this->planetService->getPlanetId(), LifeformKind::Building, self::RESIDENTIAL));
        $second->refresh();
        $this->assertSame('running', $second->status);
        $this->assertSame($premier->time_end, $second->time_start, 'Le suivant part a l echeance du precedent, pas a l instant de la page.');
        $this->assertSame(16, $second->metal, 'Niveau 2 : 7 × 1,2 × 2.');
        $this->assertSame(4, $second->crystal);
        $this->assertSame((int)$premier->time_end + 14, $second->time_end, 'Niveau 2 a x8 : 2 × 40 × 1,21² ÷ 8 = 14 s.');
    }

    /**
     * **La file des formes de vie suit la regle du jeu, Commandant compris** (regle d Azria, 19 septembre 2026,
     * journal §171). Elle autorisait cinq travaux en attente quand la file ordinaire en autorisait quatre.
     */
    public function testTheQueueFollowsTheGameRuleAndTheCommanderExtendsIt(): void
    {
        $maintenant = (int)Date::now()->timestamp;
        $file = resolve(LifeformQueueService::class);

        // Un travail demarre, quatre attendent : le cinquieme en attente — le sixieme de la file — est refuse.
        for ($i = 0; $i < 1 + QueueCapacity::WAITING_BASE; $i++) {
            $file->add($this->planetService, self::RESIDENTIAL, $maintenant);
        }
        $this->assertSame(QueueCapacity::WAITING_BASE, $file->queued($this->planetService->getPlanetId(), LifeformKind::Building)->where('status', 'waiting')->count());
        $this->assertRefused($file, self::RESIDENTIAL, LifeformRefused::QUEUE_FULL, $maintenant);

        // Avec un Commandant, la file de construction va jusqu a huit travaux en attente.
        $joueur = $this->planetService->getPlayer();
        $this->assertNotNull($joueur);
        $utilisateur = $joueur->getUser();
        $utilisateur->commander_until = Date::parse('+1 hour');
        $utilisateur->save();
        $joueur->load($this->currentUserId);

        for ($i = QueueCapacity::WAITING_BASE; $i < QueueCapacity::WAITING_WITH_COMMANDER; $i++) {
            $file->add($this->planetService, self::RESIDENTIAL, $maintenant);
        }
        $this->assertSame(QueueCapacity::WAITING_WITH_COMMANDER, $file->queued($this->planetService->getPlanetId(), LifeformKind::Building)->where('status', 'waiting')->count());
        $this->assertRefused($file, self::RESIDENTIAL, LifeformRefused::QUEUE_FULL, $maintenant);

        // Le Commandant expire : rien n est annule, seuls les ajouts nouveaux sont refuses.
        $utilisateur->commander_until = Date::parse('-1 minute');
        $utilisateur->save();
        $joueur->load($this->currentUserId);

        $this->assertSame(QueueCapacity::WAITING_WITH_COMMANDER, $file->queued($this->planetService->getPlanetId(), LifeformKind::Building)->where('status', 'waiting')->count(), 'Aucun travail n est annule.');
        $this->assertRefused($file, self::RESIDENTIAL, LifeformRefused::QUEUE_FULL, $maintenant);
    }

    public function testTheQueueRefusesWhatTheRulesForbidWithoutWritingAnything(): void
    {
        $maintenant = (int)Date::now()->timestamp;
        $file = resolve(LifeformQueueService::class);
        $metalAvant = $this->metal();

        $this->assertRefused($file, 99999, LifeformRefused::UNKNOWN_OBJECT, $maintenant);
        $this->assertRefused($file, self::MEDITATION_ENCLAVE, LifeformRefused::WRONG_SPECIES, $maintenant);
        $this->assertRefused($file, self::RESEARCH_CENTRE, LifeformRefused::REQUIREMENTS_UNMET, $maintenant);

        // L Academie : prerequis satisfaits par un logement de niveau 41, population de 20 millions exigee.
        resolve(LifeformLevels::class)->setLevel($this->planetService->getPlanetId(), LifeformKind::Building, self::RESIDENTIAL, 41);
        $this->assertRefused($file, self::ACADEMY, LifeformRefused::POPULATION_UNMET, $maintenant);

        $this->pinSettings(['lifeforms_enabled' => 0]);
        $this->assertRefused($file, self::FARM, LifeformRefused::CLOSED, $maintenant);
        $this->pinSettings(['lifeforms_enabled' => 1]);

        $this->assertSame(0, LifeformQueue::query()->where('planet_id', $this->planetService->getPlanetId())->count(), 'Aucun refus n ecrit une ligne.');
        $this->assertSame($metalAvant, $this->metal(), 'Aucun refus ne debite.');

        // Quatre en attente derriere celui qui court, pas un de plus : la regle du jeu, sans Commandant (§171).
        for ($i = 0; $i < 1 + QueueCapacity::WAITING_BASE; $i++) {
            $file->add($this->planetService, self::FARM, $maintenant);
        }
        $this->assertRefused($file, self::FARM, LifeformRefused::QUEUE_FULL, $maintenant);
        $this->assertSame(1, LifeformQueue::query()->where('planet_id', $this->planetService->getPlanetId())->where('status', 'running')->count());
        $this->assertSame(QueueCapacity::WAITING_BASE, LifeformQueue::query()->where('planet_id', $this->planetService->getPlanetId())->where('status', 'waiting')->count());
    }

    public function testAnItemWhoseResourcesAreMissingIsCanceledAtStartNotStartedHalfway(): void
    {
        $maintenant = (int)Date::now()->timestamp;
        Planet::query()->whereKey($this->planetService->getPlanetId())->update(['metal' => 0, 'crystal' => 0, 'deuterium' => 0]);
        $this->planetService->reloadPlanet();

        $element = resolve(LifeformQueueService::class)->add($this->planetService, self::RESIDENTIAL, $maintenant);

        $this->assertSame('canceled', $element->status);
        $this->assertNull($element->time_start);
        $this->assertSame(0, $element->metal, 'Rien n a ete fige : rien n a ete paye.');
        $this->assertNull(resolve(LifeformQueueService::class)->running($this->planetService->getPlanetId(), LifeformKind::Building));
    }

    public function testCancelingTheRunningItemRefundsItAndCascadesTheLevelsAbove(): void
    {
        $maintenant = (int)Date::now()->timestamp;
        $file = resolve(LifeformQueueService::class);
        $metalAvant = $this->metal();
        $premier = $file->add($this->planetService, self::RESIDENTIAL, $maintenant);
        $file->add($this->planetService, self::RESIDENTIAL, $maintenant);
        $file->add($this->planetService, self::RESIDENTIAL, $maintenant);
        $this->assertSame($metalAvant - 7, $this->metal());

        $file->cancel($this->planetService, (int)$premier->id, $maintenant);

        $this->assertSame($metalAvant, $this->metal(), 'Le travail en cours est rembourse.');
        $statuts = LifeformQueue::query()->where('planet_id', $this->planetService->getPlanetId())->orderBy('id')->pluck('status')->all();
        $this->assertSame(['canceled', 'canceled', 'canceled'], $statuts, 'Les niveaux 2 et 3 ne peuvent plus etre atteints dans l ordre.');

        $this->assertRefusedCancel($file, (int)$premier->id, $maintenant);

        // La file repart de zero.
        $nouveau = $file->add($this->planetService, self::RESIDENTIAL, $maintenant);
        $this->assertSame('running', $nouveau->status);
        $this->assertSame(1, $nouveau->target_level);
    }

    private function assertRefused(LifeformQueueService $file, int $objectId, string $raison, int $maintenant): void
    {
        try {
            $file->add($this->planetService, $objectId, $maintenant);
            $this->fail("L objet $objectId aurait du etre refuse ($raison).");
        } catch (LifeformRefused $refus) {
            $this->assertSame($raison, $refus->reason, $refus->getMessage());
        }
    }

    private function assertRefusedCancel(LifeformQueueService $file, int $queueId, int $maintenant): void
    {
        try {
            $file->cancel($this->planetService, $queueId, $maintenant);
            $this->fail('Annuler un element deja annule aurait du etre refuse.');
        } catch (LifeformRefused $refus) {
            $this->assertSame(LifeformRefused::NOT_IN_QUEUE, $refus->reason);
        }
    }

    private function metal(): int
    {
        return (int)Planet::query()->whereKey($this->planetService->getPlanetId())->value('metal');
    }
}
