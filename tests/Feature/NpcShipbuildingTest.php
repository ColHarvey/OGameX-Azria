<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\Resources;
use OGame\Services\Npc\NpcBaseService;
use OGame\Services\Npc\NpcGrowthService;
use OGame\Services\PlanetService;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;

/**
 * Une base finit-elle vraiment par sortir des vaisseaux ?
 *
 * C'est la question qui decide si tout le reste sert a quelque chose. Un raid se compose de
 * vaisseaux reellement presents sur la base ; sans chantier qui produit, le systeme entier
 * reste decoratif.
 *
 * Le defaut que ce test verrouille etait total et silencieux. Un compte pilote par le
 * serveur nait avec toutes ses recherches a zero, et le plan de croissance n'en faisait
 * aucune. Or le petit transporteur reclame la combustion 2, le chasseur leger la combustion
 * 1, le laser leger la technologie laser 3 : seul le lanceur de missiles, qui n'exige qu'un
 * chantier, restait constructible. Les bases auraient accumule des mines et vingt lanceurs,
 * sans un seul vaisseau, donc sans jamais pouvoir partir en raid — et rien dans le journal
 * ne l'aurait signale, la ligne « rien d abordable » etant indistinguable d'une base qui
 * economise.
 */
class NpcShipbuildingTest extends AccountTestCase
{
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

        // Le plafond de maturite arreterait la base bien avant le chantier : ce test porte
        // sur la chaine de construction, pas sur le plafond, qui a son propre test.
        $this->settings->set('npc_min_score_fixed', '100000');
        $this->settings->set('npc_maturity_ratio', '10');
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        $npcIds = DB::table('users')->where('is_npc', true)->pluck('id')->all();

        if ($npcIds !== []) {
            Schema::disableForeignKeyConstraints();

            $planetIds = DB::table('planets')->whereIn('user_id', $npcIds)->pluck('id')->all();
            DB::table('building_queues')->whereIn('planet_id', $planetIds)->delete();
            DB::table('unit_queues')->whereIn('planet_id', $planetIds)->delete();
            DB::table('research_queues')->whereIn('planet_id', $planetIds)->delete();
            DB::table('npc_base_snapshots')->whereIn('planet_id', $planetIds)->delete();
            DB::table('highscores')->whereIn('player_id', $npcIds)->delete();
            DB::table('users_tech')->whereIn('user_id', $npcIds)->delete();
            DB::table('planets')->whereIn('user_id', $npcIds)->delete();
            DB::table('users')->whereIn('id', $npcIds)->delete();
        }

        $this->settings->set('npc_enabled', '0');
        $this->settings->set('npc_min_score_fixed', '25');
        $this->settings->set('npc_maturity_ratio', '1.30');

        parent::tearDown();
    }

    /**
     * Assert that a base left alone eventually researches and puts real ships in orbit.
     */
    public function testABaseEventuallyBuildsRealShips(): void
    {
        $base = resolve(NpcBaseService::class)->createBase();
        $this->assertNotNull($base, 'No pirate base could be created.');

        $planetId = $base->getPlanetId();
        $userId = $base->getPlayer()?->getId();
        $this->assertNotNull($userId, 'The base has no owner.');

        $planet = $this->runGrowthFor($planetId, 120, 4);

        $player = resolve(PlayerServiceFactory::class)->make($userId, true);

        // Le laboratoire, sans lequel rien de ce qui suit n'existe.
        $this->assertGreaterThanOrEqual(
            1,
            $planet->getObjectLevel('research_lab'),
            'The base never raised a research lab, so it can never unlock a single ship.'
        );

        $this->assertGreaterThanOrEqual(
            1,
            $player->getResearchLevel('energy_technology'),
            'The base never researched energy technology, the root of the whole drive chain.'
        );

        $this->assertGreaterThanOrEqual(
            2,
            $player->getResearchLevel('combustion_drive'),
            'The base never reached combustion drive 2, so the small cargo stays out of reach forever.'
        );

        // Et la conclusion qui compte : des vaisseaux reels, en orbite, avec lesquels un raid
        // pourra effectivement partir.
        $vaisseaux = $planet->getObjectAmount('small_cargo') + $planet->getObjectAmount('light_fighter');

        $this->assertGreaterThan(
            0,
            $vaisseaux,
            'The base built no ship at all, so no raid could ever leave it.'
        );

        // Le transporteur en particulier : sans fret, la regle du butin rend un raid
        // parfaitement inoffensif.
        $this->assertGreaterThan(
            0,
            $planet->getObjectAmount('small_cargo'),
            'The base built no cargo, so any raid it sent would come home empty.'
        );
    }

    /**
     * Assert that the base defends itself before it arms itself.
     *
     * L'ordre compte : une base sans defense se fait ramasser avant d'avoir eu le temps
     * d'exister, et le contenu disparait avant d'avoir servi.
     */
    public function testABaseArmsItsDefencesBeforeItsFleet(): void
    {
        $base = resolve(NpcBaseService::class)->createBase();
        $this->assertNotNull($base, 'No pirate base could be created.');

        $planet = $this->runGrowthFor($base->getPlanetId(), 60, 4);

        $this->assertGreaterThan(
            0,
            $planet->getObjectAmount('rocket_launcher'),
            'The base armed no defence at all.'
        );
    }

    /**
     * Run the growth loop, advancing the clock so the queues actually finish.
     *
     * Les ressources sont completees a chaque passage. Ce test ne mesure pas la vitesse
     * d'accumulation d'une base — ce que fait le serveur reel, et que le plafond de maturite
     * encadre — mais l'ordre des decisions et le fait qu'elles aboutissent.
     */
    private function runGrowthFor(int $planetId, int $passes, int $hoursPerPass): PlanetService
    {
        $planetServiceFactory = resolve(PlanetServiceFactory::class);
        $planet = $planetServiceFactory->make($planetId, true);
        $this->assertNotNull($planet);

        for ($i = 0; $i < $passes; $i++) {
            $planet->addResources(new Resources(200000, 200000, 200000, 0));

            $this->growth->grow($planet);

            Date::setTestNow(Date::now()->addHours($hoursPerPass));

            $planet = $planetServiceFactory->make($planetId, true);
            $this->assertNotNull($planet);
        }

        $planet->update();

        return $planetServiceFactory->make($planetId, true) ?? $planet;
    }
}
