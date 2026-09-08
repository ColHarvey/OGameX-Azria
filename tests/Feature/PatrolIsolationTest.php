<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use OGame\Combat\Enums\CombatMissionKind;
use OGame\Combat\Services\AccountCombatWithdrawal;
use OGame\Factories\PlayerServiceFactory;
use OGame\Galaxy\FleetMovementProjection;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\FleetMission;
use OGame\Models\Patrol;
use OGame\Models\Resources;
use OGame\Patrol\Enums\PatrolState;
use OGame\Patrol\Geometry\SpatialPoint;
use OGame\Patrol\PatrolDestination;
use OGame\Patrol\PatrolOrders;
use OGame\Patrol\PatrolPricing;
use OGame\Services\FleetMissionService;
use OGame\Services\ObjectService;
use OGame\Services\PhalanxService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;

/**
 * Ce qu une patrouille ne doit **pas** faire au reste du jeu.
 *
 * ## Les trois questions que ces temoins ferment
 *
 * Le segment d une patrouille posee reste non traite, et il porte la planete de base : deux choix qui
 * font marcher le systeme sans toucher au travailleur des missions, et deux endroits ou quelque chose
 * pourrait se confondre.
 *
 *  1. **Les passages repetes du serveur** ne doivent doubler ni la consommation, ni le retour.
 *  2. **La planete de base est administrative**, jamais une position : ni la phalange, ni la carte,
 *     ni la distance ne doivent la prendre pour l endroit ou la flotte se trouve.
 *  3. **Une patrouille posee ne bloque pas la suppression d un compte**, et la disparition de sa base
 *     pendant le vol se rattrape.
 */
