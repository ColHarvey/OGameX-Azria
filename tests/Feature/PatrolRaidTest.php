<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use OGame\Combat\Enums\ReturnDestinationKind;
use OGame\Combat\Services\ReturnPlanner;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Highscore;
use OGame\Models\Patrol;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Models\SurveillanceContact;
use OGame\Models\User;
use OGame\Patrol\Enums\PatrolState;
use OGame\Patrol\Exceptions\PatrolOrderRefused;
use OGame\Patrol\FrozenPatrolTarget;
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
 * **Une patrouille frappe depuis son point, et y revient.**
 *
 * ## Ce que ce banc ferme
 *
 * Jusqu ici une attaque ne pouvait partir que d un corps. Une patrouille posee voyait une cible, et
 * n avait aucun moyen de l atteindre : il fallait la rappeler chez elle, recomposer une flotte, et
 * repartir — le temps que la cible s en aille. La surveillance montrait des proies hors de portee.
 *
 * ## Ce que chaque temoin etablit, et pourquoi il fallait celui-la
 *
 * Le **depart** : ce qui est ecrit sur la mission, et ce qui est debite. Le carburant est celui d un
 * **aller-retour** — une attaque revient toujours —, et le temoin le compare au trajet simple pour
 * que « juste » et « faux » ne coincident pas.
 *
 * Le **retour**, qui est la moitie neuve du chantier : la flotte se repose au point, la patrouille
 * redevient posee, et son segment reste **non traite** — c est lui qui porte son creneau de flotte
 * et sa presence dans la boite d evenements.
 *
 * La **retombee** : patrouille disparue en vol, la flotte n est pas perdue pour autant. Elle
 * atterrit sur un corps choisi par le protocole unique des retours, jamais par un `??`.
 *
 * Les **refus**, enfin, parce qu une action qui ne peut pas aboutir ne doit pas etre offerte : la
 * carte lit exactement la reponse que la confirmation appliquera.
 */
class PatrolRaidTest extends AccountTestCase
{
    /** @var array<int, int> */
    private array $corpsPoses = [];

    /** @var array<int, int> */
    private array $patrouillesPosees = [];

    /**
     * L etat d origine de chaque joueur dont ce banc touche le score ou l activite.
     *
     * **Retenir avant d ecrire.** La base d un processus est partagee entre les classes : un score
     * laisse en place change ce que le banc voisin voit, et celui de l affichage des cibles a deja
     * rougi exactement ainsi — sans y etre pour rien.
     *
     * @var array<int, array{score: int|null, time: int}>
     */
    private array $joueursTouches = [];

    protected function tearDown(): void
    {
        /*
         * **La base d un processus est partagee entre les classes.** Une patrouille ou un corps
         * laisses en place changent ce que le banc voisin voit — c est arrive, et le temoin qui a
         * rougi n y etait pour rien.
         */
        if ($this->patrouillesPosees !== []) {
            SurveillanceContact::query()->whereIn('patrol_id', $this->patrouillesPosees)->delete();
            FleetMission::query()->whereIn('patrol_id', $this->patrouillesPosees)->delete();
            Patrol::query()->whereIn('id', $this->patrouillesPosees)->delete();
            $this->patrouillesPosees = [];
        }

        if ($this->corpsPoses !== []) {
            SurveillanceContact::query()->whereIn('observer_planet_id', $this->corpsPoses)->delete();
            Planet::query()->whereIn('id', $this->corpsPoses)->delete();
            $this->corpsPoses = [];
        }

        foreach ($this->joueursTouches as $userId => $etat) {
            User::query()->whereKey($userId)->update(['time' => $etat['time']]);

            if ($etat['score'] === null) {
                Highscore::query()->where('player_id', $userId)->delete();
            } else {
                Highscore::query()->where('player_id', $userId)->update(['general' => $etat['score']]);
            }
        }

        $this->joueursTouches = [];

        resolve(SettingsService::class)->set('newbie_protection_enabled', 0);
        resolve(SettingsService::class)->set('patrols_enabled', 0);
        Date::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------------ le montage

    private function armer(): void
    {
        resolve(SettingsService::class)->set('patrols_enabled', 1);
        $this->playerSetResearchLevel('computer_technology', 10);
    }

    private function ordres(): PatrolOrders
    {
        return resolve(PatrolOrders::class);
    }

    private function joueur(): PlayerService
    {
        return resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);
    }

    private function flotte(): UnitCollection
    {
        $units = new UnitCollection();
        $units->addUnit(ObjectService::getUnitObjectByMachineName('cruiser'), 20);

        return $units;
    }

    private function pointA(int $x, int $y): PatrolDestination
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
     * Une patrouille du joueur, posee a son point, prete a recevoir un ordre.
     *
     * @return array{0: Patrol, 1: FleetMission}
     */
    private function unePatrouillePosee(float $reserve = 10000): array
    {
        $this->armer();
        $this->planetAddResources(new Resources(0, 0, 60000, 0));
        $this->planetAddUnit('cruiser', 20);

        $patrouille = $this->ordres()->launch(
            $this->planetService,
            $this->flotte(),
            new Resources(0, 0, 0, 0),
            (int)$reserve,
            $this->pointA(600, 600),
            10,
            (int)Date::now()->timestamp
        );

        $this->patrouillesPosees[] = (int)$patrouille->id;

        $segment = FleetMission::query()->findOrFail($patrouille->current_mission_id);

        Date::setTestNow(Date::createFromTimestamp((int)$segment->time_arrival + 1));
        $this->joueur()->updateFleetMissions();

        return [$patrouille->refresh(), $segment->refresh()];
    }

