<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use OGame\Models\NpcBaseSnapshot;
use OGame\Services\Npc\NpcBaseService;
use OGame\Services\Npc\NpcColonisationService;
use OGame\Services\PlanetService;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;

/**
 * L'essaimage : une base qui a prospere finit par en fonder une autre.
 *
 * C'est la seule facon dont la menace grandit d'elle-meme. Sans lui le nombre de bases est
 * fixe, et un serveur qui progresse finit par les depasser definitivement.
 *
 * Rien n'est simule : le vaisseau de colonisation est fabrique par le chantier comme n'importe
 * quelle unite et part en mission de colonisation ordinaire, avec un vrai temps de vol. La
 * planete n'existe qu'a l'arrivee de la flotte.
 */
class NpcSwarmTest extends AccountTestCase
{
    private SettingsService $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = resolve(SettingsService::class);
        $this->settings->set('npc_enabled', '1');
        $this->settings->set('npc_seed_min_distance', '0');
        $this->settings->set('npc_swarm_enabled', '1');
        $this->settings->set('npc_swarm_delay_days', '1');
    }

    protected function tearDown(): void
    {
        Date::setTestNow();
        $this->settings->set('npc_swarm_enabled', '0');
        $this->settings->set('npc_enabled', '0');

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
     * Assert that a base which has earned it sends a real colonisation mission.
     */
    public function testAMatureBaseSendsARealColonyShip(): void
    {
        $base = $this->prepareMatureBase();

        $partis = resolve(NpcColonisationService::class)->swarm(false);

        $this->assertNotEmpty($partis, 'A base that met every condition sent no colony ship.');

        // La mission est une vraie mission de colonisation, pas une planete apparue de nulle
        // part : la position ne sera occupee qu'a l'arrivee de la flotte.
        $mission = DB::table('fleet_missions')
            ->where('planet_id_from', $base->getPlanetId())
            ->where('mission_type', 7)
            ->first();

        $this->assertNotNull($mission, 'No colonisation mission was created for the base.');
        $this->assertSame(0, (int)$mission->processed, 'The colonisation was resolved instantly instead of flying.');
        $this->assertGreaterThan(
            (int)$mission->time_departure,
            (int)$mission->time_arrival,
            'The colony ship arrived at the same instant it left, so no real flight took place.'
        );
    }

    /**
     * Assert that nothing leaves while swarming is switched off.
     */
    public function testNothingLeavesWhileSwarmingIsDisabled(): void
    {
        $base = $this->prepareMatureBase();
        $this->settings->set('npc_swarm_enabled', '0');

        $this->assertSame([], resolve(NpcColonisationService::class)->swarm(false));

        // Compte restreint a cette base : l'univers de test contient deja des colonisations
        // faites par d'autres suites, et les compter toutes ne prouverait rien.
        $this->assertSame(
            0,
            DB::table('fleet_missions')
                ->where('planet_id_from', $base->getPlanetId())
                ->where('mission_type', 7)
                ->count()
        );
    }

    /**
     * Assert that a base which only just reached its ceiling stays home.
     *
     * L'essaimage recompense une base qu'on a laissee tranquille. Une base qui vient
     * d'atteindre son plafond a encore mieux a faire de ses ressources, et sans ce delai le
     * nombre de bases exploserait des la premiere semaine.
     */
    public function testABaseThatJustReachedItsCeilingStaysHome(): void
    {
        $base = $this->prepareMatureBase();

        // On ne garde qu'un releve tout frais : la base est au plafond, mais depuis dix
        // minutes.
        NpcBaseSnapshot::query()->where('planet_id', $base->getPlanetId())->delete();
        $this->recordSnapshot($base, Date::now()->subMinutes(10));

        $this->assertSame([], resolve(NpcColonisationService::class)->swarm(false));
    }

    /**
     * Assert that a base with no colony ship cannot colonise, however mature it is.
     */
    public function testWithoutAColonyShipNothingHappens(): void
    {
        $base = $this->prepareMatureBase();
        $base->removeUnit('colony_ship', 1, true);

        $this->assertSame([], resolve(NpcColonisationService::class)->swarm(false));
    }

    /**
     * Build a base that meets every condition for swarming.
     */
    private function prepareMatureBase(): PlanetService
    {
        $base = resolve(NpcBaseService::class)->createBase();
        $this->assertNotNull($base, 'No pirate base could be created.');

        // De quoi voler : un vaisseau, l'astrophysique qui autorise une colonie, et du
        // carburant.
        $base->addUnit('colony_ship', 1);
        $base->addResources(new \OGame\Models\Resources(100000, 100000, 100000, 0));

        $owner = $base->getPlayer();
        $this->assertNotNull($owner);

        DB::table('users_tech')
            ->where('user_id', $owner->getId())
            ->update(['astrophysics' => 4, 'impulse_drive' => 3, 'espionage_technology' => 4]);

        // Et l'anciennete au plafond, qui se lit dans les releves horaires.
        for ($heures = 30; $heures >= 0; $heures -= 3) {
            $this->recordSnapshot($base, Date::now()->subHours($heures));
        }

        return $base;
    }

    /**
     * Record one snapshot showing the base sitting at its ceiling.
     */
    private function recordSnapshot(PlanetService $base, \Illuminate\Support\Carbon $quand): void
    {
        NpcBaseSnapshot::create([
            'user_id' => $base->getPlayer()?->getId(),
            'planet_id' => $base->getPlanetId(),
            'score' => 1000,
            'maturity' => 100,
            'buildings' => 20,
            'ships' => 1,
            'defences' => 20,
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
            'action' => 'plafond atteint',
            'detail' => '100%',
            'observed_at' => $quand,
        ]);
    }
}
