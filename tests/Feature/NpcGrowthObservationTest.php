<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use OGame\Factories\PlanetServiceFactory;
use OGame\Models\NpcBaseSnapshot;
use OGame\Models\Resources;
use OGame\Services\Npc\NpcBaseService;
use OGame\Services\Npc\NpcGrowthService;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;

/**
 * La croissance des bases est mesurable, et pas seulement affichee.
 *
 * « Est-ce que les pirates evoluent bien » ne se repond pas en regardant une base : il faut
 * comparer deux instants. Le tick affichait deja une ligne de croissance par base, mais sur
 * la sortie standard d'une commande planifiee que personne ne lit — au bout d'une semaine
 * d'observation il n'en restait rien.
 */
class NpcGrowthObservationTest extends AccountTestCase
{
    private SettingsService $settings;

    /**
     * Les joueurs rendus inactifs le temps du test, a restaurer ensuite.
     *
     * @var array<int, array{id: int, time: string|null}>
     */
    private array $silenced = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = resolve(SettingsService::class);
        $this->settings->set('npc_enabled', '1');
        $this->settings->set('npc_simulation', '1');
        $this->settings->set('npc_seed_min_distance', '0');

        // Le plafond de maturite est pose explicitement : une suite voisine qui l'aurait
        // laisse bas ferait naitre les bases deja « au plafond », et ces tests mesureraient
        // alors le plafond au lieu de la croissance.
        $this->settings->set('npc_min_score_fixed', '25');
        $this->settings->set('npc_maturity_ratio', '1.30');