    /**
     * Une cible : la patrouille d un autre joueur, posee dans le meme systeme.
     */
    private function uneCible(): Patrol
    {
        $coords = $this->planetService->getPlanetCoordinates();
        $etrangere = $this->getNearbyForeignPlanet();
        $proprietaire = $etrangere->getPlayer();

        $this->assertNotNull($proprietaire, 'La premisse manque : la planete voisine n a pas de proprietaire.');
        $this->assertNotSame($this->currentUserId, $proprietaire->getId());

        $cible = Patrol::query()->create([
            'user_id' => $proprietaire->getId(),
            'home_planet_id' => null,
            'state' => PatrolState::Stationed->value,
            'galaxy' => $coords->galaxy,
            'system' => $coords->system,
            'x' => -620,
            'y' => 480,
            'fuel_reserve' => 500,
            'order_version' => 1,
        ]);

        $this->patrouillesPosees[] = (int)$cible->id;

        return $cible;
    }

    // ------------------------------------------------------------------ le depart

    /**
     * **Ce que le depart ecrit, et ce qu il laisse intact.**
     *
     * Le point de la patrouille ne bouge pas : c est l adresse a laquelle ses vaisseaux reviendront,
     * et l effacer ferait retomber le retour sur la base alors que rien ne l exige.
     */
    public function testUnePatrouillePoseeFrappeEtGardeSonPoint(): void
    {
        [$patrouille, $pose] = $this->unePatrouillePosee();

        $xAvant = (int)$patrouille->x;
        $yAvant = (int)$patrouille->y;
        $cible = $this->uneCible();
        $instant = (int)$pose->time_arrival + 3600;

        Date::setTestNow(Date::createFromTimestamp($instant));

        $attaque = $this->ordres()->attackFrom(
            $patrouille,
            FrozenPatrolTarget::of($cible),
            10,
            (int)$patrouille->order_version,
            $instant
        );

        $patrouille->refresh();
        $pose->refresh();

        $this->assertSame(PatrolState::Attacking, $patrouille->state, 'La patrouille n est pas passee en offensive.');
        $this->assertSame((int)$attaque->id, (int)$patrouille->current_mission_id, 'Le vol courant n est pas l attaque.');
        $this->assertSame(1, (int)$pose->processed, 'Le segment pose vit encore a cote de l attaque : deux vols pour une flotte.');

        $this->assertSame($xAvant, (int)$patrouille->x, 'La patrouille a perdu son point : sa flotte n aurait plus ou revenir.');
        $this->assertSame($yAvant, (int)$patrouille->y);

        $this->assertSame(1, (int)$attaque->mission_type, 'L attaque n est pas une attaque ordinaire de genre 1.');
        $this->assertSame((int)$patrouille->id, (int)$attaque->patrol_id, 'La mission ne nomme pas sa patrouille : son retour ne saurait pas ou aller.');
        $this->assertSame((int)$cible->id, (int)$attaque->target_patrol_id);
        $this->assertSame((int)$cible->user_id, (int)$attaque->target_patrol_owner_id, 'Le proprietaire gele manque : une patrouille qui change de mains resterait la meme cible.');

        $this->assertSame(PlanetType::SpatialPoint->value, (int)$attaque->type_from, 'Le depart n est pas declare comme un point de l espace.');
        $this->assertSame($xAvant, (int)$attaque->x_from);
        $this->assertSame($yAvant, (int)$attaque->y_from);
        $this->assertNull($attaque->planet_id_to, 'L attaque vise un corps alors qu elle vise un point.');
        $this->assertSame(-620, (int)$attaque->x_to);
        $this->assertSame(480, (int)$attaque->y_to);

        $this->assertSame(20, (int)$attaque->cruiser, 'Les vaisseaux ne sont pas passes sur la mission d attaque.');

        // **L ancre administrative est une planete vivante** : sans elle, le travailleur des pages ne
        // trouverait jamais cette mission, et la flotte ne rentrerait jamais.
        $this->assertNotNull($attaque->planet_id_from);
    }

    /**
     * **Le carburant preleve est celui d un aller-retour.**
     *
     * Une attaque revient toujours a son point : ne prelever que l aller rendrait le retour gratuit,
     * et une patrouille pourrait frapper indefiniment a moitie prix.
     *
     * Le temoin compare au **trajet simple**, mesure par le devis d un deplacement vers le meme
     * point : sans cette comparaison, « le double » et « le simple » seraient deux nombres
     * quelconques, et n importe quel debit aurait passe.
     */
    public function testLeCarburantPreleveEstCeluiDUnAllerRetour(): void
    {
        [$patrouille, $pose] = $this->unePatrouillePosee();

        $cible = $this->uneCible();
        $instant = (int)$pose->time_arrival + 3600;

        Date::setTestNow(Date::createFromTimestamp($instant));

        $versLaCible = $this->pointA((int)$cible->x, (int)$cible->y);
        $trajetSimple = $this->ordres()->quoteFor($patrouille, $versLaCible, 10, $instant)->fuelCost;

        $this->assertGreaterThan(0, $trajetSimple, 'La premisse manque : ce trajet ne coute rien, le double de zero vaut zero.');

        $devis = $this->ordres()->quoteForAttack($patrouille, FrozenPatrolTarget::of($cible), 10, $instant);

        $this->assertSame(2 * $trajetSimple, $devis->fuelCost, 'Le devis n annonce pas un aller-retour.');

        $reserveAvant = (float)$patrouille->fuel_reserve;

        /*
         * **Le stationnement deja consomme se paie avant le raid**, comme avant tout ordre : sans
         * cela, partir juste avant l echeance effacerait l heure ecoulee. Le temoin le **nomme** au
         * lieu de l absorber dans une tolerance — un ecart qu on tolere est un ecart qu on ne mesure
         * plus, et c est ainsi qu un debit faux passerait.
         */
        $stationnementDu = resolve(PatrolUpkeep::class)->dueBetween(
            $this->flotte(),
            (int)$patrouille->upkeep_paid_at,
            $instant
        );

        $this->assertGreaterThan(
            0.0,
            $stationnementDu,
            'La premisse manque : rien n est du, et le temoin ne distinguerait plus les deux termes.'
        );

        $this->ordres()->attackFrom($patrouille, FrozenPatrolTarget::of($cible), 10, (int)$patrouille->order_version, $instant);

        $patrouille->refresh();

        $this->assertEqualsWithDelta(
            $reserveAvant - $stationnementDu - (2 * $trajetSimple),
            (float)$patrouille->fuel_reserve,
            1.0,
            'Le debit ne vaut pas le stationnement du plus un aller-retour : le retour serait gratuit.'
        );
    }

