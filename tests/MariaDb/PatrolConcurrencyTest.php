<?php

namespace Tests\MariaDb;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\FleetMission;
use OGame\Models\Patrol;
use OGame\Models\Resources;
use OGame\Patrol\Enums\PatrolState;
use OGame\Patrol\Geometry\SpatialPoint;
use OGame\Patrol\PatrolDestination;
use OGame\Patrol\PatrolOrders;
use OGame\Patrol\PatrolPricing;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use PHPUnit\Framework\Attributes\Group;
use Tests\AccountTestCase;

/**
 * Deux traitements au meme instant : une seule consommation, un seul retour, une seule restitution.
 *
 * ## Pourquoi ces preuves appartiennent au bac, et pas a la suite ordinaire
 *
 * `Tests\Feature\PatrolIsolationTest` etablit qu un travailleur qui repasse dix fois ne double rien.
 * C est le contrat, et il se prouve sous SQLite parce qu il ne demande qu une horloge. La **course**,
 * elle, ne s y prouve pas : `lockForUpdate()` ne compile a rien sous SQLite, il n y a pas de seconde
 * connexion, et le juste comme le faux y passent. Dix passages successifs ne disent rien de deux
 * passages simultanes.
 *
 * Chacune des trois transitions d une patrouille est ici jouee par deux processus reels a la meme
 * seconde. Ce qui les protege est une relecture **sous verrou** : le perdant de la course trouve la
 * ligne deja changee et repart sans rien ecrire — il ne leve pas, car une course fermee par une
 * panne ne serait pas une course fermee.
 *
 * ## Ce que chaque essai mesure, et pourquoi ce n est pas un compteur
 *
 * Le carburant, les vaisseaux et les lignes de mission : ce sont des biens. Un compte d appels
 * dirait qui a fait quoi ; c est le stock double qui fabrique quelque chose a partir de rien.
 */
#[Group('mariadb')]
final class PatrolConcurrencyTest extends AccountTestCase
{
    use RunsInParallelProcesses;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requiresMariaDb();
        $this->requiresProcesses();

