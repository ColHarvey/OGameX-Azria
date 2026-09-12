<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use OGame\Models\FleetMission;
use OGame\Models\Patrol;
use OGame\Models\Planet;
use OGame\Models\SurveillanceContact;
use OGame\Patrol\Enums\PatrolState;
use OGame\Patrol\Enums\SurveillanceTier;
use OGame\Patrol\PatrolHomecoming;
use OGame\Patrol\SurveillanceProjection;
use OGame\Patrol\SurveillanceWatch;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;

/**
 * Une patrouille rentree cesse d etre vue — a l ecriture comme a la lecture.
 *
 * ## Le defaut, tel qu il vivait en production
 *
 * `PatrolOrders::comeHome()` terminait une patrouille **et** revoquait ses contacts, avec ce
 * commentaire : « La patrouille n existe plus dans l espace : ce que les reseaux voyaient d elle
 * cesse de se voir. » Mais depuis `109af44b`, **tous** les retours de patrouille passent par
 * `PatrolHomecoming::receive()`, qui ecrivait exactement le meme etat final sans rien revoquer.
 *
 * Chaque patrouille rentree laissait donc derriere elle des contacts ouverts pour toujours : des
 * fantomes qu aucune revocation ne fermait, que la projection reprojetait a chaque lecture de la
 * carte, et qui portaient encore la relation — et, au palier de l identite, le nom du proprietaire.
 *
 * ## Deux protections, parce qu une seule a deja manque
 *
 * La revocation ferme la porte **a l ecriture** ; le filtre d etat de la projection la ferme **a la
 * lecture**. Ce banc exige les deux separement : chacun a son essai, et une mutation qui retire
 * l une des deux fait tomber l essai qui la nomme.
 */
class SurveillanceGhostContactTest extends AccountTestCase
{
    /** @var array<int, int> */
    private array $corpsPoses = [];

    /** @var array<int, int> */
    private array $patrouillesPosees = [];

    protected function setUp(): void
    {
        parent::setUp();

        // **Cet essai decrit un chantier arme.** La veille n ouvre plus de contact quand
        // `patrols_enabled` est baisse : l essai pose ce qu il suppose au lieu d en dependre.
        resolve(SettingsService::class)->set('patrols_enabled', '1');
    }

    protected function tearDown(): void
    {
        resolve(SettingsService::class)->set('patrols_enabled', '0');

        SurveillanceContact::query()->whereIn('observer_planet_id', $this->corpsPoses)->delete();
        SurveillanceContact::query()->whereIn('patrol_id', $this->patrouillesPosees)->delete();
        FleetMission::query()->whereIn('patrol_id', $this->patrouillesPosees)->delete();
        Patrol::query()->whereIn('id', $this->patrouillesPosees)->delete();
        Planet::query()->whereIn('id', $this->corpsPoses)->delete();

        parent::tearDown();
    }

    /**
     * **Une patrouille qui rentre par le chemin d aujourd hui revoque ses contacts.**
     *
     * Cet essai echoue sur le code d avant le correctif : `receive()` terminait la patrouille et
     * laissait le contact ouvert.
     */
    public function testAPatrolComingHomeRevokesWhatTheNetworksSawOfIt(): void
    {
        [$etranger, $galaxie, $systeme] = $this->unSystemeEtranger();

        $observateur = $this->unCorpsEquipe($etranger, $galaxie, $systeme, SurveillanceTier::Identity->value);
        $entree = 1_700_000_000;
        $patrouille = $this->unePatrouilleEntree($galaxie, $systeme, $entree);

        resolve(SurveillanceWatch::class)->acquire($patrouille, $entree);

        $contact = SurveillanceContact::query()->where('observer_planet_id', $observateur)->firstOrFail();
        $this->assertNull($contact->revoked_at, 'La premisse tombe : le contact est deja revoque avant le retour.');

        /*
         * **Le cas de l incident 830** : la patrouille a ete rappelee, son segment annule, elle ne
         * tient donc plus de point — et c est dans cette branche-la que `receive()` la termine.
         * Une patrouille qui tient encore son point est simplement reposee (`parkAgain`), et
         * garde ses contacts : c est juste, elle est toujours la.
         */
        $patrouille->forceFill(['x' => null, 'y' => null])->save();

        $arrivee = $entree + 3600;
        $retour = $this->unRetourDePatrouille($patrouille, $arrivee);

        resolve(PatrolHomecoming::class)->receive($retour);

        $patrouille->refresh();
        $this->assertSame(PatrolState::Finished, $patrouille->state, 'La premisse tombe : le retour n a pas termine la patrouille, l essai ne juge donc pas la revocation.');

        $contact->refresh();
        $this->assertSame($arrivee, (int)$contact->revoked_at, 'Une patrouille rentree laisse son contact ouvert : le reseau continue de voir une flotte qui n existe plus.');
        $this->assertFalse($contact->isVisibleAt($arrivee), 'Le contact reste visible apres le retour de la patrouille.');

        // Revoque, jamais efface — la ligne reste lisible pour un audit.
        $this->assertSame(1, SurveillanceContact::query()->where('observer_planet_id', $observateur)->count());
    }