    // ------------------------------------------------------------------ les refus

    /**
     * **Une patrouille en vol ne frappe pas.** Ses unites sont entre deux endroits : son attaque
     * n aurait aucun point ou revenir.
     */
    public function testUnePatrouilleEnVolNePeutPasFrapper(): void
    {
        $this->armer();
        $this->planetAddResources(new Resources(0, 0, 60000, 0));
        $this->planetAddUnit('cruiser', 20);

        $patrouille = $this->ordres()->launch(
            $this->planetService,
            $this->flotte(),
            new Resources(0, 0, 0, 0),
            10000,
            $this->pointA(600, 600),
            10,
            (int)Date::now()->timestamp
        );

        $this->patrouillesPosees[] = (int)$patrouille->id;

        $this->assertSame(PatrolState::EnRoute, $patrouille->state, 'La premisse manque : cette patrouille est deja posee.');
        $this->assertSame(
            'must_be_stationed_to_attack',
            $this->ordres()->whyAttackIsRefused($patrouille, (int)Date::now()->timestamp)
        );
    }

    /**
     * **Le cas comparable, et il compte autant que le refus.** Sans lui, une porte qui refuserait
     * tout passerait le temoin precedent.
     */
    public function testUnePatrouillePoseePeutFrapper(): void
    {
        [$patrouille, $pose] = $this->unePatrouillePosee();

        $this->assertNull(
            $this->ordres()->whyAttackIsRefused($patrouille, (int)$pose->time_arrival + 60),
            'Une patrouille posee se voit refuser la frappe : la porte refuse tout.'
        );
    }

    /**
     * **Frapper ne doit pas immobiliser.** Une patrouille qui rentrerait de son raid sans de quoi
     * regagner sa base serait condamnee : le refus est prononce avant, pas constate apres.
     *
     * La premisse est etablie d abord — la meme frappe, avec une reserve confortable, est permise —
     * sans quoi ce temoin serait vert pour n importe quelle autre raison.
     */
    public function testUneReserveQuiNeCouvriraitPlusLeRetourRefuseLaFrappe(): void
    {
        [$patrouille, $pose] = $this->unePatrouillePosee();

        $cible = $this->uneCible();
        $instant = (int)$pose->time_arrival + 3600;

        Date::setTestNow(Date::createFromTimestamp($instant));

        $gelee = FrozenPatrolTarget::of($cible);

        $this->assertTrue(
            $this->ordres()->quoteForAttack($patrouille, $gelee, 10, $instant)->isPossible(),
            'La premisse manque : cette frappe est deja refusee avec une reserve pleine.'
        );

        /*
         * **Juste de quoi partir et revenir — et rien pour rentrer chez elle ensuite.**
         *
         * Le stationnement deja consomme entre dans le compte, parce que l ordre le preleve avant de
         * chiffrer : l oublier ici faisait tomber l essai sur `not_enough_fuel`, un refus voisin mais
         * different. L un dit « tu ne peux pas y aller », l autre « tu peux y aller mais tu ne
         * pourras plus rentrer » — c est le second qui est juge ici.
         */
        $allerRetour = $this->ordres()->quoteForAttack($patrouille, $gelee, 10, $instant)->fuelCost;
        $du = resolve(PatrolUpkeep::class)->dueBetween(
            $this->flotte(),
            (int)$patrouille->upkeep_paid_at,
            $instant
        );

        $patrouille->forceFill(['fuel_reserve' => (float)$allerRetour + $du + 1.0])->save();

        $devis = $this->ordres()->quoteForAttack($patrouille->refresh(), $gelee, 10, $instant);

        $this->assertFalse($devis->isPossible(), 'La frappe est acceptee alors qu elle laisserait la patrouille sans retour.');
        $this->assertSame('no_return_reserve', $devis->refusal);

        $this->expectException(PatrolOrderRefused::class);

        $this->ordres()->attackFrom($patrouille, $gelee, 10, (int)$patrouille->order_version, $instant);
    }

    /**
     * **Un devis perime ne debite pas autre chose.** La version d ordre est la seule chose qui dise
     * « le monde que tu as lu n est plus celui-ci ».
     */
    public function testUnDevisPerimeEstRefuse(): void
    {
        [$patrouille, $pose] = $this->unePatrouillePosee();

        $cible = $this->uneCible();
        $instant = (int)$pose->time_arrival + 3600;

        Date::setTestNow(Date::createFromTimestamp($instant));

        $this->expectException(PatrolOrderRefused::class);

        $this->ordres()->attackFrom(
            $patrouille,
            FrozenPatrolTarget::of($cible),
            10,
            (int)$patrouille->order_version + 7,
            $instant
        );
    }

    // ------------------------------------------------------------------ le retour

