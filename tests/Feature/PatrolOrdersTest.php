<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use OGame\Combat\Enums\CombatState;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\CombatInstance;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Patrol;
use OGame\Models\Resources;
use OGame\Patrol\Enums\PatrolState;
use OGame\Patrol\Exceptions\PatrolOrderRefused;
use OGame\Patrol\Geometry\SpatialPoint;
use OGame\Patrol\PatrolDestination;
use OGame\Patrol\PatrolOrders;
use OGame\Patrol\PatrolPricing;
use OGame\Services\ObjectService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;

/**
 * Les ordres qu une patrouille deja partie peut recevoir.
 *
 * ## Ce que ces temoins etablissent
 *
 * Qu une patrouille posee repart apres avoir paye ce qu elle doit ; qu une manoeuvre en vol part de
 * la position **reellement atteinte** a la fin du delai et non d un point commode ; que le delai
 * court depuis la confirmation du serveur ; et que la version d ordre refuse un devis perime au lieu
 * de debiter autre chose que ce que le joueur a lu.
 */
class PatrolOrdersTest extends AccountTestCase
{
    protected function tearDown(): void
    {
        resolve(SettingsService::class)->set('patrols_enabled', 0);
        Date::setTestNow();

        parent::tearDown();
    }

    private function arm(): void
    {
        resolve(SettingsService::class)->set('patrols_enabled', 1);
        $this->playerSetResearchLevel('computer_technology', 10);
    }

