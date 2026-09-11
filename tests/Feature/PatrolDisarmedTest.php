<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\FleetMission;
use OGame\Models\Patrol;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Patrol\Enums\PatrolState;
use OGame\Patrol\Exceptions\PatrolOrderRefused;
use OGame\Patrol\FrozenPatrolTarget;
use OGame\Patrol\Geometry\SpatialPoint;
use OGame\Patrol\PatrolDestination;
use OGame\Patrol\PatrolOrders;
use OGame\Patrol\PatrolPricing;
use OGame\Patrol\SpatialAttackOrder;
use OGame\Patrol\SurveillanceProjection;
use OGame\Patrol\SurveillanceWatch;
use OGame\Services\ObjectService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;
use ReflectionMethod;
use Tests\AccountTestCase;

/**
 * Ce qu un interrupteur baisse doit faire — et surtout ce qu il ne doit **pas** faire.
 *
 * ------------------------------------------------------------------------------------
 * LA REGLE
 *
 * `patrols_enabled` est un arret d urgence. Il doit fermer les **nouvelles entrees** : plus un
 * lancement, plus une manoeuvre, plus une attaque spatiale, plus le batiment a construire. Il ne
 * doit **jamais** retenir ce qui existe deja : une flotte en l air rentre, une arrivee se pose, une
 * bataille en cours se regle, et le joueur garde le moyen d agir.
 *
 * Un arret d urgence qui masque l interface en laissant des flottes injoignables serait pire que pas
 * d arret du tout : le joueur verrait ses vaisseaux quelque part sans pouvoir les rappeler.
 *
 * ------------------------------------------------------------------------------------
 * POURQUOI CETTE CLASSE EXISTE
 *
 * Elle a ete ecrite apres un defaut reel. `whyRecallIsRefused()` deleguait a `whyMoveIsRefused()`,
 * qui rend `disabled` interrupteur baisse, et `recall()` retraversait la meme question dans
 * `dispatchOrder()`. **Deux portes** fermaient le rappel. Desarmer le chantier emprisonnait donc les
 * patrouilles du joueur : seule l usure de la reserve, des heures plus tard, aurait fini par
 * declencher le retour de securite.
 *
 * Rien ne le disait, parce que rien n avait jamais desarme l interrupteur **avec des entites
 * vivantes**. Tous les essais du chantier l arment et le laissent arme.
 */
class PatrolDisarmedTest extends AccountTestCase
{
    protected function tearDown(): void
    {
        resolve(SettingsService::class)->set('patrols_enabled', 0);

        parent::tearDown();
    }

    private function armer(): void
    {
        resolve(SettingsService::class)->set('patrols_enabled', 1);
        $this->playerSetResearchLevel('computer_technology', 10);
    }

    private function desarmer(): void
    {
        resolve(SettingsService::class)->set('patrols_enabled', 0);
    }

