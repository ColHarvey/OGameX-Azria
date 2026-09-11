<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Hull\DamagedHulls;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Patrol;
use OGame\Models\Resources;
use OGame\Patrol\Combat\SpatialBattle;
use OGame\Patrol\Combat\SpatialSettlement;
use OGame\Patrol\Enums\PatrolState;
use OGame\Patrol\FrozenPatrolTarget;
use OGame\Patrol\Geometry\SpatialPoint;
use OGame\Patrol\SpatialAttackOrder;
use OGame\Services\ObjectService;
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
    private function unePatrouillePosee(array $unites, int $x = 120, int $y = -80, int $galaxie = 4, int $systeme = 77): array
    {
        $defenseur = $this->getSecondPlayerId();

        $patrouille = Patrol::forceCreate([
            'user_id' => $defenseur,
            'home_planet_id' => null,
            'state' => PatrolState::Stationed,
            'galaxy' => $galaxie,
            'system' => $systeme,
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
        $segment->galaxy_to = $galaxie;
        $segment->system_to = $systeme;
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

    /**
     * **L arrivee traverse le vrai travailleur, avec le combat durable arme.**
     *
     * ## Pourquoi cet essai existe, et pourquoi il precede l activation
     *
     * Les autres essais de cette classe appellent la bataille et son reglement directement. Ils
     * etablissent ce que fait la bataille ; ils n etablissent pas que l arrivee **y parvient**.
     *
     * Et le chemin qui y mene n est pas anodin. La production tourne avec
     * `persistent_combat_enabled` a 1. Une attaque spatiale est une attaque de **genre 1** :
     * `PlayerService::isGovernedByTheCombatGate()` classe par genre, sans regarder la cible, donc
     * elle range cette mission parmi celles « gouvernees par le combat » et la fait passer par
     * `FleetMovementGate`. Or l ordre des verrous de cette porte commence par une **barriere ancree
     * sur un corps celeste** — et cette mission n a pas de corps a l arrivee (`planet_id_to` est
     * vide).
     *
     * La porte prevoit ce cas et ne prend aucune barriere quand la cible est nulle. **Mais cela se
     * lisait dans le code, et rien ne l executait.** Armer les patrouilles sur une lecture de code
     * aurait mis cette lecture en production.
     *
     * ## Ce que l essai exige
     *
     * Que la bataille ait eu lieu — par ses effets, jamais par un appel direct —, que la mission
     * soit traitee, que la flotte reparte, et **qu aucun combat durable n ait ete ouvert** : le
     * chemin spatial est distinct, et une instance creee ici voudrait dire que la mission est partie
     * dans la mecanique des corps celestes.
     */
    public function testUneAttaqueSpatialeArriveParLeTravailleurQuandLeCombatDurableEstArme(): void
    {
        // **Dans le systeme de l attaquant** : c est le seul cas reel, la surveillance ne voyant
        // que les patrouilles des systemes ou le joueur possede un corps. C est aussi ce qui rend
        // le trajet payable — une attaque a trois galaxies de la n a pas de carburant.
        $chezMoi = $this->planetService->getPlanetCoordinates();
        [$patrouille] = $this->unePatrouillePosee(['light_fighter' => 5], 120, -80, $chezMoi->galaxy, $chezMoi->system);

        // **Le vrai lanceur, pas une ligne fabriquee a la main.** Les essais voisins montent la
        // mission eux-memes, ce qui convient pour eprouver la bataille ; ici c est le trajet complet
        // qui est en cause, et une mission fabriquee a la main n a pas les colonnes que le lanceur
        // pose — `type_to` et `position_to`, dont depend la creation du retour.
        $this->planetService->addUnit('battle_ship', 60);
        $this->planetService->addResources(new Resources(0, 0, 5_000_000, 0));
        $this->planetService->reloadPlanet();

        $flotte = new UnitCollection();
        $flotte->addUnit(ObjectService::getUnitObjectByMachineName('battle_ship'), 60);

        // **Compte en ecart, jamais a zero** : la base d un processus garde les combats de ses
        // voisins.
        $instancesAvant = (int)DB::table('combat_instances')->count();
        $missionsAvant = (int)DB::table('fleet_missions')->where('user_id', $this->currentUserId)->count();

        $deuteriumAvant = (int)$this->planetService->deuterium()->get();

        $attaque = resolve(SpatialAttackOrder::class)->launch(
            $this->planetService,
            $flotte,
            FrozenPatrolTarget::of($patrouille),
            10.0,
            (int)now()->timestamp
        );

        // **Le trajet coute vraiment.** Apres le defaut de la reserve a zero — qui refusait toute
        // attaque —, il ne suffit pas que le depart passe : il faut etablir qu il n est pas devenu
        // gratuit. Distance non nulle, carburant strictement positif, et le corps l a paye.
        $carburant = (int)$attaque->deuterium_consumption;

        $this->assertGreaterThan(0, $carburant, 'Le trajet ne coute rien : l attaque serait gratuite.');

        $this->planetService->reloadPlanet();

        $this->assertSame(
            $deuteriumAvant - $carburant,
            (int)$this->planetService->deuterium()->get(),
            'Le corps n a pas paye exactement ce que la mission annonce.'
        );

        // Le vol n est pas l objet de cet essai : l arrivee est ramenee dans le passe pour que le
        // travailleur ait quelque chose a traiter.
        DB::table('fleet_missions')
            ->where('id', $attaque->id)
            ->update(['time_arrival' => (int)now()->timestamp - 1]);

        resolve(SettingsService::class)->set('persistent_combat_enabled', '1');

        try {
            // Le vrai point d entree du jeu : ce que fait une page du joueur attaquant.
            resolve(PlayerServiceFactory::class)->make($this->currentUserId, true)->updateFleetMissions();
        } finally {
            resolve(SettingsService::class)->set('persistent_combat_enabled', '0');
        }

        $relue = FleetMission::find($attaque->id);
        $this->assertNotNull($relue, 'La mission attaquante doit rester en base.');
        $this->assertSame(1, (int)$relue->processed, 'Le travailleur n a pas traite l arrivee spatiale : la flotte resterait en vol.');

        $patrouille->refresh();

        $this->assertSame(
            PatrolState::Destroyed->value,
            $patrouille->state->value ?? $patrouille->state,
            'La bataille n a pas eu lieu : soixante vaisseaux de bataille contre cinq chasseurs legers ne laissent rien.'
        );

        // **Aucun combat durable ouvert.** Le chemin spatial est distinct de celui des corps.
        $this->assertSame(
            $instancesAvant,
            (int)DB::table('combat_instances')->count(),
            'Une attaque spatiale a ouvert un combat durable : elle est partie dans la mecanique des corps celestes.'
        );

        // La flotte repart : l aller plus un retour, et un seul.
        $this->assertSame(
            $missionsAvant + 2,
            (int)DB::table('fleet_missions')->where('user_id', $this->currentUserId)->count(),
            'La flotte victorieuse doit repartir, et une seule fois.'
        );

        // **Rien n est repris apres coup** : l aller et le retour ont ete preleves au depart, et le
        // corps ne doit plus bouger de ce fait. Sans ce controle, corriger le refus total aurait pu
        // introduire un second debit — ou un trajet de retour gratuit.
        $this->planetService->reloadPlanet();

        $this->assertSame(
            $deuteriumAvant - $carburant,
            (int)$this->planetService->deuterium()->get(),
            'Le corps a ete debite une seconde fois par le trajet.'
        );

        $retour = FleetMission::where('parent_id', $attaque->id)->first();
        $this->assertNotNull($retour, 'Aucun retour : la flotte est perdue en chemin.');
        $this->assertSame(
            $this->planetService->getPlanetId(),
            (int)$retour->planet_id_to,
            'Le retour doit viser le corps de depart.'
        );
    }

    /**
     * **L exception est etroite : une attaque planetaire sans cible garde son ancien comportement.**
     *
     * `GameMission::process()` renvoie chez elle toute mission arrivee sans corps a l arrivee — c est
     * ainsi qu une attaque dont la planete a ete colonisee, detruite ou deplacee se termine sans
     * incident. Le chantier des patrouilles a du y ouvrir une exception, sans quoi aucune attaque
     * spatiale n atteignait jamais `processArrival()`.
     *
     * **Cette exception reconnait une cible spatiale, pas une planete manquante.** Ecrite
     * « genre 1 sans `planet_id_to` », elle aurait laisse passer le cas ci-dessous jusqu a
     * `processArrival()`, qui leve « Attack mission has no target planet » : une attaque ordinaire se
     * serait mise a planter le travailleur du joueur la ou elle rendait tranquillement sa flotte.
     *
     * Le discriminant est `type_to` — celui que l administration emploie deja pour ne pas declarer
     * ces missions bloquees.
     */
    public function testUneAttaquePlanetaireSansCibleGardeSonAncienComportement(): void
    {
        $this->planetService->addUnit('battle_ship', 10);
        $this->planetService->reloadPlanet();

        $coords = $this->planetService->getPlanetCoordinates();

        // Une attaque **planetaire** — `type_to` est une planete — dont le corps vise a disparu.
        $mission = new FleetMission();
        $mission->user_id = $this->currentUserId;
        $mission->mission_type = 1;
        $mission->planet_id_from = $this->planetService->getPlanetId();
        $mission->type_from = PlanetType::Planet->value;
        $mission->galaxy_from = $coords->galaxy;
        $mission->system_from = $coords->system;
        $mission->position_from = $coords->position;
        $mission->planet_id_to = null;
        $mission->type_to = PlanetType::Planet->value;
        $mission->galaxy_to = $coords->galaxy;
        $mission->system_to = $coords->system;
        $mission->position_to = $coords->position === 1 ? 2 : 1;
        $mission->time_departure = (int)now()->timestamp - 600;
        $mission->time_arrival = (int)now()->timestamp - 1;
        $mission->processed = 0;
        $mission->canceled = 0;
        $mission->metal = 0;
        $mission->crystal = 0;
        $mission->deuterium = 0;
        $mission->battle_ship = 10;
        $mission->save();

        // Aucune exception : c est la moitie du verdict, et elle ne se voit que si le travailleur
        // tourne pour de vrai.
        resolve(PlayerServiceFactory::class)->make($this->currentUserId, true)->updateFleetMissions();

        $relue = FleetMission::query()->findOrFail($mission->id);

        $this->assertSame(1, (int)$relue->processed, 'La mission sans cible n a pas ete traitee.');

        $retour = FleetMission::where('parent_id', $mission->id)->first();

        $this->assertNotNull($retour, 'La flotte d une attaque sans cible doit rentrer, comme elle l a toujours fait.');
        $this->assertSame(10, (int)$retour->battle_ship, 'Elle rentre entiere : rien ne s est battu.');
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
