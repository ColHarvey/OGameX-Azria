<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Models\User;
use OGame\Models\UserTech;
use OGame\Services\Npc\NpcPopulationService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;

/**
 * Le nouveau joueur qui arrive dans six mois est-il protege ?
 *
 * C'est la question a laquelle tout le mecanisme de seuil adaptatif doit repondre, et elle
 * ne se verifie pas en lisant le code : il faut construire une population, la faire vieillir,
 * et regarder ce que le systeme decide. Un seuil fixe protegerait le debutant aujourd'hui et
 * plus du tout dans six mois, quand l'echelle du serveur aura change sous lui.
 */
class NpcNewPlayerProtectionTest extends AccountTestCase
{
    private SettingsService $settings;

    /**
     * Identifiants des joueurs que ce test a rendus inactifs, a restaurer ensuite.
     *
     * @var array<int, array{id: int, time: string|null}>
     */
    private array $silenced = [];

    /**
     * Les quatre comptes du scenario.
     *
     * @var array<string, User>
     */
    private array $cast = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = resolve(SettingsService::class);
        $this->settings->set('npc_enabled', '1');
        $this->settings->set('npc_median_ratio', '0.80');
        // Plancher neutralise : ce scenario isole le comportement de la mediane, et le
        // plancher le masquerait sur une population aussi modeste.
        $this->settings->set('npc_min_score_fixed', '0');
        $this->settings->set('npc_new_player_days', '14');
        $this->settings->set('npc_spotted_days', '7');
        // Un seul joueur actif suffit a declencher la mediane : le scenario porte
        // precisement sur l'echelle du serveur, pas sur le repli en seuil fixe.
        $this->settings->set('npc_min_active_players', '1');