    /**
     * **Le parcours entier : elle part, elle ne trouve rien, elle revient chez elle.**
     *
     * C est la moitie neuve du chantier. La cible disparait pendant le vol — une issue de jeu, pas
     * une panne : la flotte repart avec tout ce qu elle avait, et se repose au point.
     *
     * Le temoin exige que le segment revienne **non traite** : c est lui qui porte le creneau de
     * flotte de la patrouille, sa presence dans la boite d evenements et son inscription a un
     * combat. Un retour marque traite l aurait rendue invisible et sans creneau.
     */
    public function testLaFlotteRevientSePoserAuPointDeLaPatrouille(): void
    {
        [$patrouille, $pose] = $this->unePatrouillePosee();

        $cible = $this->uneCible();
        $instant = (int)$pose->time_arrival + 3600;
        $point = [(int)$patrouille->x, (int)$patrouille->y];

        Date::setTestNow(Date::createFromTimestamp($instant));

        $attaque = $this->ordres()->attackFrom(
            $patrouille,
            FrozenPatrolTarget::of($cible),
            10,
            (int)$patrouille->order_version,
            $instant
        );

        // La cible s en va : l attaque arrivera sur un point vide et repartira intacte.
        $cible->delete();

        Date::setTestNow(Date::createFromTimestamp((int)$attaque->time_arrival + 1));
        $this->joueur()->updateFleetMissions();

        $retour = FleetMission::query()->where('parent_id', $attaque->id)->first();

        $this->assertNotNull($retour, 'Aucun retour n a ete cree : la flotte est restee dans le vide.');
        $this->assertSame(PlanetType::SpatialPoint->value, (int)$retour->type_to, 'Le retour vise un corps au lieu du point de la patrouille.');
        $this->assertSame($point[0], (int)$retour->x_to, 'Le retour ne vise pas le point de la patrouille.');
        $this->assertSame($point[1], (int)$retour->y_to);

        Date::setTestNow(Date::createFromTimestamp((int)$retour->time_arrival + 1));
        $this->joueur()->updateFleetMissions();

        $patrouille->refresh();
        $retour->refresh();

        $this->assertSame(PatrolState::Stationed, $patrouille->state, 'La patrouille n est pas redevenue posee.');
        $this->assertSame((int)$retour->id, (int)$patrouille->current_mission_id, 'Le vol courant n est pas le retour qui vient de se poser.');
        $this->assertSame($point[0], (int)$patrouille->x, 'Elle s est reposee ailleurs qu a son point.');
        $this->assertSame($point[1], (int)$patrouille->y);

        $this->assertSame(0, (int)$retour->processed, 'Le segment pose est marque traite : la patrouille perd son creneau et sort de la boite d evenements.');
        $this->assertNotNull($retour->time_holding, 'Aucun rendez-vous n est arme : le retour de securite ne partirait jamais.');
        $this->assertSame(20, (int)$retour->cruiser, 'Les vaisseaux ne sont pas revenus.');
    }

    /**
     * **La patrouille a disparu pendant le raid : la flotte atterrit, elle n est pas perdue.**
     *
     * C est la retombee, et elle se decide par le protocole unique des retours — jamais par un `??`
     * qui poserait la flotte quelque part que personne n a choisi.
     */
    public function testUnePatrouilleDisparuePendantLeRaidFaitAtterrirLaFlotte(): void
    {
        [$patrouille, $pose] = $this->unePatrouillePosee();

        $cible = $this->uneCible();
        $instant = (int)$pose->time_arrival + 3600;

        Date::setTestNow(Date::createFromTimestamp($instant));

        $attaque = $this->ordres()->attackFrom(
            $patrouille,
            FrozenPatrolTarget::of($cible),
            10,
            (int)$patrouille->order_version,
            $instant
        );

        $cible->delete();

        Date::setTestNow(Date::createFromTimestamp((int)$attaque->time_arrival + 1));
        $this->joueur()->updateFleetMissions();

        $retour = FleetMission::query()->where('parent_id', $attaque->id)->firstOrFail();

        // La patrouille disparait pendant le vol de retour.
        FleetMission::query()->where('patrol_id', $patrouille->id)->update(['patrol_id' => $patrouille->id]);
        $identifiant = (int)$patrouille->id;
        Patrol::query()->whereKey($identifiant)->update(['current_mission_id' => null]);
        Patrol::query()->whereKey($identifiant)->delete();

        $croiseursAvant = (int)$this->planetService->getObjectAmount('cruiser');

        Date::setTestNow(Date::createFromTimestamp((int)$retour->time_arrival + 1));
        $this->joueur()->updateFleetMissions();

        $retour->refresh();
        $this->planetService->reloadPlanet();

        $this->assertSame(1, (int)$retour->processed, 'Le retour est reste ouvert : le travailleur le reprendrait indefiniment.');
        $this->assertSame(
            $croiseursAvant + 20,
            (int)$this->planetService->getObjectAmount('cruiser'),
            'Les vaisseaux ne sont pas rentres : ils ont disparu avec la patrouille.'
        );
    }

    // ------------------------------------------------------------------ le parcours reel

