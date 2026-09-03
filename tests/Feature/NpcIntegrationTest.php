<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\Highscore;
use OGame\Models\NpcBaseSnapshot;
use OGame\Models\Resources;
use OGame\Services\HighscoreService;
use OGame\Services\Npc\NpcColonisationService;
use OGame\Services\Npc\NpcGrowthService;
use OGame\Services\Npc\NpcRaidService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;
use Tests\SpawnsNpcBases;

/**
 * Tout ce qui fait vivre une base tient-il ensemble ?
 *
 * Les autres suites verifient chaque piece separement. Celle-ci verifie les jointures, la ou
 * les defauts se sont tous loges jusqu'ici : une base qui construit mais ne cherche pas, qui
 * cherche mais n'encaisse pas, qui possede des vaisseaux mais ne les embarque pas, qui grandit
 * mais dont le classement l'ignore.
 */
class NpcIntegrationTest extends AccountTestCase
{
    use SpawnsNpcBases;

    private SettingsService $settings;

    private NpcGrowthService $growth;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = resolve(SettingsService::class);
        $this->growth = resolve(NpcGrowthService::class);

        $this->settings->set('npc_enabled', '1');
        $this->settings->set('npc_simulation', '1');
        $this->settings->set('npc_seed_min_distance', '0');
        $this->settings->set('npc_min_score_fixed', '100000');
        $this->settings->set('npc_maturity_ratio', '10');
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        $this->settings->set('npc_enabled', '0');
        $this->settings->set('npc_swarm_enabled', '0');
        $this->settings->set('npc_min_score_fixed', '25');
        $this->settings->set('npc_maturity_ratio', '1.30');

        $npcIds = DB::table('users')->where('is_npc', true)->pluck('id')->all();

        if ($npcIds !== []) {
            Schema::disableForeignKeyConstraints();

            $planetIds = DB::table('planets')->whereIn('user_id', $npcIds)->pluck('id')->all();
            DB::table('fleet_missions')->whereIn('planet_id_from', $planetIds)->delete();
            DB::table('building_queues')->whereIn('planet_id', $planetIds)->delete();
            DB::table('unit_queues')->whereIn('planet_id', $planetIds)->delete();
            DB::table('research_queues')->whereIn('planet_id', $planetIds)->delete();
            DB::table('npc_base_snapshots')->whereIn('planet_id', $planetIds)->delete();
            DB::table('highscores')->whereIn('player_id', $npcIds)->delete();
            DB::table('users_tech')->whereIn('user_id', $npcIds)->delete();
            DB::table('planets')->whereIn('user_id', $npcIds)->delete();
            DB::table('users')->whereIn('id', $npcIds)->delete();
        }

