<?php

namespace Tests\Feature;

use OGame\Factories\PlayerServiceFactory;
use OGame\Hull\DamagedHulls;
use OGame\Models\FleetMission;
use OGame\Models\Patrol;
use OGame\Models\Resources;
use OGame\Patrol\Combat\SpatialBattle;
use OGame\Patrol\Combat\SpatialSettlement;
use OGame\Patrol\Enums\PatrolState;
use OGame\Patrol\Geometry\SpatialPoint;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;

/**
 * Une patrouille peut enfin etre attaquee.
 *
 * ------------------------------------------------------------------------------------
 * CE QUE CES ESSAIS ETABLISSENT, ET POURQUOI ILS N EXISTAIENT PAS AVANT
 *
 * Le socle du combat spatial etait ecrit et eprouve **piece par piece** depuis le 10 septembre 2026 :
 * le lieu synthetique, les combattants geles, l ouverture du champ. Mais rien ne l appelait — une
 * patrouille etait **invulnerable**, et aucun essai ne le disait, parce qu aucun essai ne montait la
 * chaine complete.
 *
 * Ceux-ci la montent : deux flottes reelles, une bataille jouee par le moteur partage, et les quatre
 * effets du reglement verifies sur la base.
 */
class SpatialBattleTest extends AccountTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        resolve(SettingsService::class)->set('patrols_enabled', '1');
        resolve(SettingsService::class)->set('hull_damage_enabled', '1');
    }

    protected function tearDown(): void
    {
        resolve(SettingsService::class)->set('patrols_enabled', '0');
        resolve(SettingsService::class)->set('hull_damage_enabled', '0');

        parent::tearDown();
    }

    /**
     * Monte une patrouille posee, avec son segment et ses unites.
     *
     * @return array{0: Patrol, 1: FleetMission}
     */
    private function unePatrouillePosee(array $unites, int $x = 120, int $y = -80): array
    {
        $defenseur = $this->getSecondPlayerId();

        $patrouille = Patrol::forceCreate([
            'user_id' => $defenseur,
            'home_planet_id' => null,
            'state' => PatrolState::Stationed,
            'galaxy' => 4,
            'system' => 77,
            'x' => $x,
            'y' => $y,
            'fuel_reserve' => 5000.0,
            'upkeep_paid_at' => null,
            'order_version' => 1,
        ]);

        $segment = new FleetMission();
        $segment->user_id = $defenseur;
        $segment->patrol_id = (int)$patrouille->id;
        $segment->mission_type = 11;
        $segment->galaxy_to = 4;
        $segment->system_to = 77;
        $segment->x_to = $x;
        $segment->y_to = $y;
        $segment->time_departure = (int)now()->timestamp - 3600;
        $segment->time_arrival = (int)now()->timestamp - 1800;
        $segment->processed = 0;
        $segment->canceled = 0;
        $segment->metal = 4000;
        $segment->crystal = 2000;
        $segment->deuterium = 0;

        foreach ($unites as $type => $nombre) {
            $segment->{$type} = $nombre;
        }

        $segment->save();

        $patrouille->forceFill(['current_mission_id' => (int)$segment->id])->save();

        return [$patrouille, $segment];
    }

    /**
     * Monte une flotte attaquante arrivee sur le point.
     */
    private function uneAttaqueArrivee(Patrol $cible, FleetMission $segment, array $unites): FleetMission
    {
        $mission = new FleetMission();
        $mission->user_id = $this->currentUserId;
        $mission->mission_type = 1;
        $mission->planet_id_from = $this->planetService->getPlanetId();
        $mission->planet_id_to = null;
        $mission->target_patrol_id = (int)$cible->id;
        $mission->target_patrol_owner_id = (int)$cible->user_id;
        $mission->galaxy_from = 1;
        $mission->system_from = 1;
        $mission->position_from = 1;
        $mission->galaxy_to = (int)$cible->galaxy;
        $mission->system_to = (int)$cible->system;
        $mission->x_to = (int)$segment->x_to;
        $mission->y_to = (int)$segment->y_to;
        $mission->time_departure = (int)now()->timestamp - 600;
        $mission->time_arrival = (int)now()->timestamp;
        $mission->processed = 0;
        $mission->canceled = 0;
        $mission->metal = 0;
        $mission->crystal = 0;
        $mission->deuterium = 0;

        foreach ($unites as $type => $nombre) {
            $mission->{$type} = $nombre;
        }

        $mission->save();

        return $mission;
    }

    public function testUneFlotteEcrasanteDetruitUnePatrouilleEtLaisseSesDebrisSurLePoint(): void
    {
        [$patrouille, $segment] = $this->unePatrouillePosee(['light_fighter' => 5]);
        $attaque = $this->uneAttaqueArrivee($patrouille, $segment, ['battle_ship' => 60]);

        $resultat = resolve(SpatialBattle::class)->fight($attaque, $patrouille, $segment);

        // **La bataille a vraiment eu lieu** : sans cela, tout ce qui suit ne prouverait rien.
        $this->assertGreaterThan(0, count($resultat->rounds), 'Aucun round : les deux camps ne se sont pas rencontres.');
        $this->assertSame(0, $resultat->defenderUnitsResult->getAmount(), 'La patrouille devait etre ecrasee.');

        $retours = 0;

        resolve(SpatialSettlement::class)->settle(
            $resultat,
            $attaque,
            $patrouille,
            $segment,
            function () use (&$retours): void {
                $retours++;
            }
        );

        $patrouille->refresh();

        // La patrouille est detruite — pas « terminee », ce qui voudrait dire rentree.
        $this->assertSame(PatrolState::Destroyed->value, $patrouille->state->value ?? $patrouille->state);
        $this->assertSame(0.0, (float)$patrouille->fuel_reserve, 'La reserve meurt avec la patrouille.');
        $this->assertNull($patrouille->current_mission_id);

        // **Les debris restent au point**, pas sur une planete.
        $debris = \Illuminate\Support\Facades\DB::table('space_debris_fields')
            ->where('galaxy', 4)->where('system', 77)
            ->where('x', (int)$segment->x_to)->where('y', (int)$segment->y_to)
            ->first();

        $this->assertNotNull($debris, 'Aucun champ de debris au point de la bataille.');
        $this->assertGreaterThan(0, (float)$debris->metal + (float)$debris->crystal);

        // L attaquante repart.
        $this->assertSame(1, $retours, 'La flotte victorieuse doit repartir.');

        $relue = FleetMission::find($attaque->id);
        $this->assertNotNull($relue, 'La mission attaquante reste en base, marquee traitee.');
        $this->assertSame(1, (int)$relue->processed);
    }

    public function testUnePatrouilleQuiSurvitGardeSesCoquesEntamees(): void
    {
        // Une patrouille lourde contre une attaque legere : elle survit, entamee.
        // **Le rebond decide ici, et il fallait le mesurer.** Un degat sous un centieme du
        // bouclier n entame rien : trente chasseurs legers contre quarante vaisseaux de bataille
        // ressortent sans avoir raye la peinture, et l essai passerait sur du vide. Il faut une
        // flotte qui perce sans ecraser.
        [$patrouille, $segment] = $this->unePatrouillePosee(['battle_ship' => 40], 200, -140);
        $attaque = $this->uneAttaqueArrivee($patrouille, $segment, ['cruiser' => 90]);

        $resultat = resolve(SpatialBattle::class)->fight($attaque, $patrouille, $segment);

        $this->assertGreaterThan(0, $resultat->defenderUnitsResult->getAmount(), 'La patrouille devait survivre.');

        resolve(SpatialSettlement::class)->settle(
            $resultat,
            $attaque,
            $patrouille,
            $segment,
            function (): void {
            }
        );

        $segment->refresh();
        $patrouille->refresh();

        $this->assertSame(PatrolState::Stationed->value, $patrouille->state->value ?? $patrouille->state);

        $coques = DamagedHulls::fromStorage($segment->damaged_hulls);

        // **Elle a pris des coups et les garde.** Sans cela, la persistance des degats ne servirait
        // a rien precisement la ou les combats se repetent le plus.
        $this->assertFalse($coques->isEmpty(), 'Une patrouille qui a subi une attaque doit garder ses degats.');
        $this->assertLessThanOrEqual(
            (int)$segment->battle_ship,
            $coques->damagedCountOf('battle_ship'),
            'L invariant est brise : plus d abimees que d unites presentes.'
        );
    }

    public function testUneFlotteAbimeeAttaqueAbimee(): void
    {
        [$patrouille, $segment] = $this->unePatrouillePosee(['light_fighter' => 40]);
        $attaque = $this->uneAttaqueArrivee($patrouille, $segment, ['cruiser' => 20]);

        // Douze des vingt croiseurs arrivent deja a moitie detruits.
        $attaque->damaged_hulls = DamagedHulls::of(['cruiser' => [5000 => 12]])->toStorage();
        $attaque->save();

        $bataille = resolve(SpatialBattle::class);
        $resultat = $bataille->fight($attaque, $patrouille, $segment);

        // La flotte entre entamee : la bataille se joue donc autrement qu avec une flotte neuve.
        // On l etablit par l effet le plus direct — elle perd plus que la meme flotte intacte.
        $attaqueIntacte = $this->uneAttaqueArrivee($patrouille, $segment, ['cruiser' => 20]);
        $resultatIntact = $bataille->fight($attaqueIntacte, $patrouille, $segment);

        $this->assertGreaterThanOrEqual(
            $resultatIntact->attackerUnitsLost->getAmount(),
            $resultat->attackerUnitsLost->getAmount(),
            'Une flotte abimee ne perd pas moins qu une flotte neuve : les degats n ont pas ete appliques.'
        );
    }

    public function testLesDebrisDeDeuxBataillesAuMemePointSAdditionnent(): void
    {
        // **Un point propre a cet essai.** La base d un processus garde les debris de ses
        // voisins, et compter a zero sur un point partage donnerait le total de la classe.
        [$patrouille, $segment] = $this->unePatrouillePosee(['light_fighter' => 3], -310, 260);
        $point = new SpatialPoint((int)$segment->x_to, (int)$segment->y_to);

        $bataille = resolve(SpatialBattle::class);

        // **On compte en ecart, jamais a zero.** La base d un processus garde ce qu un passage
        // precedent a laisse au meme point, et un total absolu additionnerait les executions.
        $avant = (float)(\Illuminate\Support\Facades\DB::table('space_debris_fields')
            ->where('galaxy', 4)->where('system', 77)
            ->where('x', $point->x)->where('y', $point->y)
            ->value('metal') ?? 0);

        $bataille->leaveDebrisAt(4, 77, $point, new Resources(1000, 500, 0, 0));
        $bataille->leaveDebrisAt(4, 77, $point, new Resources(2000, 1500, 0, 0));

        $debris = \Illuminate\Support\Facades\DB::table('space_debris_fields')
            ->where('galaxy', 4)->where('system', 77)
            ->where('x', $point->x)->where('y', $point->y)
            ->first();

        $this->assertNotNull($debris);

        // **Une addition faite en base**, jamais une relecture suivie d une ecriture : deux batailles
        // au meme point ne doivent pas s effacer l une l autre.
        $this->assertSame(3000.0, (float)$debris->metal - $avant, 'Les deux depots ne se sont pas additionnes.');

        unset($patrouille);
    }
}
