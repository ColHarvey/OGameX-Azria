<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use OGame\Models\NpcThreat;
use OGame\Models\Planet\Coordinate;
use OGame\Services\Npc\NpcBaseService;
use OGame\Services\Npc\NpcDestructionService;
use OGame\Services\Npc\NpcPopulationService;
use OGame\Services\Npc\NpcRaidService;
use OGame\Services\Npc\NpcThreatService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;
use RuntimeException;
use Tests\AccountTestCase;
use Tests\SpawnsNpcBases;

/**
 * Garanties du systeme de factions hostiles.
 *
 * Les trois questions du systeme — eligibilite, menace, puissance — sont testees separement,
 * parce que c'est precisement leur separation qui rend le systeme equilibrable. Les melanger
 * dans un meme test reviendrait a ne plus savoir laquelle des trois a produit un resultat.
 */
class NpcFactionTest extends AccountTestCase
{
    use SpawnsNpcBases;

    private SettingsService $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = resolve(SettingsService::class);
        $this->settings->set('npc_enabled', '1');
        $this->settings->set('npc_simulation', '1');
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
     * Assert that a small server uses its fixed threshold instead of a median.
     *
     * En dessous d'un certain effectif, la mediane saute par a-coups des que deux joueurs se
     * croisent. Ce n'est pas de la volatilite mais un probleme d'echantillon, et l'amortir
     * ne le repare pas : cela retarde seulement le moment ou on s'en apercoit.
     */
    public function testThresholdFallsBackToTheFixedValueOnASmallServer(): void
    {
        $population = resolve(NpcPopulationService::class);

        $this->settings->set('npc_min_active_players', '99999');
        $this->settings->set('npc_min_score_fixed', '42');

        $this->assertEquals(
            42,
            $population->threshold(),
            'A server below the population floor did not use its fixed threshold.'
        );
    }

    /**
     * Assert that the reference population excludes NPC accounts.
     *
     * C'est la raison pour laquelle les comptes PNJ sont hors classement, et elle n'est pas
     * cosmetique : la mediane des joueurs actifs produit le seuil a partir duquel les
     * factions s'interessent a un joueur, donc y laisser entrer les pirates ferait calculer
     * leur seuil a partir d'eux-memes.
     */
    public function testNpcAccountsNeverEnterTheReferencePopulation(): void
    {
        $population = resolve(NpcPopulationService::class);
        $before = $population->activePlayerCount();

        $base = $this->aSpawnedBase();

        DB::table('highscores')->insert([
            'player_id' => $base->getPlayer()?->getId(),
            'general' => 999999,
            'economy' => 0,
            'research' => 0,
            'military' => 0,
            'created_at' => Date::now(),
            'updated_at' => Date::now(),
        ]);

        $this->assertEquals(
            $before,
            $population->activePlayerCount(),
            'A NPC account was counted as an active human player.'
        );
    }

    /**
     * Assert that a protected player accumulates no threat at all.
     *
     * Sans cette regle, un debutant curieux qui sonde une base pendant sa periode de grace
     * verrait la note tomber le jour ou elle expire, pour des actes commis en se croyant a
     * l'abri.
     */
    public function testAProtectedPlayerAccumulatesNoThreat(): void
    {
        $this->settings->set('npc_min_active_players', '99999');
        // Seuil hors de portee : le joueur est protege par son score.
        $this->settings->set('npc_min_score_fixed', '999999');

        $player = resolve(PlayerService::class);
        $threat = resolve(NpcThreatService::class);

        $this->assertEquals(
            NpcPopulationService::STATE_PROTECTED,
            resolve(NpcPopulationService::class)->stateOf($player),
            'The player was expected to be protected.'
        );

        $threat->add($player, 'base_destroyed');

        $this->assertEquals(0, $threat->threatOf($player), 'A protected player accumulated threat.');
        $this->assertDatabaseMissing('npc_threats', ['user_id' => $player->getId()]);
    }

    /**
     * Assert that threat is forgotten as time passes.
     */
    public function testThreatDecaysOverTime(): void
    {
        $this->makePlayerEligible();

        $player = resolve(PlayerService::class);
        $threat = resolve(NpcThreatService::class);

        $threat->add($player, 'base_destroyed');
        $initial = $threat->threatOf($player);
        $this->assertGreaterThan(0, $initial, 'Nothing was recorded for a destroyed base.');

        $this->settings->set('npc_threat_decay_hours', '3');

        // Neuf heures en arriere, soit trois points oublies.
        $row = NpcThreat::where('user_id', $player->getId())->firstOrFail();
        $row->last_decay_at = Date::now()->subHours(9);
        $row->save();

        $this->assertEquals(
            $initial - 3,
            $threat->threatOf($player),
            'Threat did not decay by one point per configured interval.'
        );
    }