class PatrolIsolationTest extends AccountTestCase
{
    protected function tearDown(): void
    {
        resolve(SettingsService::class)->set('patrols_enabled', 0);

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

    private function fleet(): UnitCollection
    {
        $units = new UnitCollection();
        $units->addUnit(ObjectService::getUnitObjectByMachineName('cruiser'), 20);

        return $units;
    }

    /**
     * Une patrouille posee, prete a etre interrogee.
     *
     * @return array{0: Patrol, 1: FleetMission}
     */
    private function aParkedPatrol(): array
    {
        $this->arm();
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

        Date::setTestNow(Date::createFromTimestamp((int)$segment->time_arrival + 1));
        $this->player()->updateFleetMissions();

        return [$patrouille->refresh(), $segment->refresh()];
    }

    /**
     * Dix passages du travailleur ne facturent qu une fois et ne creent aucun second retour.
     *
     * **C est la contrepartie du segment laisse non traite.** Il reste visible du travailleur a
     * chaque tour ; ce qui l empeche d etre rejoue est son rendez-vous, pas sa disparition.
     */
    public function testTenWorkerPassesBillOnceAndCreateNoSecondReturn(): void
    {
        [$patrouille, $segment] = $this->aParkedPatrol();

        $reserveApresPose = (float)$patrouille->fuel_reserve;
        $curseurApresPose = (int)$patrouille->upkeep_paid_at;
        $rendezVous = (int)$segment->time_arrival + (int)$segment->time_holding;

        // Dix passages avant l echeance : rien ne bouge.
        for ($i = 1; $i <= 10; $i++) {
            Date::setTestNow(Date::createFromTimestamp((int)$segment->time_arrival + $i * 60));
            $this->player()->updateFleetMissions();
        }

        $patrouille->refresh();

        $this->assertSame($reserveApresPose, (float)$patrouille->fuel_reserve, 'Repeated worker passes billed the stationing.');
        $this->assertSame($curseurApresPose, (int)$patrouille->upkeep_paid_at, 'Repeated worker passes moved the billing cursor.');
        $this->assertSame(PatrolState::Stationed, $patrouille->state);
        $this->assertSame(1, FleetMission::query()->where('patrol_id', $patrouille->id)->count(), 'A worker pass created a second segment.');

        // L echeance venue, puis dix passages de plus : un seul retour, et une seule facturation.
        Date::setTestNow(Date::createFromTimestamp($rendezVous + 1));
        $this->player()->updateFleetMissions();

        $patrouille->refresh();
        $apresRetour = (float)$patrouille->fuel_reserve;
        $curseurApresRetour = (int)$patrouille->upkeep_paid_at;

        for ($i = 2; $i <= 11; $i++) {
            Date::setTestNow(Date::createFromTimestamp($rendezVous + $i));
            $this->player()->updateFleetMissions();
        }

        $patrouille->refresh();

        $this->assertSame(2, FleetMission::query()->where('patrol_id', $patrouille->id)->count(), 'The safety return left more than once.');
        $this->assertSame(1, FleetMission::query()->where('patrol_id', $patrouille->id)->where('processed', 0)->count(), 'More than one live segment carries the fleet.');
        $this->assertSame($apresRetour, (float)$patrouille->fuel_reserve, 'The return was paid for more than once.');
        $this->assertSame($curseurApresRetour, (int)$patrouille->upkeep_paid_at, 'The stationing was billed again after the return left.');

        Date::setTestNow();
    }

    /**
     * La phalange ne revele pas une patrouille en balayant la planete qui lui sert de base.
     *
     * ## Le piege exact que ce temoin surveille
     *
     * Le segment porte la base dans `planet_id_from` pour que le travailleur le trouve. Or la
     * phalange interroge cette meme colonne : elle montre le **retour predit** de toute flotte partie
     * de la planete balayee. Une patrouille n a pas de retour au sens du jeu — son retour est un
     * segment neuf, pas un enfant — et le filtre l ecarte donc. Ce temoin epingle ce fait, parce
     * qu il tient a une seule ligne : le jour ou quelqu un declarerait un retour au genre 11, une
     * fuite de detection naitrait en silence.
     */
    public function testThePhalanxDoesNotRevealAPatrolThroughItsHomePlanet(): void
    {
        [$patrouille, $segment] = $this->aParkedPatrol();

        $vues = resolve(PhalanxService::class)->scanPlanetFleets(
            (int)$this->planetService->getPlanetId(),
            $this->getSecondPlayerId()
        );

        foreach ($vues as $vue) {
            $this->assertNotSame(11, (int)$vue['mission_type'], 'The phalanx revealed a patrol by scanning its home planet.');
        }

        // Et la raison tient a ce fait-la, qu on epingle : le genre 11 n a pas de retour au sens du jeu.
        $this->assertFalse(
            resolve(FleetMissionService::class, ['player' => $this->player()])->missionHasReturnMission(11),
            'A patrol declaring a return trip would leak through the phalanx.'
        );

        unset($patrouille, $segment);
        Date::setTestNow();
    }

    /**
     * La carte place la flotte a ses coordonnees, pas a celles de sa base.
     */
    public function testTheMapPlacesTheFleetWhereItIsNotWhereItsBaseIs(): void
    {
        [$patrouille, $segment] = $this->aParkedPatrol();

        $base = $this->planetService->getPlanetCoordinates();
        $projection = new FleetMovementProjection(
            resolve(FleetMissionService::class, ['player' => $this->player()]),
            $this->player()
        );

        $mouvements = $projection->inSystem($base->galaxy, $base->system);
        $notre = null;

        foreach ($mouvements as $mouvement) {
            if ((int)$mouvement['id'] === (int)$segment->id) {
                $notre = $mouvement;
            }
        }

        $this->assertNotNull($notre, 'The parked patrol is invisible to its own owner on the map.');
        // La destination est le point spatial, pas la planete de base.
        $this->assertSame(5, (int)$notre['to']['type'], 'The map calls the destination a celestial body.');
        $this->assertSame($segment->position_to, $notre['to']['position']);

        unset($patrouille);
        Date::setTestNow();
    }

    /**
     * Une patrouille posee ne retient pas la suppression d un compte.
     *
     * ## Ce que la regle existante dit deja, et que j avais ecrit a l envers
     *
     * Le retrait ne retient que les flottes qui **pourraient encore engager un combat** : celles dont
     * le genre ouvre une bataille ou renforce une defense. Le genre 11 ne fait ni l un ni l autre, et
     * une patrouille posee — qui peut durer indefiniment — ne bloque donc rien. J avais annonce
     * l inverse dans le journal ; c est le code qui a raison.
     *
     * **Ce qui reste a faire, et qui est dit ici plutot que decouvert plus tard** : quand une
     * patrouille pourra etre attaquee en espace libre, elle deviendra participante d un combat, et le
     * filtre du retrait devra la compter — sans quoi supprimer un compte retirerait un participant
     * avant le reglement.
     */
    public function testAParkedPatrolDoesNotHoldBackAnAccountDeletion(): void
    {
        [$patrouille, $segment] = $this->aParkedPatrol();

        resolve(SettingsService::class)->set('persistent_combat_enabled', 1);

        try {
            $plan = resolve(AccountCombatWithdrawal::class)->planFor(
                $this->currentUserId,
                $this->player()->planets->allIds()
            );

            $this->assertNotContains(
                (int)$segment->id,
                $plan->flottesQuiPeuventEncoreEngager,
                'A parked patrol holds an account deletion for ever.'
            );

            // Et la raison est celle du jeu, epinglee : le genre 11 n ouvre ni ne renforce un combat.
            $this->assertFalse(CombatMissionKind::Patrol->opensCombat());
            $this->assertFalse(CombatMissionKind::Patrol->reinforcesTheDefence());
        } finally {
            resolve(SettingsService::class)->set('persistent_combat_enabled', 0);
        }

        unset($patrouille);
        Date::setTestNow();
    }

    /**
     * La base disparue, le repli est la planete du joueur la plus proche.
     */
    public function testWhenTheHomePlanetIsGoneTheNearestOwnPlanetTakesOver(): void
    {
        [$patrouille] = $this->aParkedPatrol();

        $base = $this->planetService->getPlanetCoordinates();
        $orders = resolve(PatrolOrders::class);

        $avant = $orders->homeCoordinateOf($patrouille);
        $this->assertNotNull($avant);
        $this->assertTrue($avant->equals($base), 'The home coordinate is not the base while the base is there.');

        // La base disparait : le lien tombe a vide, comme la cle etrangere le prevoit.
        $patrouille->forceFill(['home_planet_id' => null])->save();

        $apres = $orders->homeCoordinateOf($patrouille->refresh());

        $this->assertNotNull($apres, 'A patrol whose base vanished has nowhere to go.');
        $identifiants = [];

        foreach ($this->player()->planets->allPlanets() as $planete) {
            $identifiants[] = $planete->getPlanetCoordinates()->asString();
        }

        $this->assertContains($apres->asString(), $identifiants, 'The fallback is not one of the player own planets.');

        Date::setTestNow();
    }
}
