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
use OGame\Services\PlanetService;
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
     * **Et le devis du rappel repond encore.** C est l etape que le navigateur fait EN PREMIER :
     * montrer un bouton arme dont le devis est refuse laisse le joueur cliquer dans le vide.
     *
     * ## La regle que ce temoin pose
     *
     * **Un devis est refuse exactement quand l ordre le serait — ni plus, ni moins.** Le point
     * d entree posait `whyMoveIsRefused()` a toutes les demandes, rappel compris ; cette
     * methode-la consulte l interrupteur, et `whyRecallIsRefused()` ne le consulte pas, tout
     * expres. Les deux moities de la meme decision ne repondaient donc pas la meme chose, et c est
     * la moitie visible du joueur qui repondait faux.
     *
     * Les trois temoins voisins ne pouvaient pas le voir : l un appelle le service, l autre lit la
     * charge utile de la carte, aucun ne passe par le devis. **Un parcours n est eprouve que par le
     * chemin que le joueur emprunte.**
     */
    public function testLeDevisDuRappelRepondEncoreApresDesarmement(): void
    {
        [$patrouille] = $this->unePatrouillePosee();

        $this->desarmer();

        $reponse = $this->postJson(route('galaxy.patrol.quote'), [
            'patrol_id' => $patrouille->id,
            'kind' => 'recall',
        ]);

        $reponse->assertStatus(200);

        $this->assertGreaterThan(
            0,
            (int)$reponse->json('quote.duration_seconds'),
            'Le devis du rappel ne chiffre aucun trajet.'
        );

        // Et l autre moitie de la regle : le devis d une manoeuvre, lui, reste bien ferme.
        $coords = $this->planetService->getPlanetCoordinates();

        $this->postJson(route('galaxy.patrol.quote'), [
            'patrol_id' => $patrouille->id,
            'galaxy' => $coords->galaxy,
            'system' => $coords->system,
            'x' => 400,
            'y' => 400,
            'speed' => 10,
        ])->assertStatus(409)->assertJsonPath('reason_key', 'disabled');
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
     * **Se poser sur un corps choisi reste ouvert apres desarmement.** C est une sortie, et un
     * chantier desarme ne retient rien de ce qui existe.
     *
     * L exception de l interrupteur ne connaissait que `homeOf()`. S en tenir a la base aurait rendu
     * l atterrissage impossible des l arret d urgence — soit exactement le defaut que cette
     * exception existe pour empecher.
     */
    public function testUnAtterrissageSurUnCorpsChoisiResteOuvertApresDesarmement(): void
    {
        [$patrouille] = $this->unePatrouillePosee();
        $autre = $this->unAutreCorpsDuJoueur();

        $this->desarmer();

        $ordres = resolve(PatrolOrders::class);

        $this->assertNull(
            $ordres->whyLandingIsRefused($patrouille, $autre, (int)Date::now()->timestamp),
            'Un chantier desarme empeche la flotte de se poser : elle est prisonniere de l espace.'
        );

        $retour = $ordres->landOn($patrouille, $autre, (int)$patrouille->order_version, (int)Date::now()->timestamp);

        $this->assertNotNull($retour->id, 'L atterrissage n a cree aucun segment.');
        $this->assertSame(
            (int)$autre->getPlanetId(),
            (int)$retour->planet_id_to,
            'Le segment ne vise pas le corps choisi : il retombe sur la base.'
        );
    }

    /**
     * **Et l exception elargie garde sa frontiere : le corps doit etre a soi.**
     *
     * En passant de « sa base » a « un de ses corps », la garde a change de question. Celui-ci
     * verifie que la nouvelle question est aussi fermee que l ancienne : un ordre force qui partirait
     * en retour vers le corps d un **autre joueur** reste refuse, chantier desarme. Sans cela,
     * l arret d urgence deviendrait un couloir pour livrer une flotte a un adversaire.
     *
     * L appel prive est force volontairement : aucun appelant du jeu ne le prend — `landOn()` refuse
     * deja le corps d un autre —, et cet essai existe pour que personne ne l ouvre demain.
     */
    public function testUnRetourForceVersLeCorpsDUnAutreResteRefuse(): void
    {
        [$patrouille] = $this->unePatrouillePosee();
        $etranger = $this->getNearbyForeignPlanet();

        $this->desarmer();

        $ordres = resolve(PatrolOrders::class);
        $geometrie = resolve(PatrolPricing::class)->geometry();
        $coords = $etranger->getPlanetCoordinates();

        $chezLAutre = PatrolDestination::landingOn(
            $geometrie,
            $coords->galaxy,
            $coords->system,
            $coords->position,
            $etranger->getPlanetType(),
            (int)$etranger->getPlanetId()
        );

        $dispatch = new ReflectionMethod(PatrolOrders::class, 'dispatchOrder');

        $this->expectException(PatrolOrderRefused::class);
        $this->expectExceptionMessage('disabled');

        $dispatch->invoke(
            $ordres,
            $patrouille,
            $chezLAutre,
            10.0,
            (int)$patrouille->order_version,
            (int)Date::now()->timestamp,
            PatrolState::Returning,
            null
        );
    }

    /**
     * Un corps du joueur qui n est pas celui d ou la patrouille est partie.
     */
    private function unAutreCorpsDuJoueur(): PlanetService
    {
        $base = (int)$this->planetService->getPlanetId();

        foreach ($this->player()->planets->all() as $corps) {
            if ((int)$corps->getPlanetId() !== $base) {
                return $corps;
            }
        }

        $this->fail('Le compte du banc n a qu un corps : cet essai ne distinguerait pas un atterrissage choisi d un rappel.');
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

        // **L arrivee suit la naissance des deux comptes**, l attaquant et le proprietaire que l essai vient de
        // creer : datee d avant eux, le gel a l admission refuserait de dire quelle classe ils avaient.
        $this->travelTo(now()->addHour());

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

        // **L essai etablit son compte, il ne le suppose pas.** La veille peut avoir ouvert un
        // contact sur cette patrouille pendant le montage — un retour d essai voisin qui aboutit
        // pendant un saut d horloge suffit. Le compte attendu ne serait alors plus le sien.
        DB::table('surveillance_contacts')->where('patrol_id', (int)$patrouille->id)->delete();

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