    /**
     * **Le parcours du joueur, depuis l adresse que la carte appelle.**
     *
     * Des classes vertes ne disent rien de ce qu un joueur peut faire. Ce temoin part de la requete
     * HTTP, passe par le **contact** — jamais l identifiant de la patrouille visee, qui suivrait sa
     * cible hors couverture — et exige que la mission parte du point de la patrouille.
     *
     * Il etablit d abord la premisse — le devis repond « possible » — sans quoi un 200 sur la
     * confirmation pourrait venir de n importe ou.
     */
    public function testLaCarteChiffrePuisConfirmeUneFrappeDepuisUnePatrouille(): void
    {
        [$patrouille, $pose] = $this->unePatrouillePosee();

        $cible = $this->uneCible();
        $contact = $this->unContactSur($cible, (int)$pose->time_arrival);
        $instant = (int)$pose->time_arrival + 3600;

        Date::setTestNow(Date::createFromTimestamp($instant));

        $devis = $this->post('/ajax/galaxy/patrol/quote', [
            'kind' => 'attack',
            'patrol_id' => (int)$patrouille->id,
            'contact_id' => $contact,
            'speed' => 10,
            '_token' => csrf_token(),
        ]);

        $devis->assertStatus(200);
        $devis->assertJsonPath('quote.refusal', null);

        $cout = (int)$devis->json('quote.fuel_cost');
        $version = (int)$devis->json('quote.order_version');

        $this->assertGreaterThan(0, $cout, 'Le devis annonce une frappe gratuite.');

        $ordre = $this->post('/ajax/galaxy/patrol/attack', [
            'patrol_id' => (int)$patrouille->id,
            'contact_id' => $contact,
            'speed' => 10,
            'order_version' => $version,
            'quoted_fuel_cost' => $cout,
            '_token' => csrf_token(),
        ]);

        $ordre->assertStatus(200);
        $ordre->assertJsonPath('success', true);

        $mission = FleetMission::query()->findOrFail((int)$ordre->json('mission_id'));

        $this->assertSame((int)$patrouille->id, (int)$mission->patrol_id, 'La mission ne nomme pas la patrouille qui l a lancee.');
        $this->assertSame(PlanetType::SpatialPoint->value, (int)$mission->type_from, 'La frappe part d un corps au lieu du point.');
        $this->assertSame((int)$cible->id, (int)$mission->target_patrol_id);

        $this->assertSame(PatrolState::Attacking, $patrouille->refresh()->state);
    }

    /**
     * Un contact du joueur de l essai sur cette patrouille, ecrit directement.
     *
     * Ce qui est juge ici est le parcours de l attaque, pas la veille qui ouvre les contacts : elle
     * a ses propres temoins.
     */
    private function unContactSur(Patrol $cible, int $visibleDepuis): int
    {
        $corps = Planet::factory()->create([
            'user_id' => $this->currentUserId,
            'galaxy' => 9,
            'system' => 498,
            'planet' => count($this->corpsPoses) + 1,
            'surveillance_network' => 2,
        ]);

        $this->corpsPoses[] = (int)$corps->id;

        $contact = SurveillanceContact::query()->create([
            'observer_planet_id' => (int)$corps->id,
            'observer_user_id' => $this->currentUserId,
            'patrol_id' => (int)$cible->id,
            'entered_system_at' => $visibleDepuis,
            'acquisition_from' => $visibleDepuis,
            'visible_from' => $visibleDepuis,
            'revoked_at' => null,
            'tier' => 1,
        ]);

        return (int)$contact->id;
    }

    // -------------------------------------------- les protections, sur la nouvelle origine

    /**
     * Pose le score d un joueur et le rend actif, en retenant ce qu il avait.
     */
    private function poserLeScore(int $userId, int $score): void
    {
        if (!array_key_exists($userId, $this->joueursTouches)) {
            $ligne = User::query()->whereKey($userId)->first();
            $this->joueursTouches[$userId] = [
                'score' => Highscore::query()->where('player_id', $userId)->value('general'),
                'time' => (int)($ligne->time ?? 0),
            ];
        }

        Highscore::query()->updateOrCreate(
            ['player_id' => $userId],
            ['general' => $score, 'economy' => $score, 'research' => 0, 'military' => 0]
        );

        User::query()->whereKey($userId)->update(['time' => (int)Date::now()->timestamp]);
    }

    /**
     * **La protection des debutants couvre la frappe depuis une patrouille.**
     *
     * Lire que la porte est appelee avant les deux origines etablit la **forme** de la regle, pas son
     * effet : un temoin qui reconnait un motif ne prouve pas qu il agit. Celui-ci part de l adresse
     * que la carte appelle, avec `patrol_id`, et exige le refus **et** l absence de mission.
     *
     * Sans cette couverture, il aurait suffi de frapper depuis une patrouille pour contourner la
     * protection — exactement le trou que la protection d alliance avait sur ce chemin.
     */
    public function testLaProtectionDesDebutantsCouvreLaFrappeDepuisUnePatrouille(): void
    {
        [$patrouille, $pose] = $this->unePatrouillePosee();

        $cible = $this->uneCible();
        $contact = $this->unContactSur($cible, (int)$pose->time_arrival);
        $instant = (int)$pose->time_arrival + 3600;

        Date::setTestNow(Date::createFromTimestamp($instant));

        resolve(SettingsService::class)->set('newbie_protection_enabled', 1);
        $this->poserLeScore($this->currentUserId, 500000);
        $this->poserLeScore((int)$cible->user_id, 200);

        $volsAvant = FleetMission::query()->where('patrol_id', $patrouille->id)->count();

        $reponse = $this->post('/ajax/galaxy/patrol/attack', [
            'patrol_id' => (int)$patrouille->id,
            'contact_id' => $contact,
            'speed' => 10,
            'order_version' => (int)$patrouille->order_version,
            '_token' => csrf_token(),
        ]);

        $reponse->assertStatus(409);
        $reponse->assertJsonPath('reason_key', 'target_strength_protected');

        $this->assertSame(
            $volsAvant,
            FleetMission::query()->where('patrol_id', $patrouille->id)->count(),
            'Une mission est partie malgre le refus : la protection est affichee mais pas appliquee.'
        );

        $this->assertSame(PatrolState::Stationed, $patrouille->refresh()->state, 'La patrouille a quitte son point pour une frappe refusee.');
    }