    private function player(): PlayerService
    {
        return resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);
    }

    private function flotte(int $croiseurs = 20): UnitCollection
    {
        $unites = new UnitCollection();
        $unites->addUnit(ObjectService::getUnitObjectByMachineName('cruiser'), $croiseurs);

        return $unites;
    }

    /**
     * Une patrouille reellement posee, montee par le **vrai** lanceur et posee par le **vrai**
     * travailleur. L interrupteur reste arme a la sortie ; a chaque essai de le baisser.
     *
     * @return array{0: Patrol, 1: FleetMission}
     */
    private function unePatrouillePosee(int $reserve = 10000, int $croiseurs = 20): array
    {
        $this->armer();
        $this->planetAddResources(new Resources(0, 0, 60000, 0));
        $this->planetAddUnit('cruiser', $croiseurs);

        $coords = $this->planetService->getPlanetCoordinates();
        $geometrie = resolve(PatrolPricing::class)->geometry();

        $patrouille = resolve(PatrolOrders::class)->launch(
            $this->planetService,
            $this->flotte($croiseurs),
            new Resources(0, 0, 0, 0),
            $reserve,
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
     * **Le rappel reste possible.** C est le defaut que cette classe a ferme.
     */
    public function testUnePatrouillePoseeSeRappelleEncoreApresDesarmement(): void
    {
        [$patrouille] = $this->unePatrouillePosee();

        $this->desarmer();

        $ordres = resolve(PatrolOrders::class);

        $this->assertNull(
            $ordres->whyRecallIsRefused($patrouille, (int)Date::now()->timestamp),
            'Un chantier desarme retient la flotte du joueur : elle ne peut plus rentrer.'
        );

        $retour = $ordres->recall($patrouille, (int)$patrouille->order_version, (int)Date::now()->timestamp);

        $this->assertNotNull($retour->id, 'Le rappel n a cree aucun segment de retour.');
        $this->assertSame(
            PatrolState::Returning->value,
            $patrouille->refresh()->state->value,
            'La patrouille rappelee doit repartir en retour.'
        );
    }

    /**
     * **Et le bouton reste arme sur la carte.** Corriger le serveur sans corriger l interface
     * laisserait le joueur bloque dans son navigateur : c est la meme methode qui decide des deux,
     * et cet essai lit ce que la carte lit.
     */
    public function testLeBoutonRappelerResteArmeSurLaCarteApresDesarmement(): void
    {
        [$patrouille] = $this->unePatrouillePosee();

        $this->desarmer();

        $coords = $this->planetService->getPlanetCoordinates();
        $reponse = $this->get(route('galaxy.fleets', ['galaxy' => $coords->galaxy, 'system' => $coords->system]));

        $reponse->assertStatus(200);

        $mienne = null;

        foreach ($reponse->json('patrols') ?? [] as $ligne) {
            if ((int)($ligne['id'] ?? 0) === (int)$patrouille->id) {
                $mienne = $ligne;
            }
        }

        $this->assertNotNull($mienne, 'La carte ne montre plus la patrouille : le joueur ne peut meme plus la voir.');

        $this->assertTrue(
            (bool)$mienne['commands']['recall']['allowed'],
            'Le bouton Rappeler est grise : le joueur voit sa flotte sans pouvoir la faire rentrer.'
        );

        // Et l autre moitie de la regle : la manoeuvre, elle, est bien fermee, avec sa raison.
        $this->assertFalse((bool)$mienne['commands']['move']['allowed'], 'Une manoeuvre reste une nouvelle entree.');
        $this->assertSame('disabled', $mienne['commands']['move']['reason_key']);
    }

    /**
     * **L exception ne porte pas sur l etat seul.** Un ordre qui se dirait « retour » vers un autre
     * point n a aucune raison d etre dispense de l interrupteur.
     *
     * L appel prive est force ici volontairement : c est exactement le chemin que la garde doit
     * fermer. Aucun appelant du jeu ne le prend aujourd hui — `orderMove()` part en `EnRoute`, et
     * `recall()` compose toujours la destination de la base —, et cet essai existe pour que personne
     * ne l ouvre demain en croyant que « `Returning` passe ».
     */
    public function testUnOrdreQuiSeDitRetourVersAilleursResteRefuse(): void
    {
        [$patrouille] = $this->unePatrouillePosee();

        $this->desarmer();

        $ordres = resolve(PatrolOrders::class);
        $geometrie = resolve(PatrolPricing::class)->geometry();
        $coords = $this->planetService->getPlanetCoordinates();

        // Un point de l espace qui n est pas la base : un « retour » vers nulle part.
        $ailleurs = PatrolDestination::spatialPoint($geometrie, $coords->galaxy, $coords->system, new SpatialPoint(900, -900));

        $dispatch = new ReflectionMethod(PatrolOrders::class, 'dispatchOrder');

        $this->expectException(PatrolOrderRefused::class);
        $this->expectExceptionMessage('disabled');

        $dispatch->invoke(
            $ordres,
            $patrouille,
            $ailleurs,
            10.0,
            (int)$patrouille->order_version,
            (int)Date::now()->timestamp,
            PatrolState::Returning,
            null
        );
    }

    /**
     * Une manoeuvre ordinaire reste refusee, et c est la moitie voulue de l arret d urgence.
     */
    public function testUneManoeuvreResteRefuseeApresDesarmement(): void
    {
        [$patrouille] = $this->unePatrouillePosee();

        $this->desarmer();

        $this->assertSame(
            'disabled',
            resolve(PatrolOrders::class)->whyMoveIsRefused($patrouille, (int)Date::now()->timestamp),
            'Un chantier desarme laisse encore poser de nouvelles manoeuvres.'
        );
    }

    /**
     * Un lancement neuf reste refuse : c est la premiere raison d etre de l interrupteur.
     */
    public function testUnLancementResteRefuseApresDesarmement(): void
    {
        $this->armer();
        $this->planetAddResources(new Resources(0, 0, 60000, 0));
        $this->planetAddUnit('cruiser', 20);

        $this->desarmer();

        $this->assertSame(
            'disabled',
            resolve(PatrolOrders::class)->whyLaunchIsRefused($this->planetService, $this->flotte(), new Resources(0, 0, 0, 0), 1000)
        );
    }

    /**
     * **Une patrouille en vol arrive et se pose.** L arrivee ne consulte pas l interrupteur : la
     * fermer laisserait une flotte en vol pour toujours.
     */
    public function testUnePatrouilleEnVolSePoseApresDesarmement(): void
    {
        $this->armer();
        $this->planetAddResources(new Resources(0, 0, 60000, 0));
        $this->planetAddUnit('cruiser', 20);

        $coords = $this->planetService->getPlanetCoordinates();
        $geometrie = resolve(PatrolPricing::class)->geometry();

        $patrouille = resolve(PatrolOrders::class)->launch(
            $this->planetService,
            $this->flotte(),
            new Resources(0, 0, 0, 0),
            10000,
            PatrolDestination::spatialPoint($geometrie, $coords->galaxy, $coords->system, new SpatialPoint(600, 600)),
            10,
            (int)Date::now()->timestamp
        );

        $segment = FleetMission::query()->findOrFail($patrouille->current_mission_id);

        // Elle est en vol, et l interrupteur tombe pendant le trajet.
        $this->desarmer();

        Date::setTestNow(Date::createFromTimestamp((int)$segment->time_arrival + 1));
        $this->player()->updateFleetMissions();

        $this->assertTrue(
            $patrouille->refresh()->state->isParked(),
            'Une patrouille partie avant le desarmement n arrive jamais : elle reste en vol pour toujours.'
        );
    }

    /**
     * **La reserve continue de se consommer, et le retour de securite part.** C est la sortie
     * automatique : si elle se fermait, une patrouille sans carburant serait perdue.
     */
    public function testLeRetourDeSecuriteParApresDesarmement(): void
    {
        // **Le rendez-vous est persiste a la pose** : inutile de brider la reserve au lancement —
        // une reserve trop maigre s y fait refuser, parce qu elle ne couvrirait pas le retour. On
        // part donc normalement, et on saute a l instant que le segment porte.
        [$patrouille, $segment] = $this->unePatrouillePosee();

        $rendezVous = resolve(PatrolOrders::class)->nextEventAt($segment);

        $this->assertNotNull($rendezVous, 'Sans rendez-vous, cet essai ne prouverait rien.');

        $this->desarmer();

        Date::setTestNow(Date::createFromTimestamp($rendezVous + 1));
        $this->player()->updateFleetMissions();

        $patrouille->refresh();

        $this->assertContains(
            $patrouille->state->value,
            [PatrolState::Returning->value, PatrolState::Immobilised->value],
            'Le retour de securite ne part plus : la patrouille reste posee sans reserve et sans recours.'
        );
    }

    /**
     * **Une attaque spatiale deja en vol se resout.** Elle est partie sous un chantier arme ; la
     * refuser a l arrivee laisserait une flotte en vol et une patrouille jamais attaquee.
     */
    public function testUneAttaqueSpatialeEnVolSeResoutApresDesarmement(): void
    {
        // Deux croiseurs : une cible que soixante vaisseaux de bataille ecrasent sans discussion.
        [$patrouille] = $this->unePatrouillePosee(1000, 2);

        // La cible appartient a quelqu un d autre : une patrouille ne s attaque pas elle-meme.
        $patrouille->forceFill(['user_id' => (int)User::factory()->create()->id])->save();

        $this->planetAddUnit('battle_ship', 60);
        $this->planetAddResources(new Resources(0, 0, 5_000_000, 0));
        $this->planetService->reloadPlanet();

        $flotte = new UnitCollection();
        $flotte->addUnit(ObjectService::getUnitObjectByMachineName('battle_ship'), 60);

        $attaque = resolve(SpatialAttackOrder::class)->launch(
            $this->planetService,
            $flotte,
            FrozenPatrolTarget::of($patrouille->refresh()),
            10.0,
            (int)Date::now()->timestamp
        );

        // Elle est en vol, et l interrupteur tombe.
        $this->desarmer();

        DB::table('fleet_missions')
            ->where('id', $attaque->id)
            ->update(['time_arrival' => (int)Date::now()->timestamp - 1]);

        $this->player()->updateFleetMissions();

        $this->assertSame(
            1,
            (int)FleetMission::query()->findOrFail($attaque->id)->processed,
            'L attaque en vol n est jamais traitee : la flotte reste en l air.'
        );

        $this->assertSame(
            PatrolState::Destroyed->value,
            $patrouille->refresh()->state->value,
            'La bataille n a pas eu lieu : soixante vaisseaux de bataille contre deux croiseurs ne laissent rien.'
        );
    }

    /**
     * **Aucune acquisition neuve ne nait pendant que le chantier est desarme.**
     *
     * La regle a ete fixee ici plutot que decouverte en production : un interrupteur baisse ne doit
     * plus produire d effet neuf chez les joueurs. Un contact qui apparaitrait tout seul sur la carte
     * d une fonction censee etre eteinte serait exactement cela.
     *
     * Les deux naissances sont fermees : l arrivee d une patrouille (`acquire()`) et la mise en
     * service d un detecteur sur des patrouilles deja presentes (`commission()`).
     */
    public function testAucuneAcquisitionNouvelleNeNaitApresDesarmement(): void
    {
        [$patrouille] = $this->unePatrouillePosee();

        $etranger = (int)User::factory()->create()->id;
        $patrouille->forceFill(['user_id' => $etranger])->save();

        DB::table('planets')
            ->where('id', $this->planetService->getPlanetId())
            ->update(['surveillance_network' => 3]);

        DB::table('surveillance_contacts')->where('patrol_id', (int)$patrouille->id)->delete();

        $this->desarmer();

        $veille = resolve(SurveillanceWatch::class);

        $veille->acquire($patrouille->refresh(), (int)Date::now()->timestamp);
        $veille->commission($this->planetService->getPlanetId(), 3, (int)Date::now()->timestamp);

        $this->assertSame(
            0,
            DB::table('surveillance_contacts')->where('patrol_id', (int)$patrouille->id)->count(),
            'Un chantier desarme continue d acquerir du renseignement neuf.'
        );
    }

    /**
     * **Mais un contact peut encore mourir.** L autre moitie de la meme regle : rien de neuf n entre,
     * tout ce qui existe doit pouvoir sortir. Fermer la revocation figerait des contacts pour
     * toujours — une patrouille partie resterait visible indefiniment.
     */
    public function testUnContactPeutEncoreEtreRevoqueApresDesarmement(): void
    {
        [$patrouille] = $this->unePatrouillePosee();

        $etranger = (int)User::factory()->create()->id;
        $patrouille->forceFill(['user_id' => $etranger])->save();

        DB::table('surveillance_contacts')->insert([
            'observer_user_id' => $this->currentUserId,
            'patrol_id' => (int)$patrouille->id,
            'observer_planet_id' => $this->planetService->getPlanetId(),
            'entered_system_at' => (int)Date::now()->timestamp - 600,
            'acquisition_from' => (int)Date::now()->timestamp - 600,
            'visible_from' => (int)Date::now()->timestamp - 60,
            'revoked_at' => null,
            'created_at' => Date::now(),
            'updated_at' => Date::now(),
        ]);

        $this->desarmer();

        $revoques = resolve(SurveillanceWatch::class)->revokeAllFor($patrouille->refresh(), (int)Date::now()->timestamp);

        $this->assertSame(1, $revoques, 'Un contact ne peut plus etre revoque : le desarmement le fige pour toujours.');
    }

    /**
     * **Les contacts deja acquis restent lisibles.** Le renseignement gagne ne s efface pas parce
     * qu on baisse l interrupteur ; il cesse seulement de s en acquerir de nouveaux.
     */
    public function testLesContactsDejaAcquisRestentLisiblesApresDesarmement(): void
    {
        [$patrouille] = $this->unePatrouillePosee();

        $etranger = (int)User::factory()->create()->id;
        $patrouille->forceFill(['user_id' => $etranger])->save();

        $coords = $this->planetService->getPlanetCoordinates();

        DB::table('planets')
            ->where('id', $this->planetService->getPlanetId())
            ->update(['surveillance_network' => 3]);

        DB::table('surveillance_contacts')->insert([
            'observer_user_id' => $this->currentUserId,
            'patrol_id' => (int)$patrouille->id,
            'observer_planet_id' => $this->planetService->getPlanetId(),
            'entered_system_at' => (int)Date::now()->timestamp - 600,
            'acquisition_from' => (int)Date::now()->timestamp - 600,
            'visible_from' => (int)Date::now()->timestamp - 60,
            'revoked_at' => null,
            'created_at' => Date::now(),
            'updated_at' => Date::now(),
        ]);

        $this->desarmer();

        $contacts = resolve(SurveillanceProjection::class)->inSystem(
            $this->currentUserId,
            $coords->galaxy,
            $coords->system,
            (int)Date::now()->timestamp
        );

        $this->assertNotEmpty($contacts, 'Un contact deja acquis disparait au desarmement : le renseignement gagne est perdu.');
    }
}
