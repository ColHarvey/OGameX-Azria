<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use OGame\Combat\Enums\CombatState;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\CombatInstance;
use OGame\Models\FleetMission;
use OGame\Models\Patrol;
use OGame\Models\Resources;
use OGame\Patrol\Enums\PatrolState;
use OGame\Patrol\Exceptions\PatrolOrderRefused;
use OGame\Patrol\Geometry\SpatialPoint;
use OGame\Patrol\PatrolDestination;
use OGame\Patrol\PatrolOrders;
use OGame\Patrol\PatrolPricing;
use OGame\Patrol\PatrolUpkeep;
use OGame\Services\ObjectService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;

/**
 * Ce que la carte recoit d une patrouille, et ce qu elle ne recoit pas.
 *
 * ## Trois choses que ces temoins etablissent
 *
 *  1. **La position vient du serveur.** Un segment vers un point libre voyage avec ce point, et la
 *     patrouille posee avec le sien ; le rendez-vous du retour de securite est celui que la ligne
 *     porte, pas un recalcul.
 *  2. **Une commande grisee dit exactement ce que la confirmation refuserait.** Le bouton et
 *     `orderMove()` lisent la meme methode ; le temoin compare la raison publiee a la raison levee.
 *  3. **La patrouille d un tiers est absente**, pas masquee : ni dans `patrols`, ni dans
 *     `movements`. Un temoin qui ne verifierait que la presence des siennes passerait sur une carte
 *     omnisciente.
 */
