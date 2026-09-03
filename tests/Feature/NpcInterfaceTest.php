<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use OGame\Models\User;
use OGame\Services\HighscoreService;
use OGame\Services\InitialUserDataService;
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
        $response = $this->get('/overview');
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

        $response = $this->get('/overview');
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

        $response = $this->get('/overview');
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

        // Sans ces deux cles, la branche pirate du JS afficherait « undefined » a la
        // place de l'abreviation, ce qu'aucun autre test ne verrait.
        $response->assertSee('LOCA_GALAXY_PLAYER_STATUS_P', false);
        $response->assertSee('LOCA_GALAXY_LEGEND_PIRATE', false);
    }

    /**
     * Assert that a pirate base is never offered the courtesies owed to a player.
     *
     * Une base n'est pas quelqu'un. Lui proposer une demande d'ami, un message prive ou
     * un rang au classement individuel donne au joueur l'impression exactement inverse de
     * celle qu'on veut : qu'il y a une personne derriere. Le rang, en prime, n'a aucun
     * sens pour un compte que le calcul des rangs met volontairement a zero.
     */
    public function testAPirateBaseIsNotOfferedThePlayerCourtesies(): void
    {
        $base = resolve(NpcBaseService::class)->createBase();
        $this->assertNotNull($base, 'No pirate base could be created.');

        $coordinate = $base->getPlanetCoordinates();

        $response = $this->post('/ajax/galaxy', [
            'galaxy' => $coordinate->galaxy,
            'system' => $coordinate->system,
        ]);
        $response->assertStatus(200);

        $row = null;
        foreach ($response->json('system.galaxyContent') ?? [] as $candidate) {
            if ((int)($candidate['position'] ?? 0) === $coordinate->position) {
                $row = $candidate;
                break;
            }
        }

        $this->assertNotNull($row, 'The pirate base was not present in the galaxy payload.');

        $actions = $row['player']['actions'] ?? null;
        $this->assertNotNull($actions, 'The pirate row carried no player actions to check.');

        foreach (['buddies', 'ignore', 'message', 'highscore'] as $courtesy) {
            $this->assertFalse(
                $actions[$courtesy]['available'] ?? true,
                'A pirate base was offered the ' . $courtesy . ' action, which only a player should get.'
            );
        }

        // Et le drapeau dont depend l'abreviation : sans lui la base ressort etiquetee
        // (n), debutante, parce que son score est bas.
        $this->assertTrue($row['player']['isPirate'] ?? false, 'The pirate row was not flagged as a faction base.');
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
     *
     * Le barème est pose par le test lui-meme, et c'est la seule facon honnete de proceder.
     * La premiere version se contentait de verifier que la premiere page finissait au-dessus
     * de zero : elle passait ou echouait selon les tests joues avant elle, puisque la base de
     * test est partagee et que plusieurs suites remettent les classements a zero. Un test qui
     * affirme une condition sur l'etat ambiant ne prouve rien — il faut l'etablir.
     */
    public function testAFactionOutsideThePageRangeIsNotShownOnIt(): void
    {
        $touches = $this->giveHumanPlayersAScoreLadder();

        try {
            // Base neuve, score nul : elle se situe sous le dernier joueur de la page.
            resolve(NpcBaseService::class)->createBase();

            // **Les rangs se recalculent apres la creation, pas avant.** Une base creee apres le
            // dernier passage ne porte aucun rang, et la page l'affiche alors en queue a zero
            // point : l'essai echouait sur son propre barème, qui etait pourtant intact.
            //
            // En production le planificateur repasse de lui-meme. Ici, c'est a l'essai de le dire —
            // et l'ordre compte autant que le contenu.
            Artisan::call('ogamex:scheduler:generate-highscore-ranks');
            Cache::flush();

            $highscore = resolve(HighscoreService::class);
            $highscore->setHighscoreType(0);
            $rows = $highscore->getHighscorePlayers(100, 1);

            $lowest = (int)($rows[count($rows) - 1]['points'] ?? 0);
            $this->assertGreaterThan(0, $lowest, 'The score ladder this test lays down did not take effect.');

            foreach ($rows as $row) {
                $this->assertFalse(
                    $row['is_faction'] ?? false,
                    'A faction scoring below the whole page was still listed on it.'
                );
            }
        } finally {
            // On rend la base telle qu'on l'a trouvee, rangs compris. Laisser des rangs en
            // escalier au-dessus de scores remis a zero est exactement le genre d'heritage
            // qui fait echouer un autre test bien plus tard, sans rapport apparent — la base
            // de test est partagee entre toutes les suites.
            DB::table('highscores')->whereIn('player_id', $touches)->update(['general' => 0]);
            Artisan::call('ogamex:scheduler:generate-highscore-ranks');
            Cache::flush();
        }
    }

    /**
     * Complete l univers jusqu au nombre de joueurs classables voulu.
     *
     * L essai etablit ce qu il exige plutot que de l esperer : les chercher revenait a dependre de
     * ce que les essais precedents avaient laisse dans la base.
     */
    private function ensureTheUniverseHoldsRankablePlayers(int $wanted): void
    {
        $manquants = $wanted - count($this->rankablePlayerIds());

        for ($rang = 0; $rang < $manquants; $rang++) {
            // Un compte complet : la page du classement saute les comptes sans planete ni fiche
            // technique, et un joueur incomplet ne compterait donc pas.
            $utilisateur = User::factory()->create();

            resolve(InitialUserDataService::class)->createFor($utilisateur);
        }
    }

    /**
     * Tous ceux a qui la commande de rangs donnera un rang.
     *
     * ## Pourquoi cette liste est plus large que les joueurs affichables
     *
     * `rankablePlayerIds()` decrit ce que la **page** sait afficher : une fiche technique et une
     * planete. La commande de rangs, elle, classe tout joueur non-PNJ, non-Legor, **qui porte une
     * ligne de classement** — sans rien exiger d'autre.
     *
     * Un joueur laisse par un essai voisin avec une ligne a zero et sans planete recevait donc un
     * rang sans figurer au barème, et finissait la page a zero point. L'essai echouait alors sur une
     * condition qu'il croyait avoir etablie.
     *
     * C'est la meme lecon que deux fois deja dans ce fichier : **un essai etablit ce qu'il exige, il
     * ne l'espere pas.** Il fallait l'appliquer a la population que la commande classe, pas a celle
     * que la page affiche.
     *
     * @return array<int, int>
     */
    private function everyPlayerTheRankingCommandWillRank(): array
    {
        $dejaNotes = DB::table('highscores')
            ->join('users', 'users.id', '=', 'highscores.player_id')
            ->where('users.is_npc', false)
            ->where('users.username', '!=', User::SYSTEM_ACCOUNT_USERNAME)
            ->pluck('users.id')
            ->all();

        $tous = array_unique(array_merge(
            $this->rankablePlayerIds(),
            array_map(static fn (mixed $id): int => (int)$id, $dejaNotes)
        ));

        // Trie, parce que le barème decroissant depend du rang dans cette liste : deux passages
        // doivent poser exactement les memes scores.
        sort($tous);

        return $tous;
    }

    /**
     * Les joueurs que la page du classement peut afficher.
     *
     * @return array<int, int>
     */
    private function rankablePlayerIds(): array
    {
        $ids = DB::table('users')
            ->join('users_tech', 'users_tech.user_id', '=', 'users.id')
            ->join('planets', 'planets.user_id', '=', 'users.id')
            ->where('users.is_npc', false)
            ->where('users.username', '!=', User::SYSTEM_ACCOUNT_USERNAME)
            ->distinct()
            ->orderBy('users.id')
            ->pluck('users.id')
            ->all();

        return array_map(static fn (mixed $id): int => (int)$id, $ids);
    }

    /**
     * Give enough human players a descending score for the first page to end above zero.
     *
     * @return array<int, int> Les joueurs touches, a remettre a zero ensuite.
     */
    private function giveHumanPlayersAScoreLadder(): array
    {
        // Des joueurs reellement classables, et non les premiers venus : la page du classement
        // exige une fiche technique et saute les comptes sans planete. Un barème pose sur des
        // comptes vides serait ecarte a l'affichage, et la page finirait malgre tout sur des
        // joueurs a zero.
        // **L'essai etablit ce qu'il exige, il ne l'espere pas.** Le commentaire ci-dessus le disait
        // deja du bareme ; il vaut autant pour les joueurs eux-memes. Les chercher dans la base
        // revenait a dependre de ce que les essais precedents y avaient laisse — vrai tant que la
        // suite tournait en un seul processus, faux des qu'elle se partage entre plusieurs bases.
        $this->ensureTheUniverseHoldsRankablePlayers(101);

        $ids = $this->everyPlayerTheRankingCommandWillRank();

        $touches = [];

        foreach (array_values($ids) as $rang => $id) {
            $id = (int)$id;
            $touches[] = $id;

            DB::table('highscores')->updateOrInsert(
                ['player_id' => $id],
                [
                    // **Un barème pose sur TOUS les joueurs classables, pas sur les cent vingt
                    // premiers.** La page en affiche cent, triees par rang : si un joueur hors du
                    // barème obtenait un rang, il finirait la page a zero point. Le plancher a un
                    // garde le barème valide meme dans un univers de plusieurs milliers de comptes.
                    'general' => max(1, 1000000 - $rang * 10),
                    'economy' => 0,
                    'research' => 0,
                    'military' => 0,
                    'created_at' => Date::now(),
                    'updated_at' => Date::now(),
                ]
            );
        }

        // La page du classement est triee par general_rank et ne retient que les lignes dont
        // les quatre rangs sont poses : ecrire les points ne suffit pas, il faut faire
        // recalculer les rangs. On emprunte la commande de production plutot que d'ecrire les
        // rangs a la main — c'est elle qui decide, entre autres, que les PNJ portent le rang 0.
        Artisan::call('ogamex:scheduler:generate-highscore-ranks');
        Cache::flush();

        return $touches;
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
