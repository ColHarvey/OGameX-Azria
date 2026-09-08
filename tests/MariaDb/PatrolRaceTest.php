<?php

namespace Tests\MariaDb;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\FleetMission;
use OGame\Models\Patrol;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Patrol\Enums\PatrolState;
use OGame\Patrol\Exceptions\PatrolOrderRefused;
use OGame\Patrol\Geometry\SpatialPoint;
use OGame\Patrol\PatrolDestination;
use OGame\Patrol\PatrolOrders;
use OGame\Patrol\PatrolPricing;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use PHPUnit\Framework\Attributes\Group;
use Tests\AccountTestCase;

/**
 * Les deux courses des patrouilles : le creneau de flotte, et le corps qui change de mains.
 *
 * ## Ce que le bac prouve, et que SQLite ne peut pas
 *
 * Les deux protections tiennent par un verrou de ligne dans une transaction, et sous SQLite
 * `lockForUpdate()` ne compile a rien : un second appel sequentiel prouve l idempotence, jamais la
 * course. Les deux mutations correspondantes survivent d ailleurs a toute la suite ordinaire — c est
 * ce que ce fichier ferme.
 *
 * - **Le creneau** : deux lancements simultanes lisaient tous deux la derniere place libre avant la
 *   transaction, puis partaient tous les deux. Le compte est refait sous le verrou de la ligne du
 *   compte, qui serialise les departs d un meme joueur.
 * - **Le corps qui change de mains** : `homecomingBase()` dit oui, puis la planete change de
 *   proprietaire avant que `land()` ne prenne son verrou. La relecture `for update` decide, et le
 *   credit n a pas lieu. **Cette course emprunte le chemin de l administration**, seul appelant qui
 *   atteigne cette fenetre : le travailleur des pages tient deja la ligne du corps de bout en bout,
 *   et une course montee sur lui ne discrimine rien.
 */