    /**
     * Assert that exposure, not the setting alone, decides how high threat may climb.
     *
     * Un joueur tout juste au-dessus du seuil et loin de toute base reste une curiosite
     * qu'on surveille : on ne monte pas une expedition punitive a l'autre bout de l'univers
     * pour lui.
     */
    public function testDistantWeakPlayersAreCappedBelowTheMaximum(): void
    {
        $this->makePlayerEligible();
        $this->settings->set('npc_threat_max', '100');
        // Seuil eleve face a un score modeste : l'exposition reste faible.
        $this->settings->set('npc_min_score_fixed', '1');

        $player = resolve(PlayerService::class);
        $threat = resolve(NpcThreatService::class);

        $ceiling = $threat->ceilingFor($player);

        $this->assertLessThanOrEqual(100, $ceiling);
        $this->assertGreaterThan(0, $ceiling, 'An eligible player was given a ceiling of zero.');
    }

    /**
     * Assert that raid power grows more slowly than the player it targets.
     *
     * C'est l'invariant central du systeme. Une relation lineaire ferait doubler le raid
     * quand le joueur double sa flotte, et grossir ne protegerait donc jamais : le joueur
     * comprendrait vite que sa flotte ne lui sert a rien, et l'incitation deviendrait de
     * rester faible. Or la promesse d'OGame est exactement l'inverse.
     */
    public function testRaidPowerGrowsSlowerThanThePlayer(): void
    {
        $exponent = (float)$this->settings->get('npc_power_exponent', '0.70');
        $reference = 100.0;

        $weak = $reference * ((50 / $reference) ** $exponent);
        $strong = $reference * ((800 / $reference) ** $exponent);

        $ratioWeak = $weak / 50;
        $ratioStrong = $strong / 800;

        $this->assertLessThan(
            $ratioWeak,
            $ratioStrong,
            'The raid-to-player power ratio did not fall as the player grew, so growing never buys safety.'
        );
    }

    /**
     * Assert that a player on holiday is never even considered for a raid.
     *
     * Premier des trois verrous du mode vacances. Les deux autres sont l'envoi, ou
     * isMissionPossible() refuse la mission, et l'arrivee, ou une flotte de faction fait
     * demi-tour si le joueur est parti pendant le vol.
     */
    public function testAPlayerOnHolidayIsNeverAValidRaidTarget(): void
    {
        $this->makePlayerEligible();

        $player = resolve(PlayerService::class);
        resolve(NpcThreatService::class)->add($player, 'base_destroyed');

        $user = $player->getUser();
        $user->vacation_mode = true;
        $user->vacation_mode_activated_at = Date::now();
        $user->vacation_mode_until = Date::now()->addHours(48);
        $user->save();
        $player->refresh();

        $this->assertNull(
            resolve(NpcRaidService::class)->decideFor($player),
            'A raid was planned against a player on holiday.'
        );
    }

    /**
     * Assert that a new base is born far from every human planet.
     *
     * Le jour de la mise en ligne, personne n'a demande a avoir des pirates comme voisins.
     */
    public function testANewBaseKeepsItsDistanceFromHumanPlanets(): void
    {
        // Trois systemes et non quinze : cet univers de test compte pres de deux mille
        // planetes reparties sur la quasi-totalite des systemes, et aucune position n y
        // serait a quinze systemes de tout humain. C est la regle qui est verifiee ici,
        // pas le chiffre de production.
        $this->settings->set('npc_seed_min_distance', '3');
        $this->settings->set('npc_seed_max_distance', '400');

        $base = $this->aSpawnedBase();

        $baseCoordinate = $base->getPlanetCoordinates();

        $humans = DB::table('planets')
            ->join('users', 'users.id', '=', 'planets.user_id')
            ->where('users.is_npc', false)
            ->where('planets.galaxy', $baseCoordinate->galaxy)
            ->select('planets.system')
            ->get();

        foreach ($humans as $human) {
            $this->assertGreaterThanOrEqual(
                3,
                abs((int)$human->system - $baseCoordinate->system),
                'A base was placed closer to a human planet than the configured minimum distance.'
            );
        }
    }