        DB::table('npc_base_snapshots')->delete();
        $this->isolatePopulation();
    }

    protected function tearDown(): void
    {
        foreach ($this->silenced as $row) {
            DB::table('users')->where('id', $row['id'])->update(['time' => $row['time']]);
        }

        DB::table('npc_base_snapshots')->delete();

        $npcIds = DB::table('users')->where('is_npc', true)->pluck('id')->all();

        if ($npcIds !== []) {
            // Les rapports de combat et les champs de debris referencent ces planetes : sans
            // desactiver les cles etrangeres le nettoyage echouerait, et les comptes
            // survivraient d un test a l autre en faussant les comptages.
            Schema::disableForeignKeyConstraints();

            $planetIds = DB::table('planets')->whereIn('user_id', $npcIds)->pluck('id')->all();
            DB::table('building_queues')->whereIn('planet_id', $planetIds)->delete();
            DB::table('unit_queues')->whereIn('planet_id', $planetIds)->delete();
            DB::table('highscores')->whereIn('player_id', $npcIds)->delete();
            DB::table('users_tech')->whereIn('user_id', $npcIds)->delete();
            DB::table('planets')->whereIn('user_id', $npcIds)->delete();
            DB::table('users')->whereIn('id', $npcIds)->delete();
        }

        $this->settings->set('npc_enabled', '0');

        parent::tearDown();
    }

    /**
     * Silence the other active humans for the duration of the test.
     *
     * Ces tests portent sur la croissance des bases, pas sur les raids. Or le tick charge
     * integralement chaque joueur candidat avant de l'evaluer, ce qui coute une trentaine de
     * secondes par passage dans l'univers de test — pour une partie du travail dont aucun de
     * ces tests ne verifie quoi que ce soit. Les ecarter de la population ramene le tick a
     * ce qu'on mesure ici. C'est le meme procede que NpcNewPlayerProtectionTest.
     */
    private function isolatePopulation(): void
    {
        $limit = Date::now()->subDays(7)->timestamp;

        $rows = DB::table('users')
            ->where('is_npc', false)
            ->where('id', '!=', $this->currentUserId)
            ->whereRaw('users.time + 0 >= ?', [$limit])
            ->select('id', 'time')
            ->get();

        foreach ($rows as $row) {
            $this->silenced[] = ['id' => (int)$row->id, 'time' => $row->time];
        }

        DB::table('users')
            ->whereIn('id', array_column($this->silenced, 'id'))
            ->update(['time' => (string)Date::now()->subDays(60)->timestamp]);
    }

    /**
     * Assert that a tick leaves a measurable trace of every living base.
     */
    public function testATickRecordsWhatEachBaseLooksLike(): void
    {
        $base = resolve(NpcBaseService::class)->createBase();
        $this->assertNotNull($base, 'No pirate base could be created.');

        Artisan::call('ogamex:npc:tick');

        $releve = NpcBaseSnapshot::query()
            ->where('planet_id', $base->getPlanetId())
            ->first();

        $this->assertNotNull($releve, 'The tick recorded nothing about the base it had just grown.');

        // Les caisses sont enregistrees avec le reste, et ce n'est pas accessoire : une base
        // qui repete « rien d abordable » n'est pas arretee par une regle mais par ses
        // ressources, et seul le stock permet de faire la difference.
        $this->assertSame($base->getPlanetId(), $releve->planet_id);
        $this->assertGreaterThan(0, $releve->buildings, 'A seeded base was recorded with no buildings at all.');
        $this->assertNotNull($releve->action, 'The tick recorded no decision for the base.');
    }

    /**
     * Assert that a base is recorded once an hour, not once a tick.
     *
     * Le tick tourne au quart d'heure. Un releve par passage ferait quatre fois plus de
     * lignes sans rien apprendre de plus : une base ne change pas d'allure en quinze minutes,
     * ses batiments se comptent en heures.
     */
    public function testTheTraceIsHourlyAndNotPerTick(): void
    {
        $base = resolve(NpcBaseService::class)->createBase();
        $this->assertNotNull($base, 'No pirate base could be created.');

        Artisan::call('ogamex:npc:tick');
        Artisan::call('ogamex:npc:tick');
        Artisan::call('ogamex:npc:tick');

        $this->assertSame(
            1,
            NpcBaseSnapshot::query()->where('planet_id', $base->getPlanetId())->count(),
            'Three ticks within the same hour left three rows instead of one.'
        );

        // Une heure plus tard, la trace reprend.
        NpcBaseSnapshot::query()
            ->where('planet_id', $base->getPlanetId())
            ->update(['observed_at' => Date::now()->subHours(2)]);

        Artisan::call('ogamex:npc:tick');

        $this->assertSame(
            2,
            NpcBaseSnapshot::query()->where('planet_id', $base->getPlanetId())->count(),
            'The trace did not resume after an hour had passed.'
        );
    }

    /**
     * Assert that the command answers the question a player would actually ask.
     */
    public function testTheBasesCommandShowsStateAndProgress(): void
    {
        $base = resolve(NpcBaseService::class)->createBase();
        $this->assertNotNull($base, 'No pirate base could be created.');

        Artisan::call('ogamex:npc:tick');

        // Un releve plus ancien, pour qu'il y ait une progression a montrer et non un seul
        // point : c'est la comparaison qui informe, pas la valeur du jour.
        NpcBaseSnapshot::create([
            'user_id' => $base->getPlayer()?->getId(),
            'planet_id' => $base->getPlanetId(),
            'score' => 1,
            'maturity' => 2,
            'buildings' => 1,
            'ships' => 0,
            'defences' => 0,
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
            'action' => 'batiment',
            'detail' => 'metal_mine',
            'observed_at' => Date::now()->subDays(3),
        ]);

        Artisan::call('ogamex:npc:bases', ['--days' => 7]);
        $sortie = Artisan::output();

        $this->assertStringContainsString($base->getPlanetName(), $sortie);
        $this->assertStringContainsString('ETAT ACTUEL', $sortie);
        $this->assertStringContainsString('PROGRESSION', $sortie);
        $this->assertStringContainsString('score 1 ->', $sortie, 'The command did not compare the base with its earlier self.');

        // Et les caisses, qui distinguent une base qui attend son tour d'une base bloquee.
        $this->assertStringContainsString('caisses', $sortie);
        $this->assertStringNotContainsString('Aucun releve sur la periode', $sortie);
    }

    /**
     * Assert that the command says what a base is doing now, not an hour ago.
     *
     * Le releve est horaire ; la question « que fait-elle » ne l'est pas. Lire la derniere
     * trace a fait croire, sur le serveur reel, que cinq bases etaient bloquees alors qu'elles
     * venaient toutes de lancer un chantier : la ligne affichee datait d'avant le deploiement
     * qui les avait debloquees. Les files du jeu, elles, disent l'instant present.
     */
    public function testTheCommandShowsWhatIsHappeningNow(): void
    {
        $base = resolve(NpcBaseService::class)->createBase();
        $this->assertNotNull($base, 'No pirate base could be created.');

        // Une trace ancienne et trompeuse, du genre de celle qui a induit en erreur.
        NpcBaseSnapshot::create([
            'user_id' => $base->getPlayer()?->getId(),
            'planet_id' => $base->getPlanetId(),
            'score' => 4,
            'maturity' => 12,
            'buildings' => 12,
            'ships' => 0,
            'defences' => 0,
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
            'action' => 'rien',
            'detail' => 'rien d abordable',
            'observed_at' => Date::now()->subMinutes(50),
        ]);

        $planet = resolve(PlanetServiceFactory::class)->make($base->getPlanetId(), true);
        $this->assertNotNull($planet);
        $planet->addResources(new Resources(50000, 50000, 50000, 0));

        $planet = resolve(PlanetServiceFactory::class)->make($base->getPlanetId(), true);
        $this->assertNotNull($planet);

        $resultat = resolve(NpcGrowthService::class)->grow($planet);
        $this->assertSame(NpcGrowthService::ACTION_BUILDING, $resultat['action']);

        Artisan::call('ogamex:npc:bases');
        $sortie = Artisan::output();

        $this->assertStringContainsString(
            'fini dans',
            $sortie,
            'The command showed no countdown, so it is still reading the hourly trace instead of the live queues.'
        );

        $this->assertStringNotContainsString(
            'rien d abordable',
            $sortie,
            'The command repeated a stale decision while the base was actually building.'
        );
    }

    /**
     * Assert that the trace does not grow without bound.
     */
    public function testOldTracesArePurged(): void
    {
        $base = resolve(NpcBaseService::class)->createBase();
        $this->assertNotNull($base, 'No pirate base could be created.');

        NpcBaseSnapshot::create([
            'user_id' => $base->getPlayer()?->getId(),
            'planet_id' => $base->getPlanetId(),
            'score' => 1,
            'maturity' => 1,
            'buildings' => 1,
            'ships' => 0,
            'defences' => 0,
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
            'action' => 'rien',
            'detail' => '',
            'observed_at' => Date::now()->subDays(45),
        ]);

        Artisan::call('ogamex:npc:tick');

        $this->assertSame(
            0,
            NpcBaseSnapshot::query()->where('observed_at', '<', Date::now()->subDays(30))->count(),
            'Traces older than thirty days survived the tick.'
        );
    }
}
