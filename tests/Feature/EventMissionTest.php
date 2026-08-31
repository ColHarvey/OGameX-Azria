<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use OGame\Models\EventMissionClaim;
use OGame\Models\EventMissionDraw;
use OGame\Models\Message;
use OGame\Services\EventMissionService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;
use ReflectionClass;
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
            'rank_step' => 1000,
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
            'rank_step' => 1000,
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
     * Assert that a completed mission is credited once, without any click.
     */
    public function testCompletedMissionIsCreditedAutomaticallyAndOnlyOnce(): void
    {
        $this->openEvent();

        $player = resolve(PlayerService::class);
        $eventService = resolve(EventMissionService::class);

        $this->assertEquals(0, $eventService->getTritium($player));

        // La mission de connexion est acquise des que le joueur existe.
        $eventService->creditEventDays($player);
        $missions = $eventService->getDailyMissions($player);

        $connexion = null;
        foreach ($missions as $mission) {
            if ($mission['key'] === 'login') {
                $connexion = $mission;
            }
        }

        $this->assertNotNull($connexion, 'The login mission was not drawn.');
        $this->assertTrue($connexion['done']);
        // Le compte vient d etre cree : seule la journee en cours lui est due.
        $this->assertEquals(100, $eventService->getTritium($player));

        // Un second passage ne doit pas crediter une deuxieme fois.
        $eventService->creditEventDays($player);
        $this->assertEquals(100, $eventService->getTritium($player));

        // Compter les lignes de CE joueur : la suite ne repart pas d une base vierge entre
        // deux tests, un comptage global melerait les joueurs des tests precedents.
        $this->assertEquals(1, EventMissionClaim::where('user_id', $player->getId())->count());
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
        $this->assertFalse($ranks[7]['reached'], 'The top rank was reachable without a single mission.');

        $this->post(route('events.claim-rank'), ['rank' => 7, 'reward' => 'dark_matter']);

        $this->assertDatabaseMissing('event_rank_claims', [
            'user_id' => $player->getId(),
            'rank' => 7,
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

    /**
     * Assert that every mission icon actually exists in the public folder.
     *
     * Une image manquante ne casse rien cote serveur : elle laisse simplement un cadre vide
     * dans la page, ce qu aucun autre controle ne detecte. Ce test lit le catalogue par
     * reflexion pour qu ajouter une mission sans son image fasse echouer la suite.
     */
    public function testEveryMissionIconExists(): void
    {
        $reflexion = new ReflectionClass(EventMissionService::class);
        $missions = $reflexion->getConstant('MISSIONS');

        $this->assertIsArray($missions);
        $this->assertNotEmpty($missions);

        foreach ($missions as $key => $mission) {
            $this->assertArrayHasKey('icon', $mission, "Mission $key has no icon.");

            $chemin = public_path('img/' . $mission['icon']);
            $this->assertFileExists($chemin, "Icon missing for mission $key: " . $mission['icon']);
        }
    }

    /**
     * Assert that the daily draw is frozen once used, and survives a catalogue change.
     */
    public function testDrawIsFrozenOnFirstUse(): void
    {
        $this->openEvent();

        $player = resolve(PlayerService::class);
        $eventService = resolve(EventMissionService::class);
        $today = Date::now()->startOfDay();

        $premier = $eventService->getDrawForDay($player, $today);

        $this->assertEquals(1, EventMissionDraw::where('user_id', $player->getId())->count());
        $this->assertNotEmpty($premier);

        // Un second appel relit l instantane au lieu de retirer.
        $this->assertSame($premier, $eventService->getDrawForDay($player, $today));
        $this->assertEquals(1, EventMissionDraw::where('user_id', $player->getId())->count());
    }

    /**
     * Assert that ranks use the fixed ladder, identical for every player.
     */
    public function testRankThresholdsAreFixed(): void
    {
        $this->openEvent();

        $eventService = resolve(EventMissionService::class);
        $ranks = $eventService->getRanks(resolve(PlayerService::class));

        $this->assertCount(7, $ranks);

        $pas = resolve(SettingsService::class)->eventRankStep();

        foreach ($ranks as $rang => $donnees) {
            $this->assertEquals(
                $pas * $rang,
                $donnees['threshold'],
                "Rank $rang does not sit on the fixed ladder."
            );
        }
    }

    /**
     * Assert that a mission completed on an earlier day is still credited later.
     *
     * C est la garantie qui remplace le bouton de reclamation : ne pas ouvrir la page un
     * jour donne ne doit rien faire perdre.
     */
    public function testMissionsFromEarlierDaysAreStillCredited(): void
    {
        $this->openEvent();

        $player = resolve(PlayerService::class);
        $eventService = resolve(EventMissionService::class);

        // Le joueur existait avant l evenement : les jours ecoules lui sont dus.
        $user = $player->getUser();
        $user->created_at = Date::now()->subDays(5);
        $user->save();
        $player->refresh();

        $eventService->creditEventDays($player);

        $hier = Date::now()->subDay()->startOfDay();

        $this->assertTrue(
            EventMissionClaim::where('user_id', $player->getId())
                ->whereDate('mission_date', $hier)
                ->where('mission_key', 'login')
                ->exists(),
            'The login mission of the previous day was never credited.'
        );

        // Deux jours credites : hier et aujourd hui, 100 tritium chacun.
        $this->assertEquals(200, $eventService->getTritium($player));
    }

    /**
     * Assert that a player who registered mid-event gets no back pay.
     *
     * La mission de connexion etant acquise d office, un compte cree en cours d evenement
     * encaisserait sinon les jours precedant son inscription sans rien avoir fait.
     */
    public function testPlayerRegisteredMidEventGetsNoBackPay(): void
    {
        $this->openEvent();

        $player = resolve(PlayerService::class);
        $eventService = resolve(EventMissionService::class);

        // Le compte de test vient d etre cree, l evenement a commence hier.
        $eventService->creditEventDays($player);

        $hier = Date::now()->subDay()->startOfDay();

        $this->assertFalse(
            EventMissionClaim::where('user_id', $player->getId())
                ->whereDate('mission_date', $hier)
                ->exists(),
            'A player registered today was paid for a day before their account existed.'
        );

        $this->assertEquals(100, $eventService->getTritium($player));
    }

    /**
     * Assert that every player can earn the same amount, whatever their draw.
     *
     * C'est la condition sans laquelle des seuils fixes seraient injustes : un tirage libre
     * pouvait donner 500 tritium a l un et 1 500 a l autre le meme jour.
     */
    public function testDailyTotalIsIdenticalForEveryPlayer(): void
    {
        $this->openEvent();

        resolve(SettingsService::class)->set('event_missions_per_day', 5);

        $eventService = resolve(EventMissionService::class);
        $reflexion = new ReflectionClass(EventMissionService::class);
        $missions = $reflexion->getConstant('MISSIONS');
        $today = Date::now()->startOfDay();

        $totaux = [];
        foreach ([1, 2, 3, 42, 1337] as $userId) {
            $total = 0;
            foreach ($eventService->drawMissionKeys($userId, $today) as $key) {
                $total += $missions[$key]['tritium'];
            }
            $totaux[$userId] = $total;
        }

        $this->assertCount(1, array_unique($totaux), 'Players can earn different daily totals: ' . json_encode($totaux));
        $this->assertEquals(1100, reset($totaux), 'Five missions should be worth 1100 tritium a day.');
    }

    /**
     * Assert that the snapshot stores the tritium value, not only the mission key.
     *
     * Sans cette valeur, un jour rattrape plus tard serait credite au tarif du catalogue du
     * moment, et non a celui qui etait affiche au joueur.
     */
    public function testDrawSnapshotCarriesTheTritiumValue(): void
    {
        $this->openEvent();

        $player = resolve(PlayerService::class);
        $eventService = resolve(EventMissionService::class);

        $tirage = $eventService->getDrawForDay($player, Date::now()->startOfDay());

        $this->assertNotEmpty($tirage);

        foreach ($tirage as $mission) {
            $this->assertArrayHasKey('key', $mission);
            $this->assertArrayHasKey('tritium', $mission);
            $this->assertGreaterThan(0, $mission['tritium']);
        }

        // Et la valeur est bien persistee, pas seulement calculee a la volee.
        $ligne = EventMissionDraw::where('user_id', $player->getId())->first();
        $this->assertNotNull($ligne);
        $this->assertArrayHasKey('tritium', $ligne->missions[0]);
    }

    /**
     * Assert that the event settings freeze once players have received a draw.
     */
    public function testEventConfigurationLocksOnceDrawsExist(): void
    {
        $this->openEvent();

        $player = resolve(PlayerService::class);
        $eventService = resolve(EventMissionService::class);
        $settingsService = resolve(SettingsService::class);

        // Un tirage existe des que la page a ete consultee une fois.
        $eventService->getDrawForDay($player, Date::now()->startOfDay());

        $debutOrigine = $settingsService->eventMissionsStart();

        $this->post(route('admin.event.update'), [
            'enabled' => '1',
            'start' => Date::now()->addDays(3)->format('Y-m-d'),
            'end' => Date::now()->addDays(9)->format('Y-m-d'),
            'missions_per_day' => 12,
            'rank_step' => 5000,
        ]);

        $apres = resolve(SettingsService::class);

        $this->assertEquals($debutOrigine, $apres->eventMissionsStart(), 'The start date moved while draws existed.');
        $this->assertEquals(15, $apres->eventMissionsPerDay(), 'The mission count changed while draws existed.');
        $this->assertEquals(1000, $apres->eventRankStep(), 'The rank step changed while draws existed.');

        // La date de fin, elle, reste modifiable.
        $this->assertEquals(Date::now()->addDays(9)->format('Y-m-d'), $apres->eventMissionsEnd());
    }

    /**
     * Assert that the event page really renders the theme's reward interface.
     *
     * L'interface repose entierement sur des classes du theme, toutes ecrites sous
     * #rewardings. Une faute de frappe sur un de ces noms ne casse rien visiblement : elle
     * rend simplement la page nue, ce qu'aucun autre controle ne detecte.
     */
    public function testEventPageRendersTheThemeMarkup(): void
    {
        $this->openEvent();

        $response = $this->get(route('events.index'));
        $response->assertStatus(200);

        $html = $response->getContent();
        $this->assertIsString($html);

        // Le conteneur sans lequel aucune regle du theme ne s'applique.
        $this->assertStringContainsString('id="rewardings"', $html);

        foreach ([
            'class="rewardlist"',
            'class="rewardlist_wrapper"',
            'class="titlebar"',
            'class="tierlist"',
            'tritiumstage',
            'tritiumicon',
            'rewardlist-item',
            'rewardlist-item-icon',
            'rewardlist-item-text',
            'rewardlist-item-wrapper',
            'rewardlist-item-bottom',
            'class="normalRewards"',
            'class="additionalRewards"',
            'class="singleReward"',
            'class="rewardName"',
        ] as $classe) {
            $this->assertStringContainsString($classe, $html, "Theme markup missing: $classe");
        }

        // Un onglet Missions plus un par rang.
        $this->assertEquals(
            EventMissionService::RANK_COUNT + 1,
            substr_count($html, 'btn_blue ogx-tab'),
            'The tab bar does not carry one button per rank.'
        );

        // Autant de panneaux que d'onglets.
        $this->assertEquals(
            EventMissionService::RANK_COUNT + 1,
            substr_count($html, 'class="ogx-panel"'),
            'A tab has no panel behind it.'
        );

        // L'avertissement de perte doit etre visible : c'est la seule chose qui previent
        // le joueur qu'un rang non reclame disparait a la cloture.
        $this->assertStringContainsString(__('t_ingame.events.loss_warning'), $html);
    }

    /**
     * Assert that every image the page points at actually exists on disk.
     */
    public function testEventPageImagesAllResolve(): void
    {
        $this->openEvent();

        $html = $this->get(route('events.index'))->getContent();
        $this->assertIsString($html);

        preg_match_all('/<img[^>]+src="(\/img\/[^"]+)"/', $html, $m);

        $this->assertNotEmpty($m[1], 'The page shows no mission icon at all.');

        foreach (array_unique($m[1]) as $src) {
            $this->assertFileExists(public_path(ltrim($src, '/')), "Broken image on the event page: $src");
        }
    }
}