    private function player(): PlayerService
    {
        return resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);
    }

    private function orders(): PatrolOrders
    {
        return resolve(PatrolOrders::class);
    }

    private function fleet(): UnitCollection
    {
        $units = new UnitCollection();
        $units->addUnit(ObjectService::getUnitObjectByMachineName('cruiser'), 20);

        return $units;
    }

    private function pointAt(int $x, int $y): PatrolDestination
    {
        $coords = $this->planetService->getPlanetCoordinates();

        return PatrolDestination::spatialPoint(
            resolve(PatrolPricing::class)->geometry(),
            $coords->galaxy,
            $coords->system,
            new SpatialPoint($x, $y)
        );
    }

    /**
     * @return array{0: Patrol, 1: FleetMission}
     */
    private function aFlyingPatrol(): array
    {
        $this->arm();
        $this->planetAddResources(new Resources(0, 0, 60000, 0));
        $this->planetAddUnit('cruiser', 20);

        $patrouille = $this->orders()->launch(
            $this->planetService,
            $this->fleet(),
            new Resources(0, 0, 0, 0),
            10000,
            $this->pointAt(600, 600),
            10,
            (int)Date::now()->timestamp
        );

        return [$patrouille, FleetMission::query()->findOrFail($patrouille->current_mission_id)];
    }

    /**
     * @return array{0: Patrol, 1: FleetMission}
     */
    private function aParkedPatrol(): array
    {
        [$patrouille, $segment] = $this->aFlyingPatrol();

        Date::setTestNow(Date::createFromTimestamp((int)$segment->time_arrival + 1));
        $this->player()->updateFleetMissions();

        return [$patrouille->refresh(), $segment->refresh()];
    }

    /**
     * Une patrouille posee repart, apres avoir paye son stationnement.
     */
    public function testAParkedPatrolLeavesAgainAfterPayingWhatItOwes(): void
    {
        [$patrouille, $pose] = $this->aParkedPatrol();

        $reserveALArrivee = (float)$patrouille->fuel_reserve;
        $versionAvant = (int)$patrouille->order_version;
        $instant = (int)$pose->time_arrival + 3600;

        Date::setTestNow(Date::createFromTimestamp($instant));

        $nouveau = $this->orders()->orderMove($patrouille, $this->pointAt(-600, 600), 10, $versionAvant, $instant);

        $patrouille->refresh();
        $pose->refresh();

        $this->assertSame(PatrolState::EnRoute, $patrouille->state);
        $this->assertSame((int)$nouveau->id, (int)$patrouille->current_mission_id);
        $this->assertSame(1, (int)$pose->processed, 'The parked segment stayed live beside the new one.');
        $this->assertSame(20, (int)$nouveau->cruiser, 'The ships did not move to the new leg.');
        $this->assertSame(-600, (int)$nouveau->x_to);
        $this->assertSame(600, (int)$nouveau->y_to);
        // Une patrouille posee part tout de suite : aucun delai de manoeuvre.
        $this->assertSame($instant, (int)$nouveau->time_departure);

        // **L heure de stationnement a ete payee avant le nouveau devis.** Sans cela, un ordre
        // donne juste avant l heure effacerait la periode ecoulee.
        $this->assertSame($instant, (int)$patrouille->upkeep_paid_at, 'The stationing was not billed before the new order.');
        $this->assertLessThan($reserveALArrivee - 300.0, (float)$patrouille->fuel_reserve, 'Neither the stationing nor the leg was paid.');

        // Et la version a augmente : tout devis plus ancien est caduc.
        $this->assertSame($versionAvant + 1, (int)$patrouille->order_version);
    }

    /**
     * Une manoeuvre en vol part de la position reellement atteinte a la fin du delai.
     *
     * **C est la correction que la revue 120 a imposee**, et que la revue 121 a precisee : le delai
     * court depuis la confirmation du serveur, et le nouveau trajet ne part ni du point de depart,
     * ni de la destination, ni d une position commode — mais de la ou la flotte sera.
     */
    public function testAnInFlightManoeuvreLeavesFromWhereTheFleetWillActuallyBe(): void
    {
        [$patrouille, $segment] = $this->aFlyingPatrol();

        $depart = (int)$segment->time_departure;
        $arrivee = (int)$segment->time_arrival;
        $delai = resolve(SettingsService::class)->patrolManoeuvreDelaySeconds();

        // A mi-parcours, la flotte est entre les deux bouts.
        $instant = $depart + intdiv($arrivee - $depart, 2);
        Date::setTestNow(Date::createFromTimestamp($instant));

        // **L attendu se calcule a part, pas avec la fonction eprouvee.** Comparer le segment au
        // resultat de `departurePointFor()` etait une tautologie : une mutation qui ignore le delai
        // changeait les deux cotes a la fois et survivait. La fraction est donc ecrite ici, depuis
        // les bouts du segment et le delai du reglage.
        $geometrie = resolve(PatrolPricing::class)->geometry();
        $origine = $geometrie->stationingPointNear((int)$segment->position_from);
        $cible = new SpatialPoint((int)$segment->x_to, (int)$segment->y_to);
        $fraction = ($instant + $delai - $depart) / ($arrivee - $depart);
        $attendu = $geometrie->along($origine, $cible, $fraction);

        // Elle n est ni au depart, ni a l arrivee.
        $this->assertFalse($attendu->equals($origine), 'The manoeuvre starts from the origin of the leg.');
        $this->assertFalse($attendu->equals($cible), 'The manoeuvre starts from the destination it never reached.');

        // Et le service dit la meme chose que ce calcul independant.
        $this->assertTrue(
            $this->orders()->departurePointFor($patrouille, $segment, $instant)->equals($attendu),
            'The service places the fleet somewhere else than the leg and the delay put it.'
        );

        $nouveau = $this->orders()->orderMove($patrouille, $this->pointAt(-600, 600), 10, (int)$patrouille->order_version, $instant);

        // **Le delai court depuis la confirmation**, et le segment part a sa fin.
        $this->assertSame($instant + $delai, (int)$nouveau->time_departure, 'The manoeuvre delay did not start at the confirmation.');
        $this->assertSame($attendu->x, (int)$nouveau->x_from, 'The new leg does not start where the fleet will be.');
        $this->assertSame($attendu->y, (int)$nouveau->y_from);

        // Et la position avance avec le temps : deux confirmations differentes ne partent pas du meme point.
        $plusTard = $this->orders()->departurePointFor($patrouille->refresh(), $segment, $instant + 120);
        $this->assertFalse($attendu->equals($plusTard), 'The departure point does not follow the fleet.');
    }

    /**
     * Un devis perime est refuse, et rien n est debite.
     */
    public function testAStaleQuoteIsRefusedAndChargesNothing(): void
    {
        [$patrouille, $pose] = $this->aParkedPatrol();

        $instant = (int)$pose->time_arrival + 60;
        Date::setTestNow(Date::createFromTimestamp($instant));

        $versionLue = (int)$patrouille->order_version;

        // Un premier ordre passe, et la version augmente.
        $this->orders()->orderMove($patrouille, $this->pointAt(-600, 600), 10, $versionLue, $instant);
        $patrouille->refresh();

        $reserveAvant = (float)$patrouille->fuel_reserve;
        $segmentAvant = (int)$patrouille->current_mission_id;

        // Le second, confirme sur le devis d avant, est refuse.
        try {
            $this->orders()->orderMove($patrouille, $this->pointAt(600, -600), 10, $versionLue, $instant + 1);
            $this->fail('A quote confirmed on an outdated order version was accepted.');
        } catch (PatrolOrderRefused $refus) {
            $this->assertSame('stale_quote', $refus->reason);
        }

        $patrouille->refresh();
        $this->assertSame($reserveAvant, (float)$patrouille->fuel_reserve, 'A refused order charged the reserve.');
        $this->assertSame($segmentAvant, (int)$patrouille->current_mission_id, 'A refused order replaced the current leg.');
    }

    /**
     * Une patrouille engagee dans un combat n accepte aucun ordre.
     */
    public function testAnEngagedPatrolTakesNoOrder(): void
    {
        [$patrouille, $pose] = $this->aParkedPatrol();

        $combat = CombatInstance::create([
            'status' => CombatState::Active,
            'mission_id' => $pose->id,
            'target_planet_id' => null,
            'target_type' => 5,
            'galaxy' => (int)$patrouille->galaxy,
            'system' => (int)$patrouille->system,
            'position' => 9,
            'started_at' => 1_700_000_000,
        ]);

        $pose->forceFill(['combat_instance_id' => $combat->id])->save();

        $instant = (int)$pose->time_arrival + 60;
        Date::setTestNow(Date::createFromTimestamp($instant));

        try {
            $this->orders()->orderMove($patrouille, $this->pointAt(-600, 600), 10, (int)$patrouille->order_version, $instant);
            $this->fail('An engaged patrol accepted a movement order.');
        } catch (PatrolOrderRefused $refus) {
            $this->assertSame('engaged_in_combat', $refus->reason);
        }

        $pose->forceFill(['combat_instance_id' => null])->save();
    }

    /**
     * Une manoeuvre dont le segment arrive avant la fin du delai est refusee.
     *
     * Elle n aurait pas de point de depart : la patrouille serait deja posee. Le joueur redonne
     * l ordre une fois qu elle l est, et c est la reponse la plus honnete — plutot que d inventer
     * une position ou d avancer l arrivee.
     */
    public function testAManoeuvreIsRefusedWhenTheLegLandsFirst(): void
    {
        [$patrouille, $segment] = $this->aFlyingPatrol();

        $delai = resolve(SettingsService::class)->patrolManoeuvreDelaySeconds();
        $instant = (int)$segment->time_arrival - intdiv($delai, 2);
        Date::setTestNow(Date::createFromTimestamp($instant));

        try {
            $this->orders()->orderMove($patrouille, $this->pointAt(-600, 600), 10, (int)$patrouille->order_version, $instant);
            $this->fail('A manoeuvre was accepted although the leg lands before it could start.');
        } catch (PatrolOrderRefused $refus) {
            $this->assertSame('arriving_before_the_manoeuvre_ends', $refus->reason);
        }
    }

    /**
     * Le rappel ramene la patrouille chez elle, et paie son segment comme un autre.
     */
    public function testARecallSendsThePatrolHomeAndPaysForIt(): void
    {
        [$patrouille, $pose] = $this->aParkedPatrol();

        $instant = (int)$pose->time_arrival + 60;
        Date::setTestNow(Date::createFromTimestamp($instant));

        $reserveAvant = (float)$patrouille->fuel_reserve;

        // **Le cout du segment se calcule a part.** Constater « la reserve a baisse » ne prouvait
        // rien : le stationnement la fait baisser aussi, et un rappel gratuit y survivait.
        $devisIndependant = resolve(PatrolPricing::class)->quote(
            $this->player(),
            $this->fleet(),
            $reserveAvant,
            (int)$patrouille->galaxy,
            (int)$patrouille->system,
            new SpatialPoint((int)$patrouille->x, (int)$patrouille->y),
            PatrolDestination::nearBody(
                resolve(PatrolPricing::class)->geometry(),
                $this->planetService->getPlanetCoordinates()->galaxy,
                $this->planetService->getPlanetCoordinates()->system,
                $this->planetService->getPlanetCoordinates()->position,
                PlanetType::Planet,
                (int)$this->planetService->getPlanetId()
            ),
            resolve(SettingsService::class)->patrolSafetyReturnSpeed(),
            (int)$patrouille->order_version,
            $this->planetService->getPlanetCoordinates()
        );

        $this->assertGreaterThan(0, $devisIndependant->fuelCost, 'The recall leg is free: the witness would prove nothing.');

        $retour = $this->orders()->recall($patrouille, $instant);

        $patrouille->refresh();

        $this->assertSame((int)$this->planetService->getPlanetId(), (int)$retour->planet_id_to, 'The recall does not aim at the home planet.');
        $this->assertSame(20, (int)$retour->cruiser);
        $this->assertLessThanOrEqual(
            $reserveAvant - $devisIndependant->fuelCost,
            (float)$patrouille->fuel_reserve,
            'The recall leg was not paid out of the reserve.'
        );
        $this->assertSame(1, (int)$pose->refresh()->processed, 'The parked segment survived the recall.');

        // Le rappel emprunte le meme chemin qu un ordre : la version augmente aussi.
        $this->assertGreaterThan(1, (int)$patrouille->order_version);
    }
}