        resolve(SettingsService::class)->set('patrols_enabled', 1);
        $this->playerSetResearchLevel('computer_technology', 10);
    }

    protected function tearDown(): void
    {
        resolve(SettingsService::class)->set('patrols_enabled', 0);

        parent::tearDown();
    }

    private function fleet(): UnitCollection
    {
        $units = new UnitCollection();
        $units->addUnit(ObjectService::getUnitObjectByMachineName('cruiser'), 20);

        return $units;
    }

    /**
     * Une patrouille posee, avec sa reserve et son rendez-vous.
     *
     * @return array{0: Patrol, 1: FleetMission}
     */
    private function aParkedPatrol(): array
    {
        $this->planetAddResources(new Resources(0, 0, 60000, 0));
        $this->planetAddUnit('cruiser', 20);

        $coords = $this->planetService->getPlanetCoordinates();
        $geometrie = resolve(PatrolPricing::class)->geometry();

        $patrouille = resolve(PatrolOrders::class)->launch(
            $this->planetService,
            $this->fleet(),
            new Resources(0, 0, 0, 0),
            10000,
            PatrolDestination::spatialPoint($geometrie, $coords->galaxy, $coords->system, new SpatialPoint(600, 600)),
            10,
            (int)Date::now()->timestamp
        );

        $segment = FleetMission::query()->findOrFail($patrouille->current_mission_id);

        $this->travelTo(Date::createFromTimestamp((int)$segment->time_arrival + 1));
        $this->planetService->getPlayer()?->updateFleetMissions();

        return [$patrouille->refresh(), $segment->refresh()];
    }

    /**
     * Deux facturations au meme instant ne prelevent qu une fois.
     */
    public function testTwoSimultaneousBillingsChargeTheStationingOnce(): void
    {
        [$patrouille, $segment] = $this->aParkedPatrol();

        $reserveAvant = (float)DB::table('patrols')->where('id', $patrouille->id)->value('fuel_reserve');
        $this->assertGreaterThan(0.0, $reserveAvant, 'The patrol has no reserve: the race would prove nothing.');

        $jusqua = (int)$patrouille->upkeep_paid_at + 3600;
        $patrolId = (int)$patrouille->id;
        $segmentId = (int)$segment->id;

        $issues = $this->inParallel(2, static function (int $rang) use ($patrolId, $segmentId, $jusqua): string {
            $orders = resolve(PatrolOrders::class);
            $patrouille = Patrol::query()->findOrFail($patrolId);
            $units = $orders->unitsOf(FleetMission::query()->findOrFail($segmentId));

            $preleve = $orders->bill($patrouille, $units, $jusqua);

            return $preleve > 0.0 ? 'a facture' : 'a trouve deja facture';
        });

        sort($issues);
        $this->assertSame(['a facture', 'a trouve deja facture'], $issues, 'Both processes billed, or neither did.');

        $reserveApres = (float)DB::table('patrols')->where('id', $patrolId)->value('fuel_reserve');

        // Vingt croiseurs a trois cents de l heure : une heure vaut trois cents, jamais six cents.
        $this->assertEqualsWithDelta(
            $reserveAvant - 300.0,
            $reserveApres,
            0.01,
            'The stationing was billed twice: fuel vanished from nowhere.'
        );

        $this->assertSame(
            $jusqua,
            (int)DB::table('patrols')->where('id', $patrolId)->value('upkeep_paid_at'),
            'The billing cursor did not land on the instant both processes asked for.'
        );
    }

    /**
     * Deux departs de retour au meme instant n en font partir qu un.
     */
    public function testTwoSimultaneousSafetyReturnsLeaveOnce(): void
    {
        [$patrouille, $segment] = $this->aParkedPatrol();

        $patrolId = (int)$patrouille->id;
        $segmentId = (int)$segment->id;
        $instant = (int)$segment->time_arrival + (int)$segment->time_holding;

        $this->travelTo(Date::createFromTimestamp($instant + 1));

        $issues = $this->inParallel(2, static function (int $rang) use ($patrolId, $segmentId, $instant): string {
            $orders = resolve(PatrolOrders::class);
            $retour = $orders->launchSafetyReturn(
                Patrol::query()->findOrFail($patrolId),
                FleetMission::query()->findOrFail($segmentId),
                $instant + 1
            );

            return $retour === null ? 'a trouve deja parti' : 'a fait partir';
        });

        sort($issues);
        $this->assertSame(['a fait partir', 'a trouve deja parti'], $issues, 'Both processes launched a return, or neither did.');

        $segments = DB::table('fleet_missions')->where('patrol_id', $patrolId)->count();
        $this->assertSame(2, $segments, 'The safety return left twice: the fleet would exist in two places.');

        $vivants = DB::table('fleet_missions')->where('patrol_id', $patrolId)->where('processed', 0)->count();
        $this->assertSame(1, $vivants, 'More than one live segment carries the fleet.');

        $this->assertSame(
            PatrolState::Returning->value,
            (string)DB::table('patrols')->where('id', $patrolId)->value('state'),
            'The patrol is not on its way home after the race.'
        );
    }

    /**
     * Deux atterrissages au meme instant ne rendent les vaisseaux qu une fois.
     */
    public function testTwoSimultaneousLandingsGiveBackTheFleetOnce(): void
    {
        $this->planetAddUnit('cruiser', 20);
        $this->planetService->reloadPlanet();

        $planeteId = (int)$this->planetService->getPlanetId();
        $coords = $this->planetService->getPlanetCoordinates();

        $patrouille = Patrol::forceCreate([
            'user_id' => $this->currentUserId,
            'home_planet_id' => $planeteId,
            'state' => PatrolState::Returning,
            'galaxy' => $coords->galaxy,
            'system' => $coords->system,
            'fuel_reserve' => 2500.0,
            'order_version' => 1,
        ]);

        $segment = new FleetMission();
        $segment->user_id = $this->currentUserId;
        $segment->patrol_id = (int)$patrouille->id;
        $segment->mission_type = 11;
        $segment->planet_id_from = $planeteId;
        $segment->planet_id_to = $planeteId;
        $segment->type_from = 1;
        $segment->type_to = 1;
        $segment->galaxy_from = $coords->galaxy;
        $segment->system_from = $coords->system;
        $segment->position_from = $coords->position;
        $segment->galaxy_to = $coords->galaxy;
        $segment->system_to = $coords->system;
        $segment->position_to = $coords->position;
        $segment->time_departure = (int)Date::now()->timestamp - 600;
        $segment->time_arrival = (int)Date::now()->timestamp - 1;
        $segment->cruiser = 20;
        $segment->metal = 0;
        $segment->crystal = 0;
        $segment->deuterium = 0;
        $segment->save();

        $croiseursAvant = (int)DB::table('planets')->where('id', $planeteId)->value('cruiser');
        $deuteriumAvant = (float)DB::table('planets')->where('id', $planeteId)->value('deuterium');

        $patrolId = (int)$patrouille->id;
        $segmentId = (int)$segment->id;
        $maintenant = (int)Date::now()->timestamp;

        $issues = $this->inParallel(2, static function (int $rang) use ($patrolId, $segmentId, $planeteId, $maintenant): string {
            $orders = resolve(PatrolOrders::class);
            $avant = (int)DB::table('fleet_missions')->where('id', $segmentId)->value('processed');

            // La fabrique rend un corps **ou nul** ; un corps disparu ne se pose pas, et un essai
            // qui l ignorerait mesurerait une panne au lieu d une course.
            $corps = resolve(PlanetServiceFactory::class)->make($planeteId, true);

            if ($corps === null) {
                return 'corps introuvable';
            }

            $orders->land(
                Patrol::query()->findOrFail($patrolId),
                FleetMission::query()->findOrFail($segmentId),
                $maintenant,
                $corps
            );

            return $avant === 1 ? 'a trouve deja pose' : 'a pose';
        });

        $this->assertCount(2, $issues, 'A process died instead of finding the landing already done.');

        $this->assertSame(
            $croiseursAvant + 20,
            (int)DB::table('planets')->where('id', $planeteId)->value('cruiser'),
            'The ships came back twice: twenty cruisers were created from nothing.'
        );

        $this->assertEqualsWithDelta(
            $deuteriumAvant + 2500.0,
            (float)DB::table('planets')->where('id', $planeteId)->value('deuterium'),
            1.0,
            'The reserve came back twice.'
        );

        $this->assertSame(
            PatrolState::Finished->value,
            (string)DB::table('patrols')->where('id', $patrolId)->value('state'),
            'The patrol did not finish.'
        );

        $this->assertSame(
            0.0,
            (float)DB::table('patrols')->where('id', $patrolId)->value('fuel_reserve'),
            'The reserve was given back and kept at once.'
        );
    }
}