    /**
     * **Et la lecture refuse une patrouille terminee, meme si un contact traine.**
     *
     * La seconde protection, eprouvee seule : le contact est laisse ouvert a la main, et la
     * projection ne doit rien rendre. Sans le filtre d etat, elle rendait un contact sans position
     * ni segment, portant sa relation et le nom de son proprietaire.
     */
    public function testTheProjectionIgnoresAFinishedPatrolEvenWithAnOpenContact(): void
    {
        [$etranger, $galaxie, $systeme] = $this->unSystemeEtranger();

        $this->unCorpsEquipe($etranger, $galaxie, $systeme, SurveillanceTier::Identity->value);
        $entree = 1_700_000_000;
        $patrouille = $this->unePatrouilleEntree($galaxie, $systeme, $entree);

        resolve(SurveillanceWatch::class)->acquire($patrouille, $entree);

        $maintenant = $entree + SurveillanceTier::Identity->acquisitionSeconds() + 60;
        $projection = resolve(SurveillanceProjection::class);

        // La premisse : tant qu elle patrouille, l observateur la voit bien.
        $this->assertCount(1, $projection->inSystem($etranger, $galaxie, $systeme, $maintenant), 'La premisse tombe : l observateur ne voit pas la patrouille vivante, l essai ne prouverait rien de sa disparition.');

        // Terminee, mais le contact laisse ouvert a la main : seule la lecture peut encore proteger.
        $patrouille->forceFill([
            'state' => PatrolState::Finished->value,
            'current_mission_id' => null,
            'x' => null,
            'y' => null,
            'finished_at' => $maintenant,
            'finish_reason' => 'came_home',
        ])->save();

        $this->assertNull(
            SurveillanceContact::query()->where('patrol_id', (int)$patrouille->id)->value('revoked_at'),
            'La premisse tombe : le contact a ete revoque, la lecture n est donc pas mise a l epreuve.'
        );

        $this->assertSame([], $projection->inSystem($etranger, $galaxie, $systeme, $maintenant), 'Une patrouille terminee est encore projetee : elle porte sa relation et le nom de son proprietaire.');
    }

    /**
     * Le systeme d une planete etrangere, et son proprietaire.
     *
     * @return array{int, int, int}
     */
    private function unSystemeEtranger(): array
    {
        $etrangere = $this->getNearbyForeignPlanet();
        $proprietaire = $etrangere->getPlayer();

        $this->assertNotNull($proprietaire);
        $this->assertNotSame($this->currentUserId, $proprietaire->getId(), 'Le systeme « etranger » appartient au proprietaire de la patrouille.');

        $coordonnees = $etrangere->getPlanetCoordinates();

        return [$proprietaire->getId(), $coordonnees->galaxy, $coordonnees->system];
    }

    private function positionLibreDans(int $galaxie, int $systeme): int
    {
        for ($position = 1; $position <= 15; $position++) {
            $prise = Planet::query()
                ->where('galaxy', $galaxie)
                ->where('system', $systeme)
                ->where('planet', $position)
                ->exists();

            if (!$prise) {
                return $position;
            }
        }

        $this->fail('Aucune position libre dans le systeme du banc.');
    }

    private function unCorpsEquipe(int $userId, int $galaxie, int $systeme, int $niveau): int
    {
        $planete = Planet::factory()->create([
            'user_id' => $userId,
            'galaxy' => $galaxie,
            'system' => $systeme,
            'planet' => $this->positionLibreDans($galaxie, $systeme),
            'surveillance_network' => $niveau,
        ]);

        $this->corpsPoses[] = (int)$planete->id;

        return (int)$planete->id;
    }

    private function unePatrouilleEntree(int $galaxie, int $systeme, int $entree): Patrol
    {
        $patrouille = Patrol::query()->create([
            'user_id' => (int)$this->currentUserId,
            'home_planet_id' => $this->planetService->getPlanetId(),
            'state' => PatrolState::Stationed->value,
            'galaxy' => $galaxie,
            'system' => $systeme,
            'x' => 0,
            'y' => 0,
            'fuel_reserve' => 1000,
            'upkeep_paid_at' => $entree,
            'stationed_since' => $entree,
            'entered_system_at' => $entree,
            'order_version' => 1,
        ]);

        $this->patrouillesPosees[] = (int)$patrouille->id;

        return $patrouille;
    }

    /**
     * Le vol de retour d une patrouille, tel que le jeu le cree : la patrouille le designe comme
     * son vol courant, sans quoi `receive()` refuse de la terminer.
     */
    private function unRetourDePatrouille(Patrol $patrouille, int $arrivee): FleetMission
    {
        $corps = $this->planetService;

        // **Ecrit a la ligne, pas par assignation de masse** : `FleetMission` ne declare pas ses
        // colonnes `fillable`, et un banc qui contournerait cette garde mesurerait autre chose que
        // ce que le jeu ecrit.
        $id = DB::table('fleet_missions')->insertGetId([
            'user_id' => (int)$patrouille->user_id,
            'planet_id_from' => null,
            'galaxy_from' => (int)$patrouille->galaxy,
            'system_from' => (int)$patrouille->system,
            'position_from' => 0,
            'type_from' => 5,
            'planet_id_to' => $corps->getPlanetId(),
            'galaxy_to' => $corps->getPlanetCoordinates()->galaxy,
            'system_to' => $corps->getPlanetCoordinates()->system,
            'position_to' => $corps->getPlanetCoordinates()->position,
            'type_to' => 1,
            'mission_type' => 11,
            'time_departure' => $arrivee - 600,
            'time_arrival' => $arrivee,
            'processed' => 0,
            'patrol_id' => (int)$patrouille->id,
            // Une flotte qui rentre porte des vaisseaux : une flotte vide n existe pas, et le
            // service divise par sa capacite.
            'light_fighter' => 3,
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
        ]);

        $retour = FleetMission::query()->findOrFail($id);

        // La patrouille doit pointer sur ce vol : c est ce que `receive()` exige pour la terminer.
        DB::table('patrols')->where('id', (int)$patrouille->id)->update(['current_mission_id' => (int)$retour->id]);
        $patrouille->refresh();

        return $retour;
    }
}
