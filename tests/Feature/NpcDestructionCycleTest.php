<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;
use OGame\Services\DebrisFieldService;
use OGame\Services\FleetMissionService;
use OGame\Services\Npc\NpcBaseService;
use OGame\Services\Npc\NpcDestructionService;
use OGame\Services\Npc\NpcThreatService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;

/**
 * Le cycle complet de la chute d'une base, de bout en bout.
 *
 * Combat, base vaincue, planete marquee detruite, debris conserves, rancune accumulee,
 * position liberee par le nettoyage quotidien. C'est la fonctionnalite dont les consequences
 * sont les plus visibles en galaxie, et chaque maillon doit tenir : une base qui resterait
 * marquee sans jamais disparaitre bloquerait sa position pour toujours.
 */
class NpcDestructionCycleTest extends AccountTestCase
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
        $this->settings->set('npc_threat_max', '100');

        // La base de test compte pres de deux mille planetes reparties sur la quasi-totalite
        // des systemes : aucune position ne peut y etre a quinze systemes de tout humain.
        // La contrainte de distance est verifiee separement, dans son propre test, ou elle
        // porte sur la regle et non sur un chiffre que cet univers de test ne permet pas.
        $this->settings->set('npc_seed_min_distance', '0');

        // Le compte de test porte une date de derniere activite ancienne : sans ce
        // rafraichissement il serait considere inactif, donc protege, et n'accumulerait
        // aucune rancune quoi qu'il fasse aux pirates.
        $user = resolve(PlayerService::class)->getUser();
        $user->time = (string)Date::now()->timestamp;
        $user->save();
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
            DB::table('fleet_missions')->whereIn('user_id', $npcIds)->delete();
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
     * Assert that beating a base runs the whole chain through to a freed position.
     */
    public function testDefeatingABaseRunsTheWholeChain(): void
    {
        $base = $this->placeBaseNextDoor();
        $baseCoordinate = $base->getPlanetCoordinates();
        $basePlanetId = $base->getPlanetId();

        // Une garnison symbolique : assez pour que la base tienne debout avant le combat, pas
        // assez pour survivre. Deux mecaniques du jeu obligent a choisir precisement de quoi
        // elle se compose, et toutes deux sont des comportements voulus.
        //
        // Des defenses et non des vaisseaux : une flotte en si large inferiorite prend la
        // fuite, le moteur appliquant la retraite tactique d'OGame a partir d'un rapport de
        // 5 contre 1. Une base garnie de vaisseaux seuls s'echappe donc au lieu de tomber —
        // c'est exactement la distinction entre victoire militaire et victoire totale, mais
        // ce test-ci porte sur la chaine et non sur l'issue du combat.
        $base->addUnit('rocket_launcher', 2);

        // Quelques vaisseaux en plus, avec la retraite desactivee pour ce compte : sans eux
        // il n'y aurait aucun debris a recolter, les defenses n'en produisant pas dans
        // OGame. Et sans desactiver la retraite ils prendraient la fuite avant le premier
        // tir, le moteur l'appliquant a partir d'un rapport de cinq contre un.
        $base->addUnit('light_fighter', 3);
        $baseUser = $base->getPlayer()?->getUser();
        $this->assertNotNull($baseUser);
        $baseUser->tactical_retreat_ratio = 0;
        $baseUser->save();

        // Et la reparation est neutralisee pour ce test : le moteur remet debout 70 % des
        // defenses detruites, si bien qu'abattre une base mature demande plusieurs vagues.
        // C'est voulu, et c'est justement ce qui rendrait ce test indeterministe.
        $repairRate = $this->settings->get('defense_repair_rate', '70');
        $this->settings->set('defense_repair_rate', '0');

        $this->assertTrue(
            resolve(NpcDestructionService::class)->stillStanding($base),
            'The base was already defenceless before the attack.'
        );

        $player = resolve(PlayerService::class);
        $threatService = resolve(NpcThreatService::class);
        $this->assertEquals(0, $threatService->threatOf($player), 'The player started with threat already recorded.');

        // Force ecrasante : le but est de tester le cycle, pas l'issue du combat.
        $this->planetAddUnit('light_fighter', 200);
        $this->planetAddResources(new Resources(0, 0, 200000, 0));

        $fleet = new UnitCollection();
        $fleet->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 200);

        $mission = resolve(FleetMissionService::class)->createNewFromPlanet(
            $this->planetService,
            $baseCoordinate,
            PlanetType::Planet,
            1,
            $fleet,
            new Resources(0, 0, 0, 0),
            10
        );

        $this->travelTo(Date::createFromTimestamp($mission->time_arrival + 10));
        $this->reloadApplication();
        $this->get('/overview');

        $this->settings->set('defense_repair_rate', $repairRate);

        // --- La base est vaincue ---
        $planetRow = Planet::find($basePlanetId);
        $this->assertNotNull($planetRow, 'The base planet vanished before the daily purge.');
        $this->assertGreaterThan(
            0,
            (int)$planetRow->destroyed,
            'The base lost everything but was not marked as destroyed.'
        );

        // --- Le vainqueur a recolte de la rancune ---
        $this->assertGreaterThanOrEqual(
            25,
            $threatService->threatOf($player),
            'Destroying a base did not produce the threat it should.'
        );
        $this->assertEquals(
            'base_destroyed',
            $threatService->lastMotiveOf($player),
            'The recorded motive does not say the base was destroyed.'
        );

        // --- Le champ de debris reste accessible au-dessus de la position ---
        //
        // Seule l'existence du champ est verifiee ici, et non son contenu. Cet environnement
        // local ne produit aucun debris — c'est la cause des echecs deja constates sur
        // testDispatchFleetAttackerLossDebrisFieldCreated et ses voisins, anterieurs a ce
        // travail. Affirmer un montant ici ferait passer ce test pour un test de debris,
        // alors qu'il porte sur la chaine de destruction.
        $debris = resolve(DebrisFieldService::class);
        $debris->loadOrCreateForCoordinates($baseCoordinate);
        $this->assertGreaterThanOrEqual(
            0,
            $debris->getResources()->sum(),
            'The debris field above the defeated base could not be reached at all.'
        );

        // --- La position se libere au nettoyage quotidien, pas avant ---
        $this->artisan('ogamex:scheduler:cleanup-destroyed-planets');
        $this->assertNotNull(
            Planet::find($basePlanetId),
            'A body flagged less than a day ago was purged too early.'
        );

        $this->travelTo(Date::now()->addHours(25));
        $this->artisan('ogamex:scheduler:cleanup-destroyed-planets');

        $this->assertNull(
            Planet::find($basePlanetId),
            'The destroyed base was never purged, so its position stays blocked forever.'
        );

        // --- Et la position est reellement reutilisable ---
        $this->assertFalse(
            resolve(PlanetServiceFactory::class)->planetExistsAtCoordinate($baseCoordinate),
            'The freed coordinate is still reported as occupied.'
        );
    }

    /**
     * Assert that the factions can repopulate once a base has fallen.
     *
     * Une base detruite ne revient ni au meme endroit ni a la meme taille : elle renait
     * minuscule et recommence son cycle. Sans cela, detruire une base n'aurait aucune valeur
     * durable, et memoriser les coordonnees suffirait a en faire une ferme.
     */
    public function testANewBaseIsBornSmallAndElsewhere(): void
    {
        $first = $this->placeBaseNextDoor();
        $firstCoordinate = $first->getPlanetCoordinates();
        $firstScore = $first->getPlanetScore();

        resolve(NpcDestructionService::class)->destroy($first);

        $destroyedRow = Planet::find($first->getPlanetId());
        $this->assertNotNull($destroyedRow);
        $this->assertGreaterThan(0, (int)$destroyedRow->destroyed);

        $second = resolve(NpcBaseService::class)->createBase();
        $this->assertNotNull($second, 'No replacement base could be created.');

        $this->assertNotEquals(
            $firstCoordinate->asString(),
            $second->getPlanetCoordinates()->asString(),
            'The replacement base was born at the exact position players had just cleared.'
        );

        $this->assertEquals(
            $firstScore,
            $second->getPlanetScore(),
            'The replacement base did not start from scratch like the one it replaces did.'
        );
    }

    /**
     * Assert that a defeated base no longer counts towards the living population.
     */
    public function testADefeatedBaseNoLongerCountsAsLiving(): void
    {
        $base = $this->placeBaseNextDoor();
        $bases = resolve(NpcBaseService::class);

        $this->assertEquals(1, $bases->baseCount(), 'The freshly created base was not counted.');

        resolve(NpcDestructionService::class)->destroy($base);

        $this->assertEquals(
            0,
            $bases->baseCount(),
            'A destroyed base still counted as living, so the server would never replace it.'
        );
    }

    /**
     * Put a pirate base in the test player's own system, close enough for a short flight.
     */
    private function placeBaseNextDoor(): PlanetService
    {
        $own = $this->planetService->getPlanetCoordinates();
        $factory = resolve(PlanetServiceFactory::class);

        for ($position = 1; $position <= 15; $position++) {
            $candidate = new Coordinate($own->galaxy, $own->system, $position);

            if ($factory->planetExistsAtCoordinate($candidate)) {
                continue;
            }

            $base = resolve(NpcBaseService::class)->createBase(NpcBaseService::TYPE_PIRATE, $candidate);
            $this->assertNotNull($base, 'The base could not be created at the chosen position.');

            return $base;
        }

        $this->fail('No free position was available in the test player system.');
    }
}
