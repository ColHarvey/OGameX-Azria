<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use OGame\Models\Message;
use OGame\Services\EventMissionService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;

/**
 * Test the daily mission event: admin switch, mission draw, claiming and ranks.
 */
class EventMissionTest extends AccountTestCase
{
    /**
     * Assign admin role so the configuration routes are reachable.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $user = auth()->user();
        if ($user === null) {
            $this->fail('No authenticated user found.');
        }

        $this->artisan('ogamex:admin:assign-role', ['username' => $user->username]);
    }

    /**
     * Close the event again so its settings do not leak into other tests.
     */
    protected function tearDown(): void
    {
        $settingsService = resolve(SettingsService::class);
        $settingsService->set('event_missions_enabled', '0');
        $settingsService->set('event_missions_start', '');
        $settingsService->set('event_missions_end', '');
        $settingsService->set('event_missions_per_day', 5);

        parent::tearDown();
    }

    /**
     * Opens an event covering today, drawing every mission of the catalogue.
     *
     * Le tirage complet rend le test deterministe : avec cinq missions sur quinze, rien ne
     * garantirait que la mission verifiee soit tiree ce jour-la pour ce joueur.
     */
    private function openEvent(): void
    {
        $settingsService = resolve(SettingsService::class);
        $settingsService->set('event_missions_enabled', '1');
        $settingsService->set('event_missions_start', Date::now()->subDay()->format('Y-m-d'));
        $settingsService->set('event_missions_end', Date::now()->addDay()->format('Y-m-d'));
        $settingsService->set('event_missions_per_day', 15);
    }

    /**
     * Assert that the page stays closed and the menu entry hidden when no event runs.
     */
    public function testEventPageIsClosedByDefault(): void
    {
        $response = $this->get(route('events.index'));

        $response->assertStatus(200);
        $response->assertSee(__('t_ingame.events.closed'));

        // Le menu ne doit pas annoncer une page vide.
        $overview = $this->get(route('overview.index'));
        $overview->assertDontSee(route('events.index'));
    }

    /**
     * Assert that opening the event from the admin panel announces it to the players.
     */
    public function testOpeningEventSendsAnnouncement(): void
    {
        $user = auth()->user();
        $this->assertNotNull($user);

        $response = $this->post(route('admin.event.update'), [
            'enabled' => '1',
            'start' => Date::now()->format('Y-m-d'),
            'end' => Date::now()->addDays(6)->format('Y-m-d'),
            'missions_per_day' => 5,
        ]);

        $response->assertRedirect(route('admin.event.index'));

        $this->assertDatabaseHas('messages', [
            'user_id' => $user->id,
            'key' => 'event_started',
        ]);

        // Reenregistrer un evenement deja ouvert ne doit pas renvoyer d annonce.
        $this->post(route('admin.event.update'), [
            'enabled' => '1',
            'start' => Date::now()->format('Y-m-d'),
            'end' => Date::now()->addDays(6)->format('Y-m-d'),
            'missions_per_day' => 5,
        ]);

        $this->assertEquals(
            1,
            Message::where('user_id', $user->id)->where('key', 'event_started')->count(),
            'The announcement was sent twice for a single opening.'
        );
    }

    /**
     * Assert that the daily draw is stable for a given player and day.
     */
    public function testMissionDrawIsDeterministic(): void
    {
        $this->openEvent();

        $eventService = resolve(EventMissionService::class);
        $today = Date::now()->startOfDay();

        $premier = $eventService->drawMissionKeys(1, $today);
        $second = $eventService->drawMissionKeys(1, $today);

        $this->assertSame($premier, $second, 'The draw changed between two calls on the same day.');
        $this->assertNotSame(
            $premier,
            $eventService->drawMissionKeys(2, $today),
            'Two different players received the exact same mission order.'
        );
    }

    /**
     * Assert that a completed mission can be claimed once, and credits tritium.
     */
    public function testMissionCanBeClaimedOnlyOnce(): void
    {
        $this->openEvent();

        $player = resolve(PlayerService::class);
        $eventService = resolve(EventMissionService::class);

        $this->assertEquals(0, $eventService->getTritium($player));

        // La mission de connexion est acquise du seul fait de consulter la page.
        $response = $this->post(route('events.claim-mission'), ['mission' => 'login']);
        $response->assertRedirect(route('events.index'));

        $this->assertEquals(100, $eventService->getTritium($player));

        // Deuxieme reclamation : refusee, et le total ne bouge pas.
        $this->post(route('events.claim-mission'), ['mission' => 'login']);
        $this->assertEquals(100, $eventService->getTritium($player));
    }

    /**
     * Assert that a rank cannot be claimed while its threshold is out of reach.
     */
    public function testRankCannotBeClaimedBelowThreshold(): void
    {
        $this->openEvent();

        $player = resolve(PlayerService::class);
        $eventService = resolve(EventMissionService::class);

        $ranks = $eventService->getRanks($player);

        $this->assertCount(EventMissionService::RANK_COUNT, $ranks);
        $this->assertFalse($ranks[5]['reached'], 'The top rank was reachable without a single mission.');

        $this->post(route('events.claim-rank'), ['rank' => 5, 'reward' => 'dark_matter']);

        $this->assertDatabaseMissing('event_rank_claims', [
            'user_id' => $player->getId(),
            'rank' => 5,
        ]);
    }

    /**
     * Assert that every measure in the catalogue runs against the real schema.
     *
     * Ce test existe pour une raison precise : chaque mesure est une requete SQL ecrite a la
     * main sur une table du jeu. Une table ou une colonne mal nommee ne se voit ni a
     * l analyse statique ni au lint, seulement a l execution. En tirant les quinze missions,
     * on execute les quinze requetes.
     */
    public function testEveryMissionMeasureRunsAgainstTheSchema(): void
    {
        $this->openEvent();

        $player = resolve(PlayerService::class);
        $eventService = resolve(EventMissionService::class);

        $missions = $eventService->getDailyMissions($player);

        $this->assertCount(15, $missions, 'The whole catalogue was not drawn.');

        foreach ($missions as $mission) {
            $this->assertIsInt($mission['progress'], 'Mission ' . $mission['key'] . ' returned no measurable progress.');
            $this->assertGreaterThanOrEqual(0, $mission['progress']);
        }
    }
}