#[Group('mariadb')]
final class PatrolRaceTest extends AccountTestCase
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
        Date::setTestNow();

        parent::tearDown();
    }

    /**
     * Deux lancements simultanes ne prennent pas deux fois la derniere place.
     */
    public function testTwoSimultaneousLaunchesDoNotShareTheLastFleetSlot(): void
    {
        $this->planetAddResources(new Resources(0, 0, 400000, 0));
        $this->planetAddUnit('cruiser', 40);

        $joueur = resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);
        $restantes = $joueur->getFleetSlotsMax() - $joueur->getFleetSlotsInUse() - 1;
        $this->assertGreaterThanOrEqual(0, $restantes, 'The bench player already has no slot: the scenario would prove nothing.');

        for ($i = 0; $i < $restantes; $i++) {
            $this->aDummyMissionFor($this->currentUserId, (int)$this->planetService->getPlanetId());
        }

        $joueur = resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);
        $this->assertSame(
            1,
            $joueur->getFleetSlotsMax() - $joueur->getFleetSlotsInUse(),
            'The bench does not hold exactly one free slot: the race would prove nothing.'
        );

        $planete = (int)$this->planetService->getPlanetId();
        $utilisateur = (int)$this->currentUserId;
        $avant = (int)DB::table('patrols')->where('user_id', $utilisateur)->count();

        $issues = $this->inParallel(2, static fn (int $rang): string => self::launchOnePatrol($utilisateur, $planete));

        $reussites = count(array_filter($issues, static fn (string $issue): bool => str_starts_with($issue, 'ok:')));
        $refus = array_values(array_filter($issues, static fn (string $issue): bool => str_starts_with($issue, 'refus:')));

        $this->assertSame(1, $reussites, 'Both simultaneous launches took the last fleet slot: ' . implode(' | ', $issues));
        $this->assertCount(1, $refus, 'The losing launch did not report a refusal: ' . implode(' | ', $issues));
        $this->assertSame('refus:no_fleet_slot', $refus[0], 'The losing launch was refused for another reason.');
        $this->assertSame($avant + 1, (int)DB::table('patrols')->where('user_id', $utilisateur)->count(), 'More than one patrol was created for the last slot.');
    }

    /**
     * Un corps qui change de mains pendant que l atterrissage attend son verrou ne recoit rien.
     */
    public function testABodyThatChangesHandsWhileTheLandingWaitsReceivesNothing(): void
    {
        $this->planetAddResources(new Resources(0, 0, 60000, 0));
        $this->planetAddUnit('cruiser', 20);

        $base = (int)$this->planetService->getPlanetId();
        $patrouille = $this->aPatrolReturningTo($base);
        $retour = FleetMission::query()->findOrFail($patrouille->current_mission_id);

        $etrangere = $this->getNearbyForeignPlanet();
        $proprietaire = $etrangere->getPlayer();
        $this->assertNotNull($proprietaire);
        $this->assertNotSame($this->currentUserId, $proprietaire->getId());

        // **Le corps de repli est cree ici, pas espere.** Apres le transfert, la base n appartient
        // plus au joueur ; sans un autre corps a lui, le repli n aurait nulle part ou aller et
        // l epreuve confondrait « repli impossible » avec « repli absent » — deux issues qui se
        // ressemblent, ce que cette course a deja paye trois fois.
        $refuge = $this->createPlanetAtSafeCoordinate($this->currentUserId);
        $this->assertNotSame($base, $refuge->getPlanetId(), 'The refuge is the very base that changes hands.');

        // **Le repli doit etre observable.** Avec une reserve qui paie largement le trajet vers le
        // corps de repli, le repli cree un segment NEUF : sans cela il immobiliserait, et une
        // patrouille immobilisee garde son segment — la meme observation qu une arrivee qui aurait
        // ignore le refus. Mesure faite : la mutation survivait a ce temoin tant que les deux issues
        // se ressemblaient.
        DB::table('patrols')->where('id', $patrouille->id)->update(['fuel_reserve' => 200000]);

        $avant = DB::table('planets')->where('id', $base)->first();
        $this->assertNotNull($avant);

        // Le parent tient la ligne du corps sur une connexion a part : sa mise a jour n est pas
        // encore visible, et l enfant la lira donc « a lui » avant de buter sur le verrou.
        config(['database.connections.mysql_temoin' => config('database.connections.mysql')]);
        $temoin = DB::connection('mysql_temoin');
        $temoin->beginTransaction();
        $temoin->table('planets')->where('id', $base)->update(['user_id' => $proprietaire->getId()]);

        $utilisateur = (int)$this->currentUserId;
        $arrivee = (int)$retour->time_arrival;

        // **Celui qui prononce l arrivee est un tiers, et ce n est pas un detail de montage.** La
        // route d administration traverse `auth`, `globalgame`, `locale` et `admin` : si le compte
        // connecte etait le proprietaire de la patrouille, `globalgame` reglerait la mission par
        // `updateFleetMissions()` — donc **sous l enveloppe** qui verrouille tous ses corps — avant
        // meme que le controleur ne soit atteint, et la fenetre visee se refermerait sans qu on le
        // voie. Le compte systeme porte le role par migration et possede sa propre planete : il
        // traverse les quatre couches sans toucher a un seul corps de la patrouille.
        $administrateur = User::query()->where('username', User::SYSTEM_ACCOUNT_USERNAME)->firstOrFail();
        $this->assertNotSame($utilisateur, (int)$administrateur->id, 'The administrator is the patrol owner: the page envelope would settle the arrival before the controller.');
        $this->assertNotSame((int)$proprietaire->getId(), (int)$administrateur->id, 'The administrator is the new owner of the base: his own page worker could settle the arrival.');
        $this->assertTrue($administrateur->hasRole('admin'), 'The system account does not carry the admin role: the request would be refused for the wrong reason.');

        $issues = $this->inParallel(
            1,
            function (int $rang) use ($administrateur, $retour, $arrivee): string {
                Date::setTestNow(Date::createFromTimestamp($arrivee + 1));

                $reponse = $this->actingAs($administrateur)->post(
                    route('admin.server-administration.stuck-missions.process'),
                    ['mission_id' => (int)$retour->id]
                );

                // Le message de session dit ce que le controleur a conclu : le parent le remonte
                // dans ses assertions, pour qu un echec nomme la cause au lieu de la faire deviner.
                return 'http:' . $reponse->getStatusCode() . ' '
                    . (string)(session('status') ?? session('error') ?? 'sans message');
            },
            function () use ($temoin): void {
                // **L attente vise un processus bloque sur la ligne du corps, et rien d autre.**
                // L enfant a deja lu la base « a lui » sans verrou dans `homecomingBase()` ; il bute
                // maintenant sur la relecture `for update` de `land()`, que le parent tient. Rendre
                // la main ici place le changement de mains **entre les deux lectures** : c est
                // exactement la course visee, et le seul instant ou elle existe.
                $attendue = $this->waitUntilAProcessWaitsOnALockOn('planets');
                $temoin->commit();

                // **L epreuve refuse de conclure d un autre verrou que le sien.** Le travailleur des
                // pages ouvre sa transaction en verrouillant tous les corps du joueur d un coup
                // (`where id in (...)`) : attendre celui-la relachait le parent avant meme le
                // decideur, l enfant lisait le corps deja passe en d autres mains, et les deux
                // versions prenaient le meme repli. Mesure faite trois fois. L attente rend le
                // processus et son instruction : celle-ci doit etre la relecture **d un seul**
                // corps, celle de `land()`.
                $this->assertStringContainsString('planets', $attendue, 'The wait did not see a planets lock at all: ' . $attendue);
                $this->assertStringNotContainsString(' in (', $attendue, 'The child blocked on a page envelope, not on the landing re-read: ' . $attendue);
            }
        );

        $apres = DB::table('planets')->where('id', $base)->first();
        $this->assertNotNull($apres);
        $this->assertSame((int)$avant->cruiser, (int)$apres->cruiser, 'The fleet was credited to the new owner of the base: ' . implode(' | ', $issues));
        $this->assertEqualsWithDelta((float)$avant->deuterium, (float)$apres->deuterium, 0.001, 'The reserve was credited to the new owner of the base.');

        $patrouille->refresh();
        $this->assertNotSame(PatrolState::Finished, $patrouille->state, 'The patrol landed on a body that had just changed hands.');

        // Le repli a bien eu lieu : le segment qui ne pouvait pas se poser est regle, un segment
        // neuf le remplace, et il vise une planete du proprietaire.
        $retourApres = FleetMission::query()->findOrFail($retour->id);
        $this->assertSame(1, (int)$retourApres->processed, 'The leg that could not land is still live: the arrival ignored the refusal and will retry for ever.');
        $this->assertNotSame((int)$retour->id, (int)$patrouille->current_mission_id, 'The patrol kept the leg that could not land.');

        $vivants = FleetMission::query()->where('patrol_id', $patrouille->id)->where('processed', 0)->count();
        $this->assertSame(1, $vivants, 'The patrol holds ' . $vivants . ' live legs: the fallback happened more than once, or not at all.');

        $neuf = FleetMission::query()->findOrFail($patrouille->current_mission_id);
        $this->assertSame(
            $utilisateur,
            (int)DB::table('planets')->where('id', $neuf->planet_id_to)->value('user_id'),
            'The new leg does not aim at a planet of the patrol owner.'
        );

        // **Et ce n est pas le corps qui vient de partir.** La verification precedente passait deja
        // quand le repli visait la base transferee — son proprietaire etant lu apres coup, la
        // comparaison portait sur le mauvais joueur et non sur le mauvais corps. Le defaut trouve
        // ici tenait a une liste de planetes chargee avant le changement de mains.
        $this->assertNotSame($base, (int)$neuf->planet_id_to, 'The fallback aims at the base that just changed hands.');
    }

    /**
     * Une suppression qui demarre pendant un lancement l arrete : c est la meme ligne, donc le meme verrou.
     *
     * ## Ce que cette course prouve, et que l essai sequentiel ne prouve pas
     *
     * Un essai qui pose le drapeau **avant** de lancer montre seulement que le lancement le lit. Il
     * ne dit pas que les deux chemins se serialisent : une suppression qui demarre pendant le
     * lancement pourrait passer entre le controle et l ecriture. Ici le parent tient la ligne du
     * compte avec son drapeau non encore valide ; le lancement bute sur ce verrou — c est la preuve
     * qu il prend la meme ligne que la suppression — puis lit le drapeau et refuse.
     */
    public function testADeletionStartingDuringALaunchStopsIt(): void
    {
        $this->planetAddResources(new Resources(0, 0, 200000, 0));
        $this->planetAddUnit('cruiser', 20);

        $utilisateur = (int)$this->currentUserId;
        $planete = (int)$this->planetService->getPlanetId();
        $avantVaisseaux = (int)DB::table('planets')->where('id', $planete)->value('cruiser');

        config(['database.connections.mysql_temoin' => config('database.connections.mysql')]);
        $temoin = DB::connection('mysql_temoin');
        $temoin->beginTransaction();
        $temoin->table('users')->where('id', $utilisateur)->update(['deletion_pending_since' => (int)Date::now()->timestamp]);

        $issues = $this->inParallel(
            1,
            static fn (int $rang): string => self::launchOnePatrol($utilisateur, $planete),
            function () use ($temoin): void {
                // L enfant attend la ligne du compte : c est la meme que celle de la suppression.
                $this->waitUntilAProcessWaitsOnALock();
                $temoin->commit();
            }
        );

        $this->assertSame('refus:account_being_deleted', $issues[0], 'The launch went through while the deletion was starting on the same row.');
        $this->assertSame($avantVaisseaux, (int)DB::table('planets')->where('id', $planete)->value('cruiser'), 'The refused launch took the ships anyway.');
        $this->assertSame(0, (int)DB::table('patrols')->where('user_id', $utilisateur)->count(), 'The refused launch left a patrol behind.');
    }

    /**
     * Une mission quelconque, pour occuper un creneau sans rien faire d autre.
     */
    private function aDummyMissionFor(int $userId, int $planetId): void
    {
        $maintenant = (int)Date::now()->timestamp;

        DB::table('fleet_missions')->insert([
            'user_id' => $userId,
            'planet_id_from' => $planetId,
            'mission_type' => 3,
            'type_from' => 1,
            'type_to' => 1,
            'galaxy_from' => 1,
            'system_from' => 1,
            'position_from' => 4,
            'planet_id_to' => $planetId,
            'galaxy_to' => 1,
            'system_to' => 1,
            'position_to' => 5,
            'time_departure' => $maintenant,
            'time_arrival' => $maintenant + 86400,
            'small_cargo' => 1,
            'processed' => 0,
            'canceled' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Une patrouille en retour vers ce corps, dont le segment arrive maintenant.
     */
    private function aPatrolReturningTo(int $base): Patrol
    {
        $orders = resolve(PatrolOrders::class);
        $coords = $this->planetService->getPlanetCoordinates();
        $geometrie = resolve(PatrolPricing::class)->geometry();

        $units = new UnitCollection();
        $units->addUnit(ObjectService::getUnitObjectByMachineName('cruiser'), 20);

        $patrouille = $orders->launch(
            $this->planetService,
            $units,
            new Resources(0, 0, 0, 0),
            10000,
            PatrolDestination::spatialPoint($geometrie, $coords->galaxy, $coords->system, new SpatialPoint(600, 600)),
            10,
            (int)Date::now()->timestamp
        );

        $aller = FleetMission::query()->findOrFail($patrouille->current_mission_id);
        Date::setTestNow(Date::createFromTimestamp((int)$aller->time_arrival + 1));
        resolve(PlayerServiceFactory::class)->make($this->currentUserId, true)->updateFleetMissions();

        $patrouille->refresh();
        $aller->refresh();
        Date::setTestNow(Date::createFromTimestamp((int)$aller->time_arrival + (int)$aller->time_holding + 1));
        resolve(PlayerServiceFactory::class)->make($this->currentUserId, true)->updateFleetMissions();

        $patrouille->refresh();
        $this->assertSame(PatrolState::Returning, $patrouille->state, 'The patrol is not on its way home: the scenario would prove nothing.');

        return $patrouille;
    }

    /**
     * L essai d un enfant : lancer une patrouille, et dire ce qu il en advient.
     */
    private static function launchOnePatrol(int $userId, int $planetId): string
    {
        $joueur = resolve(PlayerServiceFactory::class)->make($userId, true);
        $planete = resolve(PlanetServiceFactory::class)->make($planetId, true);

        if ($planete === null) {
            return 'erreur:planete introuvable';
        }

        $geometrie = resolve(PatrolPricing::class)->geometry();
        $coords = $planete->getPlanetCoordinates();

        $units = new UnitCollection();
        $units->addUnit(ObjectService::getUnitObjectByMachineName('cruiser'), 20);

        try {
            $patrouille = resolve(PatrolOrders::class)->launch(
                $planete,
                $units,
                new Resources(0, 0, 0, 0),
                10000,
                PatrolDestination::spatialPoint($geometrie, $coords->galaxy, $coords->system, new SpatialPoint(600, 600)),
                10,
                (int)Date::now()->timestamp
            );
        } catch (PatrolOrderRefused $refus) {
            return 'refus:' . $refus->reason;
        }

        unset($joueur);

        return 'ok:' . $patrouille->id;
    }
}