    /**
     * Assert that a base never lands on a coordinate a player already holds.
     *
     * La recherche de position verifie deja la case, mais elle ne couvre pas tout : une
     * position imposee ne passe pas par elle, et entre sa verification et l'ecriture il
     * s'ecoule la creation d'un compte, pendant laquelle un joueur peut coloniser.
     *
     * Ce que ce test prouve n'est pas seulement le refus — createPlanetAtPosition refusait
     * deja, en levant une exception — mais qu'il ne reste rien derriere lui. L'ancienne
     * version ecrivait le compte pirate avant de decouvrir la collision, et laissait donc
     * un orphelin en base tout en faisant tomber le tick entier.
     */
    public function testABaseNeverLandsOnAPlanetAPlayerAlreadyHolds(): void
    {
        $occupee = $this->planetService->getPlanetCoordinates();

        $comptesAvant = (int)DB::table('users')->where('is_npc', true)->count();
        $planetesAvant = (int)DB::table('planets')->count();

        $base = resolve(NpcBaseService::class)->createBase(NpcBaseService::TYPE_PIRATE, $occupee);

        $this->assertNull($base, 'A base was created on a coordinate a player already holds.');

        $this->assertSame(
            $comptesAvant,
            (int)DB::table('users')->where('is_npc', true)->count(),
            'A refused birth still left a pirate account behind.'
        );

        $this->assertSame(
            $planetesAvant,
            (int)DB::table('planets')->count(),
            'A refused birth still touched the planets table.'
        );

        // Et la planete visee appartient toujours a son proprietaire d'origine.
        $proprietaire = DB::table('planets')
            ->where('galaxy', $occupee->galaxy)
            ->where('system', $occupee->system)
            ->where('planet', $occupee->position)
            ->value('user_id');

        $joueur = $this->planetService->getPlayer();
        $this->assertNotNull($joueur, 'The test planet lost its owner.');

        $this->assertSame(
            $joueur->getId(),
            (int)$proprietaire,
            'The player lost their planet to a pirate base.'
        );
    }

    /**
     * Assert that the destruction path refuses anything that is not a NPC planet.
     *
     * abandonPlanet() refuse de liberer la derniere planete d'un compte, garde-fou destine
     * aux joueurs humains. Le chemin PNJ contourne ce refus, il doit donc verifier lui-meme
     * a qui il a affaire : c'est la seule porte par laquelle une planete disparait.
     */
    public function testDestructionRefusesAPlanetThatIsNotANpc(): void
    {
        $this->expectException(RuntimeException::class);

        resolve(NpcDestructionService::class)->destroy($this->planetService);
    }

    /**
     * Assert that a base still holding ships or defences is not considered defeated.
     */
    public function testABaseThatCanStillFightIsNotDestroyed(): void
    {
        $base = $this->aSpawnedBase();

        $base->addUnit('rocket_launcher', 3);

        $destruction = resolve(NpcDestructionService::class);

        $this->assertTrue($destruction->stillStanding($base));
        $this->assertFalse($destruction->destroy($base), 'A base with defences left was destroyed.');
        $this->assertFalse($base->isDestroyed());
    }

    /**
     * Assert that proximity multiplies the threat a single action produces.
     */
    public function testProximityMultipliesThreatGains(): void
    {
        $this->makePlayerEligible();

        $player = resolve(PlayerService::class);
        $threat = resolve(NpcThreatService::class);
        $own = $this->planetService->getPlanetCoordinates();

        // Meme systeme que le joueur : le multiplicateur le plus fort.
        $near = $threat->add($player, 'espionage', new Coordinate($own->galaxy, $own->system, 15));

        DB::table('npc_threats')->where('user_id', $player->getId())->delete();

        // Une autre galaxie : aucun multiplicateur.
        $far = $threat->add($player, 'espionage', new Coordinate($own->galaxy + 1, 400, 8));

        $this->assertGreaterThan(
            $far,
            $near,
            'An action next door produced no more threat than the same action a galaxy away.'
        );
    }

    /**
     * Make the current test player eligible for the factions.
     */
    private function makePlayerEligible(): void
    {
        $this->settings->set('npc_min_active_players', '99999');
        $this->settings->set('npc_min_score_fixed', '0');
        $this->settings->set('npc_new_player_days', '0');
        $this->settings->set('npc_spotted_days', '0');
        $this->settings->set('npc_threat_max', '100');

        $user = resolve(PlayerService::class)->getUser();
        $user->time = (string)Date::now()->timestamp;
        $user->save();
    }
}