        $this->isolatePopulation();
        $this->buildCast();
    }

    protected function tearDown(): void
    {
        foreach ($this->cast as $user) {
            DB::table('highscores')->where('player_id', $user->id)->delete();
            DB::table('users_tech')->where('user_id', $user->id)->delete();
            DB::table('planets')->where('user_id', $user->id)->delete();
            DB::table('users')->where('id', $user->id)->delete();
        }

        foreach ($this->silenced as $row) {
            DB::table('users')->where('id', $row['id'])->update(['time' => $row['time']]);
        }

        $this->settings->set('npc_enabled', '0');
        $this->settings->set('npc_min_active_players', '8');

        parent::tearDown();
    }

    /**
     * Assert that the four archetypes are regarded exactly as they should be.
     *
     * Quatre joueurs, quatre situations, et la reponse attendue pour chacun. C'est le
     * scenario minimal qui prouve que les trois conditions — anciennete, activite, score —
     * agissent bien ensemble et non l'une a la place de l'autre.
     */
    public function testTheFourArchetypesAreRegardedCorrectly(): void
    {
        $population = resolve(NpcPopulationService::class);

        // Population : 0, 10, 30, 500. Mediane = (10 + 30) / 2 = 20, seuil = 16.
        $this->assertEquals(20, $population->medianScore(), 'The median was not computed over the four players alone.');
        $this->assertEquals(16, $population->threshold());

        $this->assertEquals(
            NpcPopulationService::STATE_PROTECTED,
            $population->stateOf($this->playerFor('A')),
            'A brand new player with no score was not protected.'
        );

        $this->assertEquals(
            NpcPopulationService::STATE_PROTECTED,
            $population->stateOf($this->playerFor('B')),
            'A recent player below the threshold was not protected.'
        );

        $this->assertEquals(
            NpcPopulationService::STATE_TARGETED,
            $population->stateOf($this->playerFor('C')),
            'An established player above the threshold was not targetable.'
        );

        $this->assertEquals(
            NpcPopulationService::STATE_TARGETED,
            $population->stateOf($this->playerFor('D')),
            'The strongest established player was not targetable.'
        );
    }

    /**
     * Assert that a newcomer stays protected as the server grows around them.
     *
     * C'est le coeur de la question. Le serveur prend de l'ampleur — les anciens multiplient
     * leur score par vingt — et le debutant, lui, n'a pas bouge. Un seuil fixe l'aurait
     * laisse a decouvert le jour ou il aurait franchi une valeur ecrite un an plus tot ; le
     * seuil adaptatif monte avec le serveur et le laisse tranquille.
     */
    public function testANewcomerStaysProtectedWhileTheServerGrows(): void
    {
        $population = resolve(NpcPopulationService::class);

        $thresholdBefore = $population->threshold();
        $this->assertEquals(
            NpcPopulationService::STATE_PROTECTED,
            $population->stateOf($this->playerFor('B')),
            'The newcomer was not protected before the server grew.'
        );

        // Six mois plus tard : les joueurs etablis ont explose, le debutant n'a pas bouge.
        $this->setScore('C', 3000);
        $this->setScore('D', 50000);

        $thresholdAfter = $population->threshold();

        $this->assertGreaterThan(
            $thresholdBefore,
            $thresholdAfter,
            'The threshold did not follow the server as it grew, so it would eventually expose newcomers.'
        );

        $this->assertEquals(
            NpcPopulationService::STATE_PROTECTED,
            $population->stateOf($this->playerFor('B')),
            'The newcomer lost their protection simply because other players grew.'
        );

        $this->assertEquals(
            NpcPopulationService::STATE_TARGETED,
            $population->stateOf($this->playerFor('D')),
            'The strongest player stopped being targetable as the server grew.'
        );
    }

    /**
     * Assert that a young server full of beginners does not open the gate to everyone.
     *
     * Reproduit la situation mesuree sur le serveur reel le 31 aout 2026, trois jours apres
     * son ouverture : treize joueurs actifs, dont sept encore a zero point faute d'avoir
     * commence a produire. La mediane valait zero, et le seuil aussi.
     *
     * Le seuil fixe est un plancher, pas un repli : en dessous, on n'est pas un joueur que
     * les factions regardent, quelle que soit la forme de la population.
     */
    public function testAServerFullOfBeginnersKeepsItsFloor(): void
    {
        $population = resolve(NpcPopulationService::class);

        // Les debutants ne sont pas rares : ils sont majoritaires, comme au lancement.
        for ($i = 1; $i <= 4; $i++) {
            $this->cast['debutant' . $i] = $this->makePlayer(0, 60);
        }

        $this->settings->set('npc_min_score_fixed', '25');

        $this->assertEquals(0, $population->medianScore(), 'The median was expected to sit at zero here.');

        $this->assertEquals(
            25,
            $population->threshold(),
            'A server where most players score zero dropped its threshold to zero, exposing everyone.'
        );

        // Et la consequence qui compte : les debutants restent hors de portee, les joueurs
        // etablis non.
        $this->assertEquals(
            NpcPopulationService::STATE_PROTECTED,
            $population->stateOf($this->playerFor('B')),
            'A player below the floor was exposed.'
        );

        $this->assertEquals(
            NpcPopulationService::STATE_TARGETED,
            $population->stateOf($this->playerFor('C')),
            'An established player above the floor was not targetable.'
        );
    }

    /**
     * Assert that age alone does not expose a player who is still small.
     *
     * Les trois conditions se cumulent, elles ne se remplacent pas. Un compte ancien mais
     * reste modeste n'interesse toujours personne.
     */
    public function testAnOldButStillSmallAccountRemainsProtected(): void
    {
        $population = resolve(NpcPopulationService::class);

        $user = $this->cast['B'];
        $user->created_at = Date::now()->subYear();
        $user->save();

        $this->assertEquals(
            NpcPopulationService::STATE_PROTECTED,
            $population->stateOf($this->playerFor('B')),
            'An old account was exposed despite scoring below the threshold.'
        );
    }

    /**
     * Assert that an account that stopped playing drops out of the reference population.
     *
     * Sans ce filtre, un serveur de treize comptes dont sept sont morts verrait sa mediane
     * s'effondrer, son seuil tomber a zero, et tout le monde devenir eligible — a commencer
     * par le debutant que ce systeme cherche a proteger.
     */
    public function testAnAbandonedAccountLeavesTheReferencePopulation(): void
    {
        $population = resolve(NpcPopulationService::class);
        $this->assertEquals(4, $population->activePlayerCount());

        $user = $this->cast['D'];
        $user->time = (string)Date::now()->subDays(30)->timestamp;
        $user->save();

        $this->assertEquals(
            3,
            $population->activePlayerCount(),
            'A player who stopped logging in was still counted in the reference population.'
        );

        $this->assertEquals(
            NpcPopulationService::STATE_PROTECTED,
            $population->stateOf($this->playerFor('D')),
            'An inactive account was still considered a valid target.'
        );
    }

    /**
     * Silence every other active human so the scenario controls the whole population.
     */
    private function isolatePopulation(): void
    {
        $limit = Date::now()->subDays(7)->timestamp;

        $rows = DB::table('users')
            ->where('is_npc', false)
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
     * Create the four accounts of the scenario.
     */
    private function buildCast(): void
    {
        $this->cast['A'] = $this->makePlayer(0, 0);
        $this->cast['B'] = $this->makePlayer(10, 3);
        $this->cast['C'] = $this->makePlayer(30, 120);
        $this->cast['D'] = $this->makePlayer(500, 200);
    }

    /**
     * Create one active human with a given score and age in days.
     */
    private function makePlayer(int $score, int $ageInDays): User
    {
        $user = User::factory()->create([
            'time' => (string)Date::now()->timestamp,
        ]);

        $user->created_at = Date::now()->subDays($ageInDays);
        $user->save();

        UserTech::create(['user_id' => $user->id]);

        // Le helper cherche une position libre : la fabrique brute laisserait les
        // coordonnees nulles, que le schema refuse.
        $this->createPlanetAtSafeCoordinate($user->id);

        DB::table('highscores')->updateOrInsert(
            ['player_id' => $user->id],
            [
            'general' => $score,
            'economy' => $score,
            'research' => 0,
            'military' => 0,
            'general_rank' => 1,
            'economy_rank' => 1,
            'research_rank' => 1,
            'military_rank' => 1,
            'created_at' => Date::now(),
            'updated_at' => Date::now(),
            ]
        );

        return $user;
    }

    /**
     * Change one player's general score.
     */
    private function setScore(string $label, int $score): void
    {
        DB::table('highscores')
            ->where('player_id', $this->cast[$label]->id)
            ->update(['general' => $score]);
    }

    /**
     * Get a player service for one of the four accounts.
     */
    private function playerFor(string $label): PlayerService
    {
        return resolve(PlayerService::class, ['player_id' => $this->cast[$label]->id]);
    }
}