        parent::tearDown();
    }

    /**
     * Assert that a base short of energy builds something that makes energy.
     *
     * Sans cela les mines tournent au ralenti : la production s'effondre, la base n'a plus de
     * quoi construire, et elle ne construit donc jamais ce qui la sortirait de la. C'est un
     * puits dont on ne remonte pas tout seul.
     */
    public function testABaseInEnergyDeficitBuildsAPowerSource(): void
    {
        $base = $this->aSpawnedBase();

        $planetId = $base->getPlanetId();
        $factory = resolve(PlanetServiceFactory::class);

        // On casse l'energie en montant les mines sans toucher au solaire.
        $base->setObjectLevel(ObjectService::getObjectByMachineName('metal_mine')->id, 12, false);
        $base->setObjectLevel(ObjectService::getObjectByMachineName('crystal_mine')->id, 10, false);
        $base->setObjectLevel(ObjectService::getObjectByMachineName('solar_plant')->id, 1, false);
        $base->save();
        $base->updateResourceProductionStats();

        $planet = $factory->make($planetId, true);
        $this->assertNotNull($planet);
        $this->assertLessThan(0, $planet->energy()->get(), 'The test failed to put the base into an energy deficit.');

        $planet->addResources(new Resources(200000, 200000, 200000, 0));
        $planet = $factory->make($planetId, true);
        $this->assertNotNull($planet);

        $resultat = $this->growth->grow($planet);

        $this->assertSame(NpcGrowthService::ACTION_BUILDING, $resultat['action']);
        $this->assertStringContainsString(
            'solar_plant',
            $resultat['detail'],
            'A base running an energy deficit built something other than a power source, so its mines stay throttled.'
        );
    }

    /**
     * Assert that a base builds what it can afford instead of idling on what it cannot.
     *
     * Reproduit l'etat mesure sur le serveur reel le 1er septembre 2026 : cinq bases
     * immobiles depuis leur naissance, caisses a 925 metal, 678 cristal et 41 deuterium,
     * bloquees sur une usine de robots qui en reclame 200 — avec trois batiments abordables
     * sous la main dont pas un n'a ete monte. Un joueur monte ses mines en attendant que le
     * deuterium rentre ; une base doit faire pareil.
     */
    public function testABaseBuildsWhatItCanAffordInsteadOfIdling(): void
    {
        $base = $this->aSpawnedBase();

        $planetId = $base->getPlanetId();
        $factory = resolve(PlanetServiceFactory::class);

        // Energie confortable : sans cela le solaire passerait devant et masquerait le cas.
        $base->setObjectLevel(ObjectService::getObjectByMachineName('solar_plant')->id, 10, false);
        $base->save();
        $base->updateResourceProductionStats();

        $planet = $factory->make($planetId, true);
        $this->assertNotNull($planet);
        $planet->deductResources(new Resources(
            $planet->metal()->get(),
            $planet->crystal()->get(),
            $planet->deuterium()->get(),
            0
        ));

        $planet = $factory->make($planetId, true);
        $this->assertNotNull($planet);
        $planet->addResources(new Resources(925, 678, 41, 0));

        $planet = $factory->make($planetId, true);
        $this->assertNotNull($planet);

        // L'usine de robots est bien le premier choix, et elle est bien hors de prix.
        $this->assertLessThan(4, $planet->getObjectLevel('robot_factory'));
        $this->assertFalse(
            $planet->hasResources(ObjectService::getObjectPrice('robot_factory', $planet)),
            'The scenario no longer reproduces: the robot factory has become affordable.'
        );

        $resultat = $this->growth->grow($planet);

        $this->assertSame(
            NpcGrowthService::ACTION_BUILDING,
            $resultat['action'],
            'The base stood idle because its first choice was too dear, instead of building something cheaper.'
        );

        $this->assertSame(
            1,
            DB::table('building_queues')->where('planet_id', $planetId)->count(),
            'Nothing actually reached the building queue.'
        );
    }

    /**
     * Assert that a base whose planet is full leaves to keep growing.
     *
     * Une planete a un nombre de cases fini. Le terraformeur ne repousse la limite que d'un
     * cran ; ensuite, la seule facon de continuer a grandir est d'aller ailleurs — ce que
     * ferait un joueur.
     */
    public function testAFullBaseLeavesToKeepGrowing(): void
    {
        $this->settings->set('npc_swarm_enabled', '1');
        $this->settings->set('npc_swarm_delay_days', '365');

        $base = $this->aSpawnedBase();

        $base->addUnit('colony_ship', 1);
        $base->addResources(new Resources(200000, 200000, 200000, 0));

        $owner = $base->getPlayer();
        $this->assertNotNull($owner);

        DB::table('users_tech')
            ->where('user_id', $owner->getId())
            ->update(['astrophysics' => 4, 'impulse_drive' => 3, 'espionage_technology' => 4]);

        // Aucun releve : la base n'a donc pas « merite » son essaimage par l'anciennete. Seule
        // la saturation peut la faire partir.
        NpcBaseSnapshot::query()->where('planet_id', $base->getPlanetId())->delete();

        $this->assertSame(
            [],
            resolve(NpcColonisationService::class)->swarm(false),
            'A base with room to spare left anyway.'
        );

        // On remplit la planete jusqu'a la derniere case.
        DB::table('planets')->where('id', $base->getPlanetId())->update([
            'field_max' => DB::raw('field_current + 1'),
        ]);

        $partis = resolve(NpcColonisationService::class)->swarm(false);

        $this->assertNotEmpty($partis, 'A base with no field left stayed put instead of founding a colony.');
    }

    /**
     * Assert that a raid can only ever carry ships the base actually owns.
     */
    public function testARaidOnlyCarriesShipsTheBaseReallyOwns(): void
    {
        $base = $this->aSpawnedBase();

        $base->addUnit('small_cargo', 10);
        $base->addUnit('light_fighter', 30);

        $raids = resolve(NpcRaidService::class);
        $flotte = $raids->assembleFleet($base, 1000000000);

        // Jamais plus que ce qui existe, et jamais toute la flotte : une base qui part
        // entierement se laisse prendre sans combat au retour.
        $this->assertLessThanOrEqual(10, $flotte->getAmountByMachineName('small_cargo'));
        $this->assertLessThanOrEqual(30, $flotte->getAmountByMachineName('light_fighter'));
        $this->assertSame(
            0,
            $flotte->getAmountByMachineName('cruiser'),
            'The raid fielded a cruiser the base never built.'
        );

        $this->assertGreaterThan(
            0,
            $flotte->getAmountByMachineName('small_cargo'),
            'The raid took no cargo, so it would come home empty however well it fought.'
        );
    }

    /**
     * Assert that the highscore follows a base as it grows.
     *
     * Le score sert a deux choses qui comptent : la ligne de faction du classement, et la
     * mediane qui fixe le plafond de maturite. Un score fige laisserait les deux mentir.
     */
    public function testTheHighscoreFollowsAGrowingBase(): void
    {
        $base = $this->aSpawnedBase();

        $userId = $base->getPlayer()?->getId();
        $this->assertNotNull($userId);

        // Le lien qui compte : la commande planifiee balaye User::whereHas('tech'). Un compte
        // PNJ sans fiche technique serait invisible au classement, et sa ligne de faction
        // resterait vide sans que rien ne le signale.
        $this->assertTrue(
            DB::table('users_tech')->where('user_id', $userId)->exists(),
            'A pirate account has no tech row, so the highscore job would skip it entirely.'
        );

        $highscore = resolve(HighscoreService::class);
        $factory = resolve(PlayerServiceFactory::class);

        $avant = $highscore->getPlayerScore($factory->make($userId, true));
        $this->assertSame($avant, $this->writeHighscoreFor($userId));

        $this->runGrowth($base->getPlanetId(), 25, 6);

        $apres = $this->writeHighscoreFor($userId);

        $this->assertGreaterThan(
            $avant,
            $apres,
            'The base grew but its highscore did not move, so the faction row and the median would both lie.'
        );

        $this->assertSame(
            $apres,
            (int)DB::table('highscores')->where('player_id', $userId)->value('general'),
            'The score was computed but never stored on the row the ranking reads.'
        );

        $planet = resolve(PlanetServiceFactory::class)->make($base->getPlanetId(), true);
        $this->assertNotNull($planet);

        // Et le rang reste a zero : un PNJ ne doit pas entrer dans la mediane qui produit son
        // propre plafond, sinon le calcul se referme sur lui-meme.
        Artisan::call('ogamex:scheduler:generate-highscore-ranks');

        $this->assertSame(
            0,
            (int)DB::table('highscores')->where('player_id', $userId)->value('general_rank'),
            'A pirate base entered the individual ranking, and therefore the median that caps it.'
        );

        $this->assertGreaterThan(0, $planet->getBuildingCount());
    }

    /**
     * Assert that growth really moves buildings, research and the shipyard together.
     */
    public function testGrowthAdvancesAllThreeQueues(): void
    {
        $base = $this->aSpawnedBase();

        $planet = $this->runGrowth($base->getPlanetId(), 60, 6);
        $player = resolve(PlayerServiceFactory::class)->make($planet->getPlayer()?->getId() ?? 0, true);

        $this->assertGreaterThan(0, $planet->getObjectLevel('research_lab'), 'No laboratory was ever raised.');
        $this->assertGreaterThan(0, $planet->getObjectLevel('shipyard'), 'No shipyard was ever raised.');
        $this->assertGreaterThan(0, $player->getResearchLevel('energy_technology'), 'No research was ever completed.');
        $this->assertGreaterThan(0, $planet->getDefenseUnits()->getAmount(), 'The base armed nothing at all.');

        // L'energie reste positive tout du long : c'est la condition pour que les mines
        // produisent a plein, donc pour que le reste soit finançable.
        $this->assertGreaterThanOrEqual(
            0,
            $planet->energy()->get(),
            'The base ended in an energy deficit, which throttles its mines and starves everything else.'
        );
    }

    /**
     * Write this player's highscore row the way the scheduled job does, and return the score.
     *
     * On refait le geste de GenerateHighscores pour ce seul compte au lieu de lancer la
     * commande. Ce n'est pas une facilite : l'univers de test contient un joueur dont le score
     * militaire deborde l'entier, parce que getPlayerScoreMilitary() et getPlayerScoreEconomy()
     * n'ont pas le plafond PHP_INT_MAX que getPlayerScore() a recu. Balayer les 3 800 comptes
     * ferait donc echouer ce test pour une raison qui n'a rien a voir avec les pirates.
     */
    private function writeHighscoreFor(int $userId): int
    {
        $highscore = resolve(HighscoreService::class);
        $player = resolve(PlayerServiceFactory::class)->make($userId, true);
        $score = $highscore->getPlayerScore($player);

        Highscore::updateOrCreate(['player_id' => $userId], ['general' => $score]);

        return $score;
    }

    /**
     * Run the growth loop, advancing the clock so the queues actually finish.
     */
    private function runGrowth(int $planetId, int $passes, int $hoursPerPass): PlanetService
    {
        $factory = resolve(PlanetServiceFactory::class);
        $planet = $factory->make($planetId, true);
        $this->assertNotNull($planet);

        for ($i = 0; $i < $passes; $i++) {
            $planet->addResources(new Resources(100000, 100000, 100000, 0));
            $this->growth->grow($planet);

            Date::setTestNow(Date::now()->addHours($hoursPerPass));

            $planet = $factory->make($planetId, true);
            $this->assertNotNull($planet);
        }

        $planet->update();

        return $factory->make($planetId, true) ?? $planet;
    }
}
