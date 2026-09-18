<?php

namespace Tests\Feature\Lifeforms;

use Illuminate\Support\Facades\Date;
use OGame\Factories\GameMessageFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\Discovery\LifeformDiscoveryOutcome;
use OGame\Lifeforms\Discovery\LifeformDiscoveryRules;
use OGame\Lifeforms\LifeformRefused;
use OGame\Lifeforms\Services\LifeformDiscoveryService;
use OGame\Lifeforms\Services\LifeformInstallationService;
use OGame\Lifeforms\Services\LifeformLevels;
use OGame\Lifeforms\Services\LifeformResearchService;
use OGame\Lifeforms\Species;
use OGame\Models\Lifeforms\LifeformAccount;
use OGame\Models\Lifeforms\LifeformBuildingLevel;
use OGame\Models\Lifeforms\LifeformDiscovery;
use OGame\Models\Lifeforms\LifeformPlanet;
use OGame\Models\Lifeforms\LifeformSlot;
use OGame\Models\Lifeforms\LifeformSlotChange;
use OGame\Models\Lifeforms\LifeformSpeciesProgress;
use OGame\Models\Lifeforms\LifeformTechnologyLevel;
use OGame\Models\Message;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;
use Tests\AccountTestCase;
use Tests\Support\PinsSettings;
use Tests\Support\PlacesLifeformSlots;

/**
 * Les vols de decouverte (tranche 4) : ouverture, quota, lancement scelle, reglement credite une fois
 * avec son rapport, et la page qui les montre.
 */
final class LifeformDiscoveryTest extends AccountTestCase
{
    use PinsSettings;
    use PlacesLifeformSlots;