class PatrolProjectionTest extends AccountTestCase
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
     * La couche privee de la carte, pour le systeme de la planete du joueur.
     *
     * @return array<string, mixed>
     */
    private function layer(int $systemOffset = 0): array
    {
        $coords = $this->planetService->getPlanetCoordinates();

        return $this->getJson(route('galaxy.fleets', ['galaxy' => $coords->galaxy, 'system' => $coords->system + $systemOffset]))
            ->assertStatus(200)
            ->json();
    }

    /**
     * @param array<string, mixed> $couche
     * @return array<string, mixed>|null
     */
    private function patrolIn(array $couche, int $id): array|null
    {
        foreach ($couche['patrols'] as $patrouille) {
            if ((int)$patrouille['id'] === $id) {
                return $patrouille;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $couche
     * @return array<string, mixed>|null
     */
    private function movementIn(array $couche, int $id): array|null
    {
        foreach ($couche['movements'] as $mouvement) {
            if ((int)$mouvement['id'] === $id) {
                return $mouvement;
            }
        }

        return null;
    }

    /**
     * Une patrouille posee est publiee la ou elle est, avec le rendez-vous que la ligne porte.
     */
    public function testAParkedPatrolIsProjectedWhereItIsWithItsPersistedRendezVous(): void
    {
        [$patrouille, $segment] = $this->aParkedPatrol();
        $coords = $this->planetService->getPlanetCoordinates();

        $couche = $this->layer();
        $notre = $this->patrolIn($couche, (int)$patrouille->id);

        $this->assertNotNull($notre, 'The owner does not see his own parked patrol.');
        $this->assertSame('stationed', $notre['state']);
        $this->assertSame(['x' => 600, 'y' => 600], $notre['point'], 'The parked point is not the one the patrol holds.');

        // Le segment : parti d une planete (pas de point), arrive a un point libre.
        $this->assertSame((int)$segment->id, $notre['segment']['id']);
        $this->assertNull($notre['segment']['from']['x'], 'A departure from a planet carries a spatial point.');
        $this->assertSame($coords->position, $notre['segment']['from']['position']);
        $this->assertSame(600, $notre['segment']['to']['x']);
        $this->assertSame(600, $notre['segment']['to']['y']);
        $this->assertSame(5, $notre['segment']['to']['type']);

        // **Le rendez-vous persiste, pas un recalcul.** C est lui que le travailleur attend.
        $this->assertSame(
            (int)$segment->time_arrival + (int)$segment->time_holding,
            $notre['safety_return_at'],
            'The safety return instant is not the one the worker holds.'
        );
        $this->assertSame((int)$segment->time_arrival, $notre['stationed_since']);
        $this->assertGreaterThan(0, $notre['safety_return_cost'], 'Coming home is announced free.');
        $this->assertGreaterThan(0, $notre['upkeep_per_hour'], 'Twenty cruisers are announced as burning nothing.');

        // La composition, avec son nom lisible.
        $this->assertCount(1, $notre['units']);
        $this->assertSame('cruiser', $notre['units'][0]['machine_name']);
        $this->assertSame(20, $notre['units'][0]['amount']);
        $this->assertNotSame('', $notre['units'][0]['label']);

        $this->assertSame((int)$patrouille->order_version, $notre['order_version']);
        $this->assertSame($coords->position, $notre['home']['position']);

        $this->assertTrue($notre['commands']['move']['allowed'], 'A parked patrol cannot be given a destination.');
        $this->assertTrue($notre['commands']['recall']['allowed'], 'A parked patrol cannot be recalled.');
        $this->assertNull($notre['commands']['move']['reason']);
    }

    /**
     * La reserve publiee est celle de maintenant — et la lire ne facture rien.
     *
     * Une heure apres la pose, sans passage du travailleur : la ligne porte encore la reserve de
     * l arrivee, la carte annonce ce qui en reste **a cet instant**, et la lecture ne touche pas la
     * ligne. L attendu se calcule avec le taux, jamais avec la projection.
     */
    public function testTheReserveShownIsTheOneOfNowAndReadingItBillsNothing(): void
    {
        [$patrouille, $segment] = $this->aParkedPatrol();

        $reservePersistee = (float)$patrouille->fuel_reserve;
        $curseur = (int)$patrouille->upkeep_paid_at;
        $tauxHoraire = resolve(PatrolUpkeep::class)->perHour($this->fleet());

        $this->assertGreaterThan(0.0, $tauxHoraire, 'The witness needs a fleet that burns fuel.');

        Date::setTestNow(Date::createFromTimestamp((int)$segment->time_arrival + 3600));

        $notre = $this->patrolIn($this->layer(), (int)$patrouille->id);
        $this->assertNotNull($notre);

        $attendu = $reservePersistee - $tauxHoraire * (3600 - ((int)$segment->time_arrival - $curseur)) / 3600;
        $this->assertEqualsWithDelta($attendu, $notre['fuel_reserve'], 0.011, 'The reserve shown is not the one of now.');
        $this->assertLessThan($reservePersistee, $notre['fuel_reserve'], 'An hour of stationing changed nothing on the map.');

        $patrouille->refresh();
        $this->assertSame($reservePersistee, (float)$patrouille->fuel_reserve, 'Reading the map billed the patrol.');
        $this->assertSame($curseur, (int)$patrouille->upkeep_paid_at, 'Reading the map moved the billing cursor.');
    }

    /**
     * Le mouvement d un segment de patrouille porte son point d arrivee et sa patrouille ; un
     * mouvement ordinaire ne porte ni l un ni l autre.
     */
    public function testTheFleetLayerPublishesTheSpatialEndOfAPatrolSegmentAndLinksIt(): void
    {
        [$patrouille, $segment] = $this->aFlyingPatrol();
        $coords = $this->planetService->getPlanetCoordinates();

        $ordinaire = (int)DB::table('fleet_missions')->insertGetId([
            'user_id' => $this->currentUserId,
            'planet_id_from' => $this->planetService->getPlanetId(),
            'mission_type' => 15,
            'type_from' => 1,
            'type_to' => 1,
            'galaxy_from' => $coords->galaxy,
            'system_from' => $coords->system,
            'position_from' => $coords->position,
            'planet_id_to' => null,
            'galaxy_to' => $coords->galaxy,
            'system_to' => $coords->system,
            'position_to' => 16,
            'time_departure' => (int)Date::now()->timestamp,
            'time_arrival' => (int)Date::now()->timestamp + 3600,
            'light_fighter' => 3,
            'processed' => 0,
            'canceled' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $couche = $this->layer();

        $vol = $this->movementIn($couche, (int)$segment->id);
        $this->assertNotNull($vol, 'The patrol leg is missing from the movements.');
        $this->assertSame(600, $vol['to']['x'], 'The map would draw the leg toward an orbit slot, not the point.');
        $this->assertSame(600, $vol['to']['y']);
        $this->assertSame(5, $vol['to']['type']);
        $this->assertSame((int)$patrouille->id, $vol['patrol_id']);

        $autre = $this->movementIn($couche, $ordinaire);
        $this->assertNotNull($autre, 'The ordinary mission is missing from the movements.');
        $this->assertNull($autre['to']['x'], 'An ordinary mission is given a spatial point.');
        $this->assertNull($autre['patrol_id']);

        // Une patrouille en vol est publiee sans point, avec son segment.
        $notre = $this->patrolIn($couche, (int)$patrouille->id);
        $this->assertNotNull($notre);
        $this->assertSame('en_route', $notre['state']);
        $this->assertNull($notre['point'], 'A flying patrol claims a parked point.');
        $this->assertNull($notre['safety_return_at'], 'A flying patrol announces a stationing rendez-vous.');
    }

    /**
     * La patrouille d un tiers, posee dans le systeme regarde, est absente de la couche.
     *
     * Absente, pas masquee : ni dans `patrols`, ni dans `movements`. Tant qu aucun reseau de
     * surveillance n existe, rien d etranger ne voyage.
     */
    public function testAnotherPlayersPatrolIsAbsentNotHidden(): void
    {
        [$patrouille] = $this->aParkedPatrol();
        $coords = $this->planetService->getPlanetCoordinates();

        $etrangere = $this->getNearbyForeignPlanet();
        $proprietaire = $etrangere->getPlayer();
        $this->assertNotNull($proprietaire);
        $this->assertNotSame($this->currentUserId, $proprietaire->getId());

        $autre = (int)DB::table('patrols')->insertGetId([
            'user_id' => $proprietaire->getId(),
            'home_planet_id' => $etrangere->getPlanetId(),
            'state' => PatrolState::Stationed->value,
            'galaxy' => $coords->galaxy,
            'system' => $coords->system,
            'x' => 600,
            'y' => -600,
            'current_mission_id' => null,
            'fuel_reserve' => 4242,
            'upkeep_paid_at' => (int)Date::now()->timestamp - 60,
            'stationed_since' => (int)Date::now()->timestamp - 60,
            'order_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $segmentEtranger = (int)DB::table('fleet_missions')->insertGetId([
            'user_id' => $proprietaire->getId(),
            'patrol_id' => $autre,
            'planet_id_from' => $etrangere->getPlanetId(),
            'mission_type' => 11,
            'type_from' => 1,
            'type_to' => 5,
            'galaxy_from' => $etrangere->getPlanetCoordinates()->galaxy,
            'system_from' => $etrangere->getPlanetCoordinates()->system,
            'position_from' => $etrangere->getPlanetCoordinates()->position,
            'planet_id_to' => null,
            'galaxy_to' => $coords->galaxy,
            'system_to' => $coords->system,
            'position_to' => 8,
            'x_to' => 600,
            'y_to' => -600,
            'time_departure' => (int)Date::now()->timestamp - 3600,
            'time_arrival' => (int)Date::now()->timestamp - 60,
            'time_holding' => 36000,
            'cruiser' => 7,
            'processed' => 0,
            'canceled' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('patrols')->where('id', $autre)->update(['current_mission_id' => $segmentEtranger]);

        $couche = $this->layer();

        $this->assertNotNull($this->patrolIn($couche, (int)$patrouille->id), 'The owner lost his own patrol.');
        $this->assertNull($this->patrolIn($couche, $autre), 'A stranger patrol is sent to the reader: the map is omniscient.');
        $this->assertNull($this->movementIn($couche, $segmentEtranger), 'A stranger patrol leg is sent as a movement.');

        $this->assertStringNotContainsString('4242', json_encode($couche, JSON_THROW_ON_ERROR), 'The stranger reserve leaked somewhere in the payload.');
    }

    /**
     * Une commande grisee dit exactement ce que la confirmation refuserait — dans trois situations.
     *
     * Le bouton et `orderMove()` lisent la meme methode. Le temoin ne se contente pas de « grise » :
     * il compare la clef publiee a la raison levee, et exige que le texte soit une traduction, pas
     * la clef rendue telle quelle.
     */
    public function testAGreyedCommandSaysExactlyWhatTheConfirmationWouldRefuse(): void
    {
        // 1. Engagee dans un combat.
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

        $this->assertCommandRefusedFor($patrouille, 'engaged_in_combat');

        $pose->forceFill(['combat_instance_id' => null])->save();

        // 2. En retour : le retour se termine ou se rappelle, il ne se redirige pas.
        $patrouille->forceFill(['state' => PatrolState::Returning])->save();

        $this->assertCommandRefusedFor($patrouille, 'state_refuses_orders');

        $patrouille->forceFill(['state' => PatrolState::Stationed])->save();

        // 3. En vol, et le segment se pose avant la fin du delai de manoeuvre.
        [$enVol, $segment] = $this->aFlyingPatrol();

        Date::setTestNow(Date::createFromTimestamp((int)$segment->time_arrival - 10));

        $this->assertCommandRefusedFor($enVol, 'arriving_before_the_manoeuvre_ends');
    }

    private function assertCommandRefusedFor(Patrol $patrouille, string $raison): void
    {
        $notre = $this->patrolIn($this->layer(), (int)$patrouille->id);
        $this->assertNotNull($notre, 'The patrol vanished from the layer in the ' . $raison . ' case.');

        $commande = $notre['commands']['move'];
        $this->assertFalse($commande['allowed'], 'The button is offered although the order would be refused (' . $raison . ').');
        $this->assertSame($raison, $commande['reason_key']);
        $this->assertIsString($commande['reason']);
        $this->assertStringNotContainsString('t_ingame', $commande['reason'], 'The reason is a raw translation key.');

        // Le rappel est refuse pour la meme raison : les memes conditions.
        $this->assertSame($raison, $notre['commands']['recall']['reason_key']);

        try {
            $this->orders()->orderMove(
                $patrouille->refresh(),
                $this->pointAt(-600, 600),
                10,
                (int)$patrouille->order_version,
                (int)Date::now()->timestamp
            );
            $this->fail('The confirmation accepted what the button refused (' . $raison . ').');
        } catch (PatrolOrderRefused $refus) {
            $this->assertSame($raison, $refus->reason, 'The button and the confirmation disagree.');
        }
    }

    /**
     * L interrupteur eteint n efface pas la flotte : elle se voit, et **elle peut rentrer**.
     *
     * Cet essai exigeait autrefois que le rappel soit refuse comme la manoeuvre. C etait l ancien
     * comportement, et c etait un defaut : un arret d urgence qui ferme le rappel laisse les
     * flottes du joueur en l air, sans aucun moyen d agir, jusqu a ce que l usure de la reserve
     * declenche le retour de securite des heures plus tard.
     *
     * La regle est desormais : **un interrupteur baisse ferme les nouvelles entrees, il
     * n emprisonne pas ce qui existe**. La manoeuvre reste donc refusee, avec sa raison ; le rappel
     * reste offert. Voir `Tests\Feature\PatrolDisarmedTest` pour les neuf temoins de cette regle.
     */
    public function testWithTheSwitchOffThePatrolIsStillShownAndCanStillComeHome(): void
    {
        [$patrouille] = $this->aParkedPatrol();

        resolve(SettingsService::class)->set('patrols_enabled', 0);

        $notre = $this->patrolIn($this->layer(), (int)$patrouille->id);

        $this->assertNotNull($notre, 'Turning the switch off hid the fleet from its owner.');

        $this->assertFalse($notre['commands']['move']['allowed'], 'Une manoeuvre est une nouvelle entree : elle reste fermee.');
        $this->assertSame('disabled', $notre['commands']['move']['reason_key']);

        $this->assertTrue(
            $notre['commands']['recall']['allowed'],
            'Le bouton Rappeler est grise : le joueur voit sa flotte sans pouvoir la faire rentrer.'
        );
        $this->assertNull($notre['commands']['recall']['reason_key']);
    }

    /**
     * Une patrouille n est listee que dans les systemes que son segment touche.
     */
    public function testAPatrolIsOnlyListedInTheSystemsItsSegmentTouches(): void
    {
        [$patrouille] = $this->aParkedPatrol();

        $this->assertNotNull($this->patrolIn($this->layer(), (int)$patrouille->id));
        $this->assertNull($this->patrolIn($this->layer(1), (int)$patrouille->id), 'The patrol is listed in a system its leg never touches.');
    }

    /**
     * Chaque refus que le code peut emettre, et chaque etat, a sa traduction dans les deux langues.
     *
     * `__()` rend la clef quand la traduction manque, sans erreur : un refus non traduit se lirait
     * « t_ingame.patrol.refusal_x » sous un bouton. Les clefs sont relevees **dans les sources**,
     * pas recopiees ici — une nouvelle raison non traduite fait rougir ce temoin.
     *
     * **Sans repli de langue.** `Lang::has()` retombe par defaut sur l anglais : une clef absente du
     * francais y etait trouvee, et le temoin ne voyait rien. C est une mutation qui l a montre.
     */
    public function testEveryRefusalTheCodeCanEmitIsTranslatedInBothLanguages(): void
    {
        $fichiers = array_merge(
            glob(base_path('app/Patrol/*.php')) ?: [],
            glob(base_path('app/Patrol/*/*.php')) ?: [],
            [base_path('app/GameMissions/PatrolMission.php'), base_path('app/Http/Controllers/PatrolController.php')]
        );

        $raisons = [];

        // Trois formes : l exception levee, le devis refuse, et la raison rendue par un decideur
        // (`return 'x';`) — celle que la carte lit sous un bouton et que le controleur traduit.
        foreach ($fichiers as $fichier) {
            $source = (string)file_get_contents($fichier);
            foreach (["/PatrolOrderRefused\\('([a-z_]+)'\\)/", "/refusedBecause\\('([a-z_]+)'\\)/", "/return '([a-z_]+)';/"] as $motif) {
                preg_match_all($motif, $source, $trouves);
                array_push($raisons, ...$trouves[1]);
            }
        }

        $raisons = array_values(array_unique($raisons));
        sort($raisons);

        $this->assertGreaterThanOrEqual(15, count($raisons), 'The scan found too few reasons: the pattern no longer matches the code.');

        foreach ($raisons as $raison) {
            foreach (['fr', 'en'] as $langue) {
                $this->assertTrue(
                    Lang::has('t_ingame.patrol.refusal_' . $raison, $langue, false),
                    'The refusal "' . $raison . '" has no ' . $langue . ' translation.'
                );
            }
        }

        foreach (PatrolState::cases() as $etat) {
            foreach (['fr', 'en'] as $langue) {
                $this->assertTrue(
                    Lang::has('t_ingame.patrol.state_' . $etat->value, $langue, false),
                    'The state "' . $etat->value . '" has no ' . $langue . ' translation.'
                );
            }
        }
    }
}
