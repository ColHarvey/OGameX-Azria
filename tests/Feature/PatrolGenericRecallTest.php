<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use OGame\GameMissions\PatrolMission;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Enums\PlanetType;
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
use OGame\Services\SettingsService;
use Tests\AccountTestCase;

/**
 * Le rappel generique et les segments de patrouille — l incident du 11 septembre 2026.
 *
 * ## Ce qui s est passe en production
 *
 * Le joueur 6 a rappele une patrouille depuis la boite d evenements. Le rappel generique ne connait
 * pas les patrouilles : il a cree un **retour** de genre « patrouille » (mission 830), et a
 * l arrivee de ce retour `PatrolMission::processReturn()` levait une exception — « regle par le
 * service des mouvements de patrouille, jamais par le traitement generique ». Le jeu traite les
 * missions du joueur a chaque requete : **chaque page lui rendait 500, la connexion comprise.**
 *
 * ## Ce que ces temoins etablissent
 *
 * 1. **Curatif** : un tel retour, deja en base, est livre — la page s ouvre, la flotte atterrit sur
 *    son corps d arrivee, la patrouille est terminee. C est l etat exact du joueur 6, rejoue.
 * 2. **Preventif** : le rappel generique refuse un segment de patrouille, au service comme au
 *    controleur (409 et une raison lisible), sans creer de retour.
 * 3. **Lisibilite** : les lignes d un segment n offrent plus le bouton — une action ne s offre que
 *    si elle peut aboutir.
 */