    private const int RESEARCH_CENTRE = 11103;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pinSettings(['lifeforms_enabled' => 1, 'economy_speed' => 1]);
        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Humans, (int)Date::now()->timestamp);
        $this->planetAddResources(new Resources(100000, 100000, 100000, 0));
    }

    protected function tearDown(): void
    {
        $planetes = Planet::query()->where('user_id', $this->currentUserId)->pluck('id');
        LifeformDiscovery::query()->where('user_id', $this->currentUserId)->delete();
        Message::query()->where('user_id', $this->currentUserId)->where('key', 'lifeform_discovery_report')->delete();
        LifeformSlot::query()->whereIn('planet_id', $planetes)->delete();
        LifeformSlotChange::query()->whereIn('planet_id', $planetes)->delete();
        LifeformTechnologyLevel::query()->whereIn('planet_id', $planetes)->delete();
        LifeformBuildingLevel::query()->whereIn('planet_id', $planetes)->delete();
        LifeformPlanet::query()->whereIn('planet_id', $planetes)->delete();
        LifeformAccount::query()->where('user_id', $this->currentUserId)->delete();
        LifeformSpeciesProgress::query()->where('user_id', $this->currentUserId)->delete();
        $this->restorePinnedSettings();
        parent::tearDown();
    }

    public function testALaunchNeedsTheCentreTheQuotaTheResourcesAndAFreshPosition(): void
    {
        $service = resolve(LifeformDiscoveryService::class);
        $maintenant = (int)Date::now()->timestamp;
        $depart = $this->planetService->getPlanetCoordinates();
        $cible = new Coordinate($depart->galaxy, $depart->system, $depart->position === 1 ? 2 : 1);

        $this->assertRefused(fn () => $service->launch($this->planetService, $cible, $maintenant), LifeformRefused::DISCOVERY_LOCKED, 'Sans centre de recherche, aucun vol.');
        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Building, self::RESEARCH_CENTRE, 1);

        $this->assertRefused(fn () => $service->launch($this->planetService, new Coordinate(1, 500, 1), $maintenant), LifeformRefused::BAD_COORDINATES);
        $this->assertRefused(fn () => $service->launch($this->planetService, new Coordinate(1, 1, 16), $maintenant), LifeformRefused::BAD_COORDINATES);

        $metalAvant = (int)Planet::query()->whereKey($this->currentPlanetId)->value('metal');
        $vol = $service->launch($this->planetService, $cible, $maintenant);
        $this->assertSame('running', $vol->status);
        $this->assertSame($maintenant, $vol->started_at);
        $this->assertSame($maintenant + LifeformDiscoveryRules::duration(LifeformDiscoveryRules::distance($depart, $cible), 0.0, 1.0), $vol->ends_at, 'Duree Azria, sans Emissaires, coefficient 1.');
        $this->assertSame(LifeformDiscoveryRules::VERSION, $vol->rules_version);
        $issue = LifeformDiscoveryOutcome::fromStorage($vol->outcome);
        $this->assertContains($issue->kind, [LifeformDiscoveryOutcome::NOTHING, LifeformDiscoveryOutcome::ARTIFACTS, LifeformDiscoveryOutcome::EXPERIENCE, LifeformDiscoveryOutcome::SPECIES], 'L issue est scellee au lancement.');
        $this->assertSame($metalAvant - 5000, (int)Planet::query()->whereKey($this->currentPlanetId)->value('metal'));

        $compte = LifeformAccount::query()->where('user_id', $this->currentUserId)->first();
        $this->assertNotNull($compte);
        $this->assertSame(LifeformDiscoveryRules::QUOTA_PER_DAY - 1, $compte->discoveries_available, 'Le quota du jour du choix, moins ce vol.');

        $this->assertRefused(fn () => $service->launch($this->planetService, $cible, $maintenant + 6 * 86400), LifeformRefused::RECENTLY_EXPLORED);
        $autre = new Coordinate($depart->galaxy, $depart->system, $depart->position === 3 ? 4 : 3);

        $compte->discoveries_available = 0;
        $compte->save();
        $this->assertRefused(fn () => $service->launch($this->planetService, $autre, $maintenant), LifeformRefused::QUOTA_EXHAUSTED);
        $compte->discoveries_available = 1;
        $compte->save();

        Planet::query()->whereKey($this->currentPlanetId)->update(['metal' => 100]);
        $this->assertRefused(fn () => $service->launch($this->planetService, $autre, $maintenant), LifeformRefused::INSUFFICIENT_RESOURCES);
        $this->assertSame(1, (int)LifeformAccount::query()->where('user_id', $this->currentUserId)->value('discoveries_available'), 'Un refus ne consomme pas le quota.');
        $this->assertSame(1, LifeformDiscovery::query()->where('user_id', $this->currentUserId)->count());

        $this->pinSettings(['lifeforms_enabled' => 0]);
        $this->assertRefused(fn () => $service->launch($this->planetService, $autre, $maintenant), LifeformRefused::CLOSED);
    }

    /**
     * Les Emissaires intergalactiques raccourcissent le vol par le vrai chemin (l echeance ecrite), avec l experience de
     * l espece de la technologie et la Metropole de la planete — ce que la fiche affichait deja — et seulement tant que
     * leur emplacement est ouvert (audit des effets, journal §157).
     */
    public function testTheEnvoysShortenTheFlightWithExperienceAndMetropolisWhileTheirSlotIsOpen(): void
    {
        $service = resolve(LifeformDiscoveryService::class);
        $maintenant = (int)Date::now()->timestamp;
        $planetId = $this->currentPlanetId;
        $depart = $this->planetService->getPlanetCoordinates();
        $cible = new Coordinate($depart->galaxy, $depart->system, $depart->position === 1 ? 2 : 1);
        $niveaux = resolve(LifeformLevels::class);
        $niveaux->setLevel($planetId, LifeformKind::Building, self::RESEARCH_CENTRE, 1);
        $niveaux->setLevel($planetId, LifeformKind::Building, 11111, 8); // Metropole : +4 %
        LifeformSpeciesProgress::query()->updateOrCreate(['user_id' => $this->currentUserId, 'species' => Species::Humans->value], ['experience' => 3600, 'discovered_at' => $maintenant]); // niveau 4 : +0,4 %
        LifeformPlanet::query()->where('planet_id', $planetId)->update(['population' => 250000.0]);
        $this->placeLifeformSlot($planetId, 1, 11201, $maintenant);
        $niveaux->setLevel($planetId, LifeformKind::Technology, 11201, 10);

        $multiplicateur = resolve(LifeformResearchService::class)->technologyBonusMultiplier($this->currentUserId, Species::Humans, $niveaux->buildingLevelsOf($planetId));
        $this->assertGreaterThan(1.04, $multiplicateur, 'Premisse : experience ET Metropole comptent (1,04 x (1 + experience)).');
        $attendue = min(0.99, 10.0 * $multiplicateur / 100);
        $this->assertEqualsWithDelta($attendue, $service->envoysReduction($this->currentUserId, $planetId), 1e-9, '10 % x le multiplicateur : la meme regle que la fiche et le resolveur.');
        $vol = $service->launch($this->planetService, $cible, $maintenant);
        $this->assertSame($maintenant + LifeformDiscoveryRules::duration(LifeformDiscoveryRules::distance($depart, $cible), $attendue, 1.0), $vol->ends_at, 'L echeance ecrite porte la reduction.');

        // L emplacement se referme (population retombee) : les Emissaires ne comptent plus.
        LifeformPlanet::query()->where('planet_id', $planetId)->update(['population' => 1000.0]);
        $this->assertSame(0.0, $service->envoysReduction($this->currentUserId, $planetId), 'Un emplacement referme eteint l effet.');
    }

    public function testTheQuotaAccruesFromTheSpeciesChoiceByWholeDays(): void
    {
        $service = resolve(LifeformDiscoveryService::class);
        $compte = LifeformAccount::query()->where('user_id', $this->currentUserId)->first();
        $this->assertNotNull($compte);
        $choix = (int)$compte->chosen_at;

        $lu = $service->accrueQuota($this->currentUserId, $choix + 3 * 86400 + 3600);
        $this->assertNotNull($lu);
        $this->assertSame(4 * LifeformDiscoveryRules::QUOTA_PER_DAY, $lu->discoveries_available, 'Le jour du choix plus trois jours revolus.');
        $this->assertSame($choix + 3 * 86400, $lu->discoveries_credited_until, 'La fraction du quatrieme jour attend.');
        $this->assertSame($choix, $lu->discoveries_started_at);

        $relu = $service->accrueQuota($this->currentUserId, $choix + 3 * 86400 + 7200);
        $this->assertNotNull($relu);
        $this->assertSame(4 * LifeformDiscoveryRules::QUOTA_PER_DAY, $relu->discoveries_available, 'Rien de plus avant le jour suivant.');

        $plusTard = $service->accrueQuota($this->currentUserId, $choix + 4 * 86400);
        $this->assertNotNull($plusTard);
        $this->assertSame(5 * LifeformDiscoveryRules::QUOTA_PER_DAY, $plusTard->discoveries_available);

        $this->assertNull($service->accrueQuota($this->currentUserId + 1000000, $choix), 'Sans compte, rien.');
    }

    public function testASettlementCreditsOnceSendsOneReportAndRespectsTheArtifactCap(): void
    {
        $service = resolve(LifeformDiscoveryService::class);
        $maintenant = (int)Date::now()->timestamp;
        $joueur = resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);

        $vol = $this->aDueFlight(new LifeformDiscoveryOutcome(LifeformDiscoveryOutcome::ARTIFACTS, null, 25, 0), $maintenant);
        $this->assertSame(1, $service->settleDue($joueur, $maintenant));
        $this->assertSame(0, $service->settleDue($joueur, $maintenant), 'Un second passage ne trouve rien.');
        $this->assertSame(25, (int)LifeformAccount::query()->where('user_id', $this->currentUserId)->value('artifacts'), 'Credite une fois.');
        $vol->refresh();
        $this->assertSame('settled', $vol->status);
        $this->assertSame($maintenant, $vol->settled_at);
        $this->assertSame(1, Message::query()->where('user_id', $this->currentUserId)->where('key', 'lifeform_discovery_report')->count());
        $message = Message::query()->where('user_id', $this->currentUserId)->where('key', 'lifeform_discovery_report')->first();
        $this->assertNotNull($message);
        $this->assertSame('artifacts', $message->params['outcome_code']);
        $this->assertSame(25, (int)$message->params['artifacts']);

        // Une espece nouvelle : decouverte datee, experience de bienvenue, et le tirage la voit.
        $this->assertSame([Species::Humans], resolve(LifeformResearchService::class)->discoveredSpeciesOf($this->currentUserId));
        $this->aDueFlight(new LifeformDiscoveryOutcome(LifeformDiscoveryOutcome::SPECIES, Species::Rocktal, 0, 100), $maintenant);
        $this->assertSame(1, $service->settleDue($joueur, $maintenant));
        $progres = LifeformSpeciesProgress::query()->where('user_id', $this->currentUserId)->where('species', Species::Rocktal->value)->first();
        $this->assertNotNull($progres);
        $this->assertSame($maintenant, $progres->discovered_at);
        $this->assertSame(100, $progres->experience);
        $this->assertSame([Species::Humans, Species::Rocktal], resolve(LifeformResearchService::class)->discoveredSpeciesOf($this->currentUserId));

        // De l experience pour une espece deja connue : additionnee, date inchangee.
        $this->aDueFlight(new LifeformDiscoveryOutcome(LifeformDiscoveryOutcome::EXPERIENCE, Species::Rocktal, 0, 60), $maintenant + 10);
        $this->assertSame(1, $service->settleDue($joueur, $maintenant + 10));
        $progres->refresh();
        $this->assertSame(160, $progres->experience);
        $this->assertSame($maintenant, $progres->discovered_at);

        // La reserve pleine refuse, et le rapport le dit.
        LifeformAccount::query()->where('user_id', $this->currentUserId)->update(['artifacts' => LifeformDiscoveryRules::ARTIFACT_CAP]);
        $plein = $this->aDueFlight(new LifeformDiscoveryOutcome(LifeformDiscoveryOutcome::ARTIFACTS, null, 50, 0), $maintenant + 20);
        $this->assertSame(1, $service->settleDue($joueur, $maintenant + 20));
        $this->assertSame(LifeformDiscoveryRules::ARTIFACT_CAP, (int)LifeformAccount::query()->where('user_id', $this->currentUserId)->value('artifacts'));
        $plein->refresh();
        $this->assertSame(0, LifeformDiscoveryOutcome::fromStorage($plein->outcome)->artifacts, 'L issue creditee est ecrite : zero.');

        // Un vol pas encore echu attend.
        $this->aDueFlight(new LifeformDiscoveryOutcome(LifeformDiscoveryOutcome::NOTHING, null, 0, 0), $maintenant + 3600);
        $this->assertSame(0, $service->settleDue($joueur, $maintenant + 30));
        $this->assertSame(4, Message::query()->where('user_id', $this->currentUserId)->where('key', 'lifeform_discovery_report')->count());
    }

    public function testThePlayerUpdateSettlesDueFlightsAndTheReportReadsInFrench(): void
    {
        $maintenant = (int)Date::now()->timestamp;
        $this->aDueFlight(new LifeformDiscoveryOutcome(LifeformDiscoveryOutcome::SPECIES, Species::Mechas, 0, 100), $maintenant - 5);

        // La vue generale met le joueur a jour : le vol est regle en passant.
        $this->get(route('overview.index'))->assertStatus(200);

        $this->assertSame('settled', LifeformDiscovery::query()->where('user_id', $this->currentUserId)->value('status'));
        $this->assertNotNull(LifeformSpeciesProgress::query()->where('user_id', $this->currentUserId)->where('species', Species::Mechas->value)->whereNotNull('discovered_at')->first());

        $message = Message::query()->where('user_id', $this->currentUserId)->where('key', 'lifeform_discovery_report')->first();
        $this->assertNotNull($message);
        app()->setLocale('fr');
        $rapport = GameMessageFactory::createGameMessage($message);
        $this->assertStringContainsString('Mechas', $rapport->getBody());
        $this->assertStringContainsString('nouvelle espèce', $rapport->getBody());
        $this->assertStringNotContainsString('t_messages', $rapport->getBody());
        $this->assertStringNotContainsString('t_lifeforms', $rapport->getBody());
    }

    public function testThePageShowsTheQuotaAndLaunchesAFlight(): void
    {
        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Building, self::RESEARCH_CENTRE, 1);
        $page = $this->get(route('lifeforms.discoveries'));
        $page->assertStatus(200);
        $page->assertSee('id="lifeform-discoveries-quota"', false);
        $page->assertSee(__('t_lifeforms_ui.discoveries.quota', ['available' => LifeformDiscoveryRules::QUOTA_PER_DAY, 'per_day' => LifeformDiscoveryRules::QUOTA_PER_DAY]));
        $page->assertSee(route('lifeforms.discoveries.launch'), false);
        $page->assertSee(__('t_lifeforms_ui.discoveries.none_running'));

        $depart = $this->planetService->getPlanetCoordinates();
        $cible = ['galaxy' => $depart->galaxy, 'system' => $depart->system, 'position' => $depart->position === 1 ? 2 : 1];
        $this->post(route('lifeforms.discoveries.launch'), $cible)->assertRedirect(route('lifeforms.discoveries'));
        $this->assertSame(1, LifeformDiscovery::query()->where('user_id', $this->currentUserId)->where('status', 'running')->count());

        $page = $this->get(route('lifeforms.discoveries'));
        $page->assertSee('class="lifeform-discovery-running"', false);
        $page->assertSee($cible['galaxy'] . ':' . $cible['system'] . ':' . $cible['position']);

        // Le refus du service arrive au joueur comme une phrase, jamais comme une clef.
        $this->post(route('lifeforms.discoveries.launch'), $cible)->assertRedirect(route('lifeforms.discoveries'));
        $refus = $this->get(route('lifeforms.discoveries'));
        $refus->assertSee(__('t_lifeforms_ui.refused.recently_explored'));
        $this->assertStringNotContainsString('t_lifeforms_ui.', (string)$refus->getContent());

        $this->post(route('lifeforms.discoveries.launch'), ['galaxy' => 1, 'system' => 0, 'position' => 1])->assertSessionHasErrors('system');

        $this->pinSettings(['lifeforms_enabled' => 0]);
        $this->get(route('lifeforms.discoveries'))->assertStatus(404);
        $this->post(route('lifeforms.discoveries.launch'), $cible)->assertStatus(404);
    }

    private function aDueFlight(LifeformDiscoveryOutcome $issue, int $endsAt): LifeformDiscovery
    {
        return LifeformDiscovery::query()->create([
            'user_id' => $this->currentUserId,
            'planet_id' => $this->currentPlanetId,
            'galaxy' => 1,
            'system' => 1 + LifeformDiscovery::query()->count() % 400,
            'position' => 7,
            'started_at' => $endsAt - 3600,
            'ends_at' => $endsAt,
            'outcome' => $issue->toStorage(),
            'status' => 'running',
            'rules_version' => LifeformDiscoveryRules::VERSION,
        ]);
    }

    private function assertRefused(callable $action, string $raison, string $message = ''): void
    {
        try {
            $action();
            $this->fail("Un refus « $raison » etait attendu. $message");
        } catch (LifeformRefused $refus) {
            $this->assertSame($raison, $refus->reason, $message !== '' ? $message : $refus->getMessage());
        }
    }
}