    /**
     * **Le cas comparable, et il compte autant que le refus.**
     *
     * La meme requete, protection desarmee, doit aboutir. Sans lui, une porte qui refuserait tout —
     * ou un montage casse — passerait le temoin precedent sans rien prouver.
     */
    public function testDesarmeeLaProtectionNEmpechePasLaMemeFrappe(): void
    {
        [$patrouille, $pose] = $this->unePatrouillePosee();

        $cible = $this->uneCible();
        $contact = $this->unContactSur($cible, (int)$pose->time_arrival);
        $instant = (int)$pose->time_arrival + 3600;

        Date::setTestNow(Date::createFromTimestamp($instant));

        // Le meme ecart de puissance, exactement ; seul l interrupteur change.
        resolve(SettingsService::class)->set('newbie_protection_enabled', 0);
        $this->poserLeScore($this->currentUserId, 500000);
        $this->poserLeScore((int)$cible->user_id, 200);

        $reponse = $this->post('/ajax/galaxy/patrol/attack', [
            'patrol_id' => (int)$patrouille->id,
            'contact_id' => $contact,
            'speed' => 10,
            'order_version' => (int)$patrouille->order_version,
            '_token' => csrf_token(),
        ]);

        $reponse->assertStatus(200);
        $reponse->assertJsonPath('success', true);
        $this->assertSame(PatrolState::Attacking, $patrouille->refresh()->state);
    }

    /**
     * **Un compte inactif n est plus protege** (decision de Keven) : le recolter est une mecanique
     * du jeu, et cette protection n a jamais eu pour role de l empecher.
     *
     * La premisse est etablie par le temoin du refus ci-dessus : le meme couple, cible active, est
     * bien refuse. Ici seule l activite change.
     */
    public function testUneCibleInactiveNEstPlusProtegeeContreUneFrappeDePatrouille(): void
    {
        [$patrouille, $pose] = $this->unePatrouillePosee();

        $cible = $this->uneCible();
        $contact = $this->unContactSur($cible, (int)$pose->time_arrival);
        $instant = (int)$pose->time_arrival + 3600;

        Date::setTestNow(Date::createFromTimestamp($instant));

        resolve(SettingsService::class)->set('newbie_protection_enabled', 1);
        $this->poserLeScore($this->currentUserId, 500000);
        $this->poserLeScore((int)$cible->user_id, 200);

        // Le meme joueur, devenu inactif.
        User::query()->whereKey((int)$cible->user_id)->update(['time' => (int)Date::now()->subDays(30)->timestamp]);

        $reponse = $this->post('/ajax/galaxy/patrol/attack', [
            'patrol_id' => (int)$patrouille->id,
            'contact_id' => $contact,
            'speed' => 10,
            'order_version' => (int)$patrouille->order_version,
            '_token' => csrf_token(),
        ]);

        $reponse->assertStatus(200);
        $reponse->assertJsonPath('success', true);
    }

    /**
     * **Un devis est refuse exactement quand son ordre le serait.**
     *
     * L ordre paie le stationnement du **avant** de chiffrer. Un devis qui partait de la reserve
     * brute etait donc plus genereux que lui : « possible », puis refus a la confirmation, a une
     * seconde d intervalle. Le joueur lisait deux verdicts contraires sans rien avoir fait.
     *
     * Le montage rend le faux **observable** : la reserve couvre l aller-retour, et rien de plus.
     * Sans le stationnement du, les deux chemins coincideraient et l essai ne prouverait rien.
     */
    public function testUnDevisDeFrappeEstRefuseExactementQuandSonOrdreLeSerait(): void
    {
        [$patrouille, $pose] = $this->unePatrouillePosee();

        $cible = $this->uneCible();
        $instant = (int)$pose->time_arrival + 3600;

        Date::setTestNow(Date::createFromTimestamp($instant));

        $gelee = FrozenPatrolTarget::of($cible);
        $allerRetour = $this->ordres()->quoteForAttack($patrouille, $gelee, 10, $instant)->fuelCost;
        $du = resolve(PatrolUpkeep::class)->dueBetween(
            $this->flotte(),
            (int)$patrouille->upkeep_paid_at,
            $instant
        );

        $this->assertGreaterThan(0.0, $du, 'La premisse manque : rien n est du, les deux chemins coincident.');

        /*
         * Exactement de quoi faire l aller-retour, plus le retour de securite — mais **pas** le
         * stationnement deja consomme. C est la fenetre exacte ou les deux verdicts divergeaient.
         */
        $retourDeSecurite = $this->ordres()->safetyReturnCostOf($patrouille, $this->flotte());
        $patrouille->forceFill(['fuel_reserve' => (float)$allerRetour + (float)$retourDeSecurite + ($du / 2)])->save();

        $devis = $this->ordres()->quoteForAttack($patrouille->refresh(), $gelee, 10, $instant);

        if ($devis->isPossible()) {
            // Le devis dit oui : l ordre doit aboutir. Un refus ici serait la divergence meme.
            $this->ordres()->attackFrom($patrouille, $gelee, 10, (int)$patrouille->order_version, $instant);
            $this->assertSame(PatrolState::Attacking, $patrouille->refresh()->state);

            return;
        }

        // Le devis dit non : l ordre doit refuser pour la meme raison, pas partir quand meme.
        $this->expectException(PatrolOrderRefused::class);

        $this->ordres()->attackFrom($patrouille, $gelee, 10, (int)$patrouille->order_version, $instant);
    }

    // --------------------------------------- ce que le joueur LIT quand c est refuse

