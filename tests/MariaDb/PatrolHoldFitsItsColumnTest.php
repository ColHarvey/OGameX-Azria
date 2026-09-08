<?php

namespace Tests\MariaDb;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Models\FleetMission;
use OGame\Models\Patrol;
use OGame\Patrol\Enums\PatrolState;
use OGame\Patrol\PatrolOrders;
use OGame\Services\SettingsService;
use PHPUnit\Framework\Attributes\Group;
use Tests\AccountTestCase;

/**
 * Le rendez-vous « sans terme » d une patrouille tient dans la colonne qui le porte.
 *
 * ## Le defaut que cette epreuve a trouve, et que SQLite ne pouvait pas montrer
 *
 * Une patrouille qui ne brule rien — ou qu aucune reserve ne peut faire partir — recoit un
 * rendez-vous volontairement lointain plutot qu un nul, pour que `time_arrival + time_holding`
 * reste une somme d entiers. Cette valeur etait de **cent ans**, soit 3 153 600 000 secondes.
 *
 * `fleet_missions.time_holding` est un entier signe de quatre octets. SQLite, qui ne verifie pas
 * l etendue d une colonne entiere, acceptait cette valeur sans rien dire ; MariaDB la refuse
 * (erreur 1264, « Out of range value »). Le premier stationnement immobilise sur le serveur reel
 * aurait donc leve une exception au milieu du travailleur — et la suite ordinaire serait restee
 * verte. Le defaut est apparu au bac, sur la premiere course de patrouille qui l a atteint.
 *
 * L epreuve ecrit la valeur pour de vrai et la relit : c est l aller-retour par la colonne qui est
 * mesure, pas la constante.
 */
#[Group('mariadb')]
final class PatrolHoldFitsItsColumnTest extends AccountTestCase
{
    // **Seulement pour `requiresMariaDb()`.** Aucune course ici : ce que l on mesure est ce que la
    // colonne accepte.
    use RunsInParallelProcesses;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requiresMariaDb();

        resolve(SettingsService::class)->set('patrols_enabled', 1);
    }

    protected function tearDown(): void
    {
        resolve(SettingsService::class)->set('patrols_enabled', 0);
        Date::setTestNow();

        parent::tearDown();
    }

    /**
     * Le rendez-vous sans terme s ecrit et se relit identique.
     */
    public function testTheEndlessAppointmentSurvivesTheRoundTripThroughTheColumn(): void
    {
        $maintenant = (int)Date::now()->timestamp;

        $segment = FleetMission::forceCreate([
            'user_id' => $this->currentUserId,
            'planet_id_from' => $this->planetService->getPlanetId(),
            'mission_type' => 11,
            'type_from' => 1,
            'type_to' => 5,
            'galaxy_from' => 1,
            'system_from' => 1,
            'position_from' => 4,
            'planet_id_to' => null,
            'galaxy_to' => 1,
            'system_to' => 1,
            'position_to' => 8,
            'x_to' => 600,
            'y_to' => -600,
            'time_departure' => $maintenant - 3600,
            'time_arrival' => $maintenant - 60,
            'cruiser' => 7,
            'processed' => 0,
            'canceled' => 0,
        ]);

        $patrouille = new Patrol();
        $patrouille->forceFill([
            'user_id' => $this->currentUserId,
            'home_planet_id' => $this->planetService->getPlanetId(),
            'state' => PatrolState::Stationed,
            'galaxy' => 1,
            'system' => 1,
            'x' => 600,
            'y' => -600,
            'current_mission_id' => $segment->id,
            'fuel_reserve' => 0,
            'upkeep_paid_at' => $maintenant - 60,
            'stationed_since' => $maintenant - 60,
            'order_version' => 1,
        ])->save();

        // La patrouille ne peut pas payer son retour : le socle lui donne un rendez-vous sans terme.
        $orders = resolve(PatrolOrders::class);
        $orders->launchSafetyReturn($patrouille, $segment, $maintenant);

        $patrouille->refresh();
        $this->assertSame(PatrolState::Immobilised, $patrouille->state, 'The patrol left with an empty reserve: the scenario would prove nothing.');

        $relu = DB::table('fleet_missions')->where('id', $segment->id)->value('time_holding');

        // **L ecriture elle-meme est la mesure.** Sous l ancienne valeur, MariaDB refusait cette
        // ligne et le travailleur levait au milieu de son passage ; ici elle passe, et ce qui revient
        // est bien un rendez-vous sans terme.
        $this->assertNotNull($relu, 'The endless appointment was not written at all.');
        $this->assertGreaterThan(0, (int)$relu, 'The endless appointment came back as nothing.');
        $this->assertLessThanOrEqual(2147483647, (int)$relu, 'The endless appointment does not fit a four byte signed column.');
        $this->assertNull($orders->nextEventAt($segment->refresh()), 'An appointment written as endless is read back as a real one.');
    }
}