class PatrolGenericRecallTest extends AccountTestCase
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

    private function fleet(array $composition): UnitCollection
    {
        $units = new UnitCollection();

        foreach ($composition as $nom => $nombre) {
            $units->addUnit(ObjectService::getUnitObjectByMachineName($nom), $nombre);
        }

        return $units;
    }

    private function aFreePoint(): PatrolDestination
    {
        $coords = $this->planetService->getPlanetCoordinates();
        $geometrie = resolve(PatrolPricing::class)->geometry();

        return PatrolDestination::spatialPoint($geometrie, $coords->galaxy, $coords->system, new SpatialPoint(600, 600));
    }

    /**
     * Une patrouille de vingt croiseurs, lancee de la planete du banc vers un point libre.
     */
    private function unePatrouilleEnVol(): Patrol
    {
        $this->arm();
        $this->planetAddResources(new Resources(0, 0, 60000, 0));
        $this->planetAddUnit('cruiser', 20);

        return resolve(PatrolOrders::class)->launch(
            $this->planetService,
            $this->fleet(['cruiser' => 20]),
            new Resources(0, 0, 0, 0),
            10000,
            $this->aFreePoint(),
            10,
            (int)Date::now()->timestamp
        );
    }

    /**
     * **Le retour que le rappel generique laissait derriere lui**, tel que la base du joueur 6 le
     * portait : le segment annule, et un retour de genre « patrouille », ne de lui, vers la base.
     *
     * Il est ecrit a la main parce que le chemin qui le creait est ferme par ces memes temoins ;
     * ce qui existe deja en base, lui, doit etre livre.
     */
    private function unRetourLaisseParLeRappelGenerique(Patrol $patrouille): FleetMission
    {
        /** @var FleetMission $segment */
        $segment = FleetMission::query()->findOrFail($patrouille->current_mission_id);
        $segment->forceFill(['canceled' => 1, 'processed' => 1])->save();

        $coords = $this->planetService->getPlanetCoordinates();
        $maintenant = (int)Date::now()->timestamp;

        $retour = (new FleetMission())->forceFill([
            'user_id' => $this->currentUserId,
            'parent_id' => $segment->id,
            'patrol_id' => $patrouille->id,
            'mission_type' => PatrolMission::TYPE,
            'planet_id_from' => null,
            'galaxy_from' => $coords->galaxy,
            'system_from' => $coords->system,
            'position_from' => 0,
            'type_from' => PlanetType::SpatialPoint->value,
            'x_from' => 600,
            'y_from' => 600,
            'planet_id_to' => $this->planetService->getPlanetId(),
            'galaxy_to' => $coords->galaxy,
            'system_to' => $coords->system,
            'position_to' => $coords->position,
            'type_to' => PlanetType::Planet->value,
            'time_departure' => $maintenant,
            'time_arrival' => $maintenant + 100,
            'cruiser' => 20,
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
            'processed' => 0,
            'canceled' => 0,
        ]);
        $retour->save();

        return $retour;
    }

    /**
     * **Le joueur 6 rouvre ses pages, et sa flotte rentre.**
     *
     * La page d accueil est demandee **apres** l arrivee du retour : c est elle qui traite les
     * missions du joueur, et c est elle qui rendait 500. Puis les effets : retour regle, croiseurs
     * rendus a la planete, patrouille terminee par ce retour — bien qu elle pointe encore sur le
     * segment annule, comme en production.
     */
    public function testUnRetourLaisseParLeRappelGeneriqueEstLivreEtLaPageSOuvre(): void
    {
        $patrouille = $this->unePatrouilleEnVol();
        $retour = $this->unRetourLaisseParLeRappelGenerique($patrouille);

        $this->assertSame(0, $this->planetService->getObjectAmount('cruiser'), 'La premisse manque : les croiseurs sont encore sur la planete.');

        Date::setTestNow(Date::createFromTimestamp((int)$retour->time_arrival + 1));

        $reponse = $this->get('/overview');
        $reponse->assertStatus(200);

        $retour->refresh();
        $patrouille->refresh();
        $this->planetService->reloadPlanet();

        $this->assertSame(1, (int)$retour->processed, 'Le retour n est pas regle : il sera repris a chaque requete.');
        $this->assertSame(20, $this->planetService->getObjectAmount('cruiser'), 'Les croiseurs ne sont pas rendus a la planete : la flotte a disparu avec le retour.');
        $this->assertSame(PatrolState::Finished, $patrouille->state, 'La patrouille n est pas terminee : son vol vient pourtant de se poser.');
        $this->assertSame('came_home', $patrouille->finish_reason);
        $this->assertNull($patrouille->current_mission_id);
    }

    /**
     * **Le rappel generique refuse un segment, et il le dit.**
     *
     * Au controleur : 409 avec une raison traduite (jamais une clef `t_ingame.` nue). Au service,
     * qui est le filet — l interface n est jamais la protection : aucun retour n est cree, le
     * segment et la patrouille restent tels quels.
     */
    public function testLeRappelGeneriqueRefuseUnSegmentDePatrouilleEtLeDit(): void
    {
        $patrouille = $this->unePatrouilleEnVol();
        $segment = (int)$patrouille->current_mission_id;

        $reponse = $this->post('/ajax/fleet/dispatch/recall-fleet', ['fleet_mission_id' => $segment]);

        $reponse->assertStatus(409);
        $reponse->assertJsonPath('success', false);

        $raison = (string)$reponse->json('error');

        $this->assertSame(__('t_ingame.fleet.recall_refused_patrol'), $raison);
        $this->assertStringNotContainsString('t_ingame.', $raison, 'Le joueur lirait une clef de traduction.');

        // Le filet du service, appele directement.
        resolve(FleetMissionService::class)->cancelMission(FleetMission::query()->findOrFail($segment));

        $this->assertFalse(
            FleetMission::query()->where('parent_id', $segment)->exists(),
            'Un retour a ete cree pour un segment de patrouille : c est exactement la mission 830.'
        );

        $patrouille->refresh();
        /** @var FleetMission $mission */
        $mission = FleetMission::query()->findOrFail($segment);

        $this->assertSame(PatrolState::EnRoute, $patrouille->state);
        $this->assertSame(0, (int)$mission->canceled);
        $this->assertSame(0, (int)$mission->processed);
    }

    /**
     * **Les lignes d un segment n offrent pas le bouton** — ni dans la liste d evenements, ni sur
     * la page de mouvement. La premisse : la ligne existe, et une mission ordinaire l offre bien.
     */
    public function testLesLignesDUnSegmentNOffrentPasLeRappel(): void
    {
        $patrouille = $this->unePatrouilleEnVol();
        $segment = (int)$patrouille->current_mission_id;

        // Une mission ordinaire, pour prouver que le bouton existe encore la ou il doit : une sonde
        // vers la voisine, ecrite directement — le banc des comptes n a pas d aide d envoi.
        $cible = $this->getNearbyForeignPlanet();
        $depart = $this->planetService->getPlanetCoordinates();
        $arrivee = $cible->getPlanetCoordinates();
        $maintenant = (int)Date::now()->timestamp;
        $sonde = (new FleetMission())->forceFill([
            'user_id' => $this->currentUserId,
            'planet_id_from' => $this->planetService->getPlanetId(),
            'galaxy_from' => $depart->galaxy,
            'system_from' => $depart->system,
            'position_from' => $depart->position,
            'type_from' => PlanetType::Planet->value,
            'planet_id_to' => $cible->getPlanetId(),
            'galaxy_to' => $arrivee->galaxy,
            'system_to' => $arrivee->system,
            'position_to' => $arrivee->position,
            'type_to' => PlanetType::Planet->value,
            'mission_type' => 6,
            'time_departure' => $maintenant,
            'time_arrival' => $maintenant + 600,
            'espionage_probe' => 1,
            'processed' => 0,
            'canceled' => 0,
        ]);
        $sonde->save();

        // La ligne se reconnait a son identifiant ; `data-fleet-id` n existe que sur le bouton de rappel.
        $lignes = ['/ajax/fleet/eventlist/fetch' => 'id="eventRow-', '/fleet/movement' => 'id="fleet'];

        foreach ($lignes as $page => $marque) {
            $html = (string)$this->get($page)->assertStatus(200)->getContent();

            $this->assertStringContainsString($marque . $segment . '"', $html, 'La premisse manque : la ligne du segment n est pas sur ' . $page . ' (la boite ne l affiche plus).');
            $this->assertStringContainsString('recallFleet" data-fleet-id="' . $sonde->id . '"', $html, 'La premisse manque : une mission ordinaire n offre plus le rappel sur ' . $page . '.');
            $this->assertStringNotContainsString('recallFleet" data-fleet-id="' . $segment . '"', $html, 'Le rappel est offert sur un segment de patrouille (' . $page . ') : le joueur retomberait sur la mission 830.');
        }
    }
}