    /**
     * **Un refus dit pourquoi, en francais, et jamais une clef.**
     *
     * `__()` rend la clef elle-meme quand la traduction manque — une chaine lisible, sans la moindre
     * erreur. Comparer la clef d un service ne prouve donc rien de ce que le joueur lit : ce temoin
     * poste la requete et refuse **toute** chaine `t_ingame.` dans la reponse entiere.
     *
     * Le cas choisi est celui que Keven a nomme : l ecart de puissance. Un refus qui dirait
     * seulement « impossible » laisserait le joueur chercher ce qu il a mal fait.
     */
    public function testUnRefusPourEcartDePuissanceSeLitEnFrancais(): void
    {
        [$patrouille, $pose] = $this->unePatrouillePosee();

        $cible = $this->uneCible();
        $contact = $this->unContactSur($cible, (int)$pose->time_arrival);
        $instant = (int)$pose->time_arrival + 3600;

        Date::setTestNow(Date::createFromTimestamp($instant));

        resolve(SettingsService::class)->set('newbie_protection_enabled', 1);
        $this->poserLeScore($this->currentUserId, 500000);
        $this->poserLeScore((int)$cible->user_id, 200);

        $reponse = $this->post('/ajax/galaxy/patrol/attack', [
            'patrol_id' => (int)$patrouille->id,
            'contact_id' => $contact,
            'speed' => 10,
            'order_version' => (int)$patrouille->order_version,
            '_token' => csrf_token(),
        ]);

        $reponse->assertStatus(409);

        $corps = $reponse->getContent();

        $this->assertIsString($corps);
        $this->assertStringNotContainsString(
            't_ingame.',
            $corps,
            'Le joueur recoit une clef de traduction a la place d une phrase : ' . $corps
        );

        $phrase = (string)$reponse->json('reason');

        /*
         * **La clef ne se fait pas passer pour une phrase.** `__()` rend la clef elle-meme quand la
         * traduction manque : c est exactement ce qu il faut voir, et une comparaison a la clef nue
         * le voit.
         */
        $this->assertNotSame('target_strength_protected', $phrase, 'Le joueur lit la clef brute.');
        $this->assertGreaterThan(30, strlen($phrase), 'Le refus tient en trois mots : il ne peut rien expliquer. Lu : ' . $phrase);

        /*
         * **Et la phrase francaise nomme la cause.** Le banc tourne sous la locale par defaut, donc
         * la reponse est en anglais ; le serveur de Keven est en francais. La langue du joueur se
         * lit la ou elle vit — dans le fichier —, pas devinee d une reponse HTTP.
         */
        $langues = [
            'fr' => ['puissance', 'offensive'],
            'en' => ['power', 'offensive'],
        ];

        foreach ($langues as $langue => $mots) {
            $table = require base_path('resources/lang/' . $langue . '/t_ingame.php');
            $dite = (string)($table['patrol']['refusal_target_strength_protected'] ?? '');

            foreach ($mots as $mot) {
                $this->assertStringContainsString(
                    $mot,
                    mb_strtolower($dite),
                    'Le refus ne nomme pas ce qui l a cause en ' . $langue . ' : ' . $dite
                );
            }
        }

        // **La meme phrase voyage dans `errors[0].message`**, la forme que le jeu affiche deja pour
        // tous ses envois de flotte : un seul des deux canaux rempli laisserait la moitie des
        // affichages muette.
        $this->assertSame($phrase, (string)$reponse->json('errors.0.message'));
    }

    /**
     * **Un devis refuse se dit refuse, et dit pourquoi.**
     *
     * Ce devis-la ne passait pas par le composeur unique et annoncait `possible: true` **en dur** :
     * une frappe impossible etait presentee comme faisable, et le joueur ne decouvrait le refus qu a
     * la confirmation — apres avoir cru pouvoir.
     */
    public function testUnDevisDeFrappeRefuseSeDitRefuseEtDitPourquoi(): void
    {
        [$patrouille, $pose] = $this->unePatrouillePosee();

        $cible = $this->uneCible();
        $contact = $this->unContactSur($cible, (int)$pose->time_arrival);
        $instant = (int)$pose->time_arrival + 3600;

        Date::setTestNow(Date::createFromTimestamp($instant));

        // La premisse : avec une reserve pleine, ce devis est possible.
        $possible = $this->post('/ajax/galaxy/patrol/quote', [
            'kind' => 'attack',
            'patrol_id' => (int)$patrouille->id,
            'contact_id' => $contact,
            'speed' => 10,
            '_token' => csrf_token(),
        ]);

        $possible->assertStatus(200);
        $possible->assertJsonPath('quote.possible', true);

        // Plus de quoi faire l aller-retour : le meme devis doit se dire refuse.
        $patrouille->forceFill(['fuel_reserve' => 1.0])->save();

        $refuse = $this->post('/ajax/galaxy/patrol/quote', [
            'kind' => 'attack',
            'patrol_id' => (int)$patrouille->id,
            'contact_id' => $contact,
            'speed' => 10,
            '_token' => csrf_token(),
        ]);

        $refuse->assertStatus(200);
        $refuse->assertJsonPath('quote.possible', false);

        $raison = (string)$refuse->json('quote.refusal_reason');

        $this->assertNotSame('', $raison, 'Le devis refuse ne porte aucune phrase : la carte n aurait qu une clef a afficher.');
        $this->assertStringNotContainsString('t_ingame.', $raison, 'La phrase du refus est une clef non traduite : ' . $raison);

        $corps = $refuse->getContent();
        $this->assertIsString($corps);
        $this->assertStringNotContainsString('t_ingame.', $corps);
    }

    // ------------------------------------------- ce qu un raid ne doit PAS faire

