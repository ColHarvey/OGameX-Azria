<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use OGame\Services\HighscoreService;
use OGame\Services\Npc\NpcBaseService;
use OGame\Services\Npc\NpcThreatService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;

/**
 * Les ajouts a l'interface s'affichent, et ils s'affichent avec le theme du jeu.
 *
 * Ces tests rendent reellement les pages : ils attrapent une classe CSS inventee, une cle de
 * traduction absente, ou un lien construit a partir d'une valeur nulle — trois erreurs que
 * ni PHPStan ni Pint ne voient, et qui ne se manifesteraient qu'aux yeux d'un joueur.
 */
class NpcInterfaceTest extends AccountTestCase
{
    private SettingsService $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = resolve(SettingsService::class);
        $this->settings->set('npc_enabled', '1');
        $this->settings->set('npc_simulation', '1');
        $this->settings->set('npc_min_active_players', '99999');
        $this->settings->set('npc_min_score_fixed', '0');
        $this->settings->set('npc_new_player_days', '0');
        $this->settings->set('npc_spotted_days', '0');

        // La base de test compte pres de deux mille planetes reparties sur la quasi-totalite
        // des systemes : aucune position ne peut y etre a quinze systemes de tout humain.
        // La contrainte de distance est verifiee separement, dans son propre test, ou elle
        // porte sur la regle et non sur un chiffre que cet univers de test ne permet pas.
        $this->settings->set('npc_seed_min_distance', '0');
    }

    protected function tearDown(): void
    {
        DB::table('npc_threats')->delete();

        $npcIds = DB::table('users')->where('is_npc', true)->pluck('id')->all();

        if ($npcIds !== []) {
            // Les rapports de combat et les champs de debris referencent ces planetes :
            // sans desactiver les cles etrangeres le nettoyage echouerait, et les comptes
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
     * Assert that the threat panel renders with the theme's own building blocks.
     *
     * Les classes verifiees ne sont pas choisies au hasard : content-box-s est l'encadre des
     * pages Chantier et Installations, bar_container et filllevel_bar sont la jauge de soute
     * de cette meme page Flotte. Aucune n'est inventee, donc le panneau suivra le theme du
     * joueur, y compris s'il en change.
     */
    public function testTheThreatPanelRendersWithTheGameTheme(): void
    {
        $response = $this->get('/fleet');
        $response->assertStatus(200);

        $response->assertSee('content-box-s', false);
        $response->assertSee('bar_container', false);
        $response->assertSee('filllevel_bar', false);
        $response->assertSee(__('t_ingame.npc.threat_title'), false);

        // Aucune cle de traduction ne doit fuir a l'ecran.
        $response->assertDontSee('t_ingame.npc.', false);
    }

    /**
     * Assert that the panel stays hidden while the factions are switched off.
     */
    public function testTheThreatPanelIsHiddenWhenFactionsAreDisabled(): void
    {
        $this->settings->set('npc_enabled', '0');

        $response = $this->get('/fleet');
        $response->assertStatus(200);
        $response->assertDontSee(__('t_ingame.npc.threat_title'), false);
    }

    /**
     * Assert that the panel names the band the player has actually reached.
     */
    public function testTheThreatPanelShowsTheBandThePlayerReached(): void
    {
        $player = resolve(PlayerService::class);
        $threat = resolve(NpcThreatService::class);

        for ($i = 0; $i < 10; $i++) {
            $threat->add($player, 'base_destroyed');
        }

        $response = $this->get('/fleet');
        $response->assertStatus(200);
        $response->assertSee(__('t_ingame.npc.band_' . $threat->bandOf($player)), false);
    }

    /**
     * Assert that a pirate base is visible in the galaxy, coloured and labelled.
     */
    public function testAPirateBaseIsVisibleAndLabelledInTheGalaxy(): void
    {
        $base = resolve(NpcBaseService::class)->createBase();
        $this->assertNotNull($base, 'No pirate base could be created.');

        $coordinate = $base->getPlanetCoordinates();

        $response = $this->get('/galaxy?galaxy=' . $coordinate->galaxy . '&system=' . $coordinate->system);
        $response->assertStatus(200);

        // La legende annonce la faction.
        $response->assertSee(__('t_ingame.galaxy.legend_pirate'), false);
        $response->assertSee('status_abbr_pirate', false);
    }

    /**
     * Assert that the highscore shows the faction rows without breaking a single link.
     *
     * Une ligne de faction n'a ni coordonnees ni identifiant de compte : c'est exactement le
     * genre de ligne qui fait tomber un gabarit ecrit pour des joueurs.
     */
    public function testTheHighscoreShowsFactionRowsWithoutBreaking(): void
    {
        $this->giveAPirateBaseAScoreOnTheFirstPage();

        // Page 1 explicitement : la page du classement s'ouvre par defaut sur le rang du
        // joueur courant, et le score donne a la faction vise la premiere page.
        $response = $this->get('/highscore?page=1');
        $response->assertStatus(200);
        $response->assertSee(__('t_ingame.highscore.faction_pirate'), false);
    }

    /**
     * Assert that the faction rows disappear when the setting says so.
     */
    public function testFactionRowsCanBeSwitchedOff(): void
    {
        $this->giveAPirateBaseAScoreOnTheFirstPage();

        // Le classement est mis en cache cinq minutes : sans purge, la page servie serait
        // celle d'avant le changement de reglage, et le test ne prouverait rien.
        $this->settings->set('npc_highscore_rows', '0');
        Cache::flush();

        // Page 1 explicitement : la page du classement s'ouvre par defaut sur le rang du
        // joueur courant, et le score donne a la faction vise la premiere page.
        $response = $this->get('/highscore?page=1');
        $response->assertStatus(200);
        $response->assertDontSee(__('t_ingame.highscore.faction_pirate'), false);

        $this->settings->set('npc_highscore_rows', '1');
        Cache::flush();
    }

    /**
     * Assert that a faction whose average sits outside a page is not shown on that page.
     *
     * Sans ce controle la ligne se repeterait sur chacune des pages du classement.
     */
    public function testAFactionOutsideThePageRangeIsNotShownOnIt(): void
    {
        // Base neuve, score nul : elle se situe sous le dernier joueur de la premiere page.
        resolve(NpcBaseService::class)->createBase();
        Cache::flush();

        $highscore = resolve(HighscoreService::class);
        $highscore->setHighscoreType(0);
        $rows = $highscore->getHighscorePlayers(100, 1);

        $lowest = (int)($rows[count($rows) - 1]['points'] ?? 0);
        $this->assertGreaterThan(0, $lowest, 'The first page was expected to end above zero.');

        foreach ($rows as $row) {
            $this->assertFalse(
                $row['is_faction'] ?? false,
                'A faction scoring below the whole page was still listed on it.'
            );
        }
    }

    /**
     * Give a pirate base a score that lands inside the first page of the highscore.
     */
    private function giveAPirateBaseAScoreOnTheFirstPage(): void
    {
        $base = resolve(NpcBaseService::class)->createBase();
        $this->assertNotNull($base, 'No pirate base could be created.');

        $highscore = resolve(HighscoreService::class);
        $highscore->setHighscoreType(0);
        $rows = $highscore->getHighscorePlayers(100, 1);

        $highest = (int)($rows[0]['points'] ?? 0);
        $lowest = (int)($rows[count($rows) - 1]['points'] ?? 0);
        $middle = (int)(($highest + $lowest) / 2);

        DB::table('highscores')->updateOrInsert(
            ['player_id' => $base->getPlayer()?->getId()],
            [
                'general' => $middle,
                'economy' => 0,
                'research' => 0,
                'military' => 0,
                'created_at' => Date::now(),
                'updated_at' => Date::now(),
            ]
        );

        Cache::flush();
    }
}