    /**
     * **Un raid vers un autre systeme ne remet pas l horloge de surveillance a zero.**
     *
     * La patrouille n a pas bouge : son point, sa galaxie et son systeme sont exactement ceux
     * d avant. Ce sont ses **vaisseaux** qui sont alles ailleurs et revenus.
     *
     * `park()` decide « a-t-elle change de systeme ? » en comparant les deux bouts du segment —
     * juste pour un deplacement, faux pour un retour de raid, dont le segment part de la cible.
     * Sans cette distinction, un joueur dont la patrouille allait etre acquise n avait qu a frapper
     * le systeme voisin pour effacer tous les contacts poses sur elle et relancer le compteur.
     *
     * Le temoin exige les deux moities : le contact survit, et l instant d entree ne bouge pas.
     */
    public function testUnRaidVersUnAutreSystemeNeRelancePasLHorlogeDeSurveillance(): void
    {
        [$patrouille, $pose] = $this->unePatrouillePosee();

        $entreeAvant = (int)$patrouille->entered_system_at;
        $observateur = $this->unContactPoseSurMaPatrouille($patrouille, $entreeAvant);

        $this->assertGreaterThan(0, $entreeAvant, 'La premisse manque : la patrouille n a pas d instant d entree.');

        // Une cible dans le **systeme voisin** : c est le franchissement qui declenche le defaut.
        $coords = $this->planetService->getPlanetCoordinates();
        $cible = $this->uneCible();
        $cible->forceFill(['system' => $coords->system + 1])->save();

        $instant = (int)$pose->time_arrival + 3600;
        Date::setTestNow(Date::createFromTimestamp($instant));

        $attaque = $this->ordres()->attackFrom(
            $patrouille,
            FrozenPatrolTarget::of($cible->refresh()),
            10,
            (int)$patrouille->order_version,
            $instant
        );

        $this->assertNotSame(
            (int)$attaque->system_from,
            (int)$attaque->system_to,
            'La premisse manque : ce raid ne franchit aucun systeme, le defaut ne peut pas se produire.'
        );

        $cible->delete();

        Date::setTestNow(Date::createFromTimestamp((int)$attaque->time_arrival + 1));
        $this->joueur()->updateFleetMissions();

        $retour = FleetMission::query()->where('parent_id', $attaque->id)->firstOrFail();

        Date::setTestNow(Date::createFromTimestamp((int)$retour->time_arrival + 1));
        $this->joueur()->updateFleetMissions();

        $patrouille->refresh();

        $this->assertSame(
            $entreeAvant,
            (int)$patrouille->entered_system_at,
            'Le raid a relance l horloge d acquisition : il suffirait de frapper le systeme voisin pour echapper a toute detection.'
        );

        $this->assertNull(
            SurveillanceContact::query()->whereKey($observateur)->value('revoked_at'),
            'Le raid a revoque le contact pose sur la patrouille : le detecteur perd ce qu il avait acquis sans rien avoir perdu.'
        );
    }

    /**
     * Un contact qu un autre joueur tient sur MA patrouille — ce que le raid ne doit pas effacer.
     */
    private function unContactPoseSurMaPatrouille(Patrol $mienne, int $depuis): int
    {
        $etrangere = $this->getNearbyForeignPlanet();
        $proprietaire = $etrangere->getPlayer();

        $this->assertNotNull($proprietaire);

        $contact = SurveillanceContact::query()->create([
            'observer_planet_id' => $etrangere->getPlanetId(),
            'observer_user_id' => $proprietaire->getId(),
            'patrol_id' => (int)$mienne->id,
            'entered_system_at' => $depuis,
            'acquisition_from' => $depuis,
            'visible_from' => $depuis,
            'revoked_at' => null,
            'tier' => 1,
        ]);

        return (int)$contact->id;
    }

    // ------------------------------------------------- le planificateur, seul

    /**
     * **Le planificateur vise le point d une patrouille qui tient le sien.**
     *
     * C est lui qui decide aussi pour une flotte refusee par un combat ou rendue par une annulation
     * d exploitation : un second decideur aurait derive.
     */
    public function testLePlanificateurViseLePointDUnePatrouilleQuiTientLeSien(): void
    {
        [$patrouille, $pose] = $this->unePatrouillePosee();

        $cible = $this->uneCible();
        $instant = (int)$pose->time_arrival + 3600;

        Date::setTestNow(Date::createFromTimestamp($instant));

        $attaque = $this->ordres()->attackFrom(
            $patrouille,
            FrozenPatrolTarget::of($cible),
            10,
            (int)$patrouille->order_version,
            $instant
        );

        $plan = resolve(ReturnPlanner::class)->planFor($attaque);

        $this->assertSame(ReturnDestinationKind::PatrolPoint, $plan->kind, 'Le plan ne vise pas le point de la patrouille.');
        $this->assertSame((int)$patrouille->id, $plan->patrolId);
        $this->assertNull($plan->planetId, 'Le plan designe un corps alors qu il vise un point.');
        $this->assertSame([(int)$patrouille->id], resolve(ReturnPlanner::class)->patrolsThatDecideFor($attaque));
    }

    /**
     * **Le cas comparable : plus de point, plus de plan vers un point.**
     *
     * Sans lui, `landsOnAPoint()` pourrait rendre vrai partout et le temoin precedent resterait
     * vert. Une patrouille en vol n a pas de position posee : la flotte reprend l ordre des recours
     * ordinaires et retombe sur un corps.
     */
    public function testSansPointLePlanificateurRetombeSurUnCorps(): void
    {
        [$patrouille, $pose] = $this->unePatrouillePosee();

        $cible = $this->uneCible();
        $instant = (int)$pose->time_arrival + 3600;

        Date::setTestNow(Date::createFromTimestamp($instant));

        $attaque = $this->ordres()->attackFrom(
            $patrouille,
            FrozenPatrolTarget::of($cible),
            10,
            (int)$patrouille->order_version,
            $instant
        );

        // La patrouille perd son point sans disparaitre : le cas exact que la colonne decrit.
        Patrol::query()->whereKey($patrouille->id)->update(['x' => null, 'y' => null]);

        $plan = resolve(ReturnPlanner::class)->planFor($attaque->refresh());

        $this->assertNotSame(ReturnDestinationKind::PatrolPoint, $plan->kind, 'Le plan vise encore un point que la patrouille ne tient plus.');
        $this->assertTrue($plan->isPossible(), 'La flotte n a plus nulle part ou aller alors que le joueur a des planetes.');
        $this->assertNotNull($plan->planetId, 'Le recours ne designe aucun corps.');
    }
}
