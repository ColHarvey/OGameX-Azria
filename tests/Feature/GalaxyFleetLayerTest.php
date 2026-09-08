<?php

namespace Tests\Feature;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use OGame\Events\FleetMovementChanged;
use OGame\Events\GalaxySystemChanged;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\DebrisFieldService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\SettingsService;
use ReflectionProperty;
use Tests\FleetDispatchTestCase;

/**
 * La couche des flottes de la carte tactique : ce qu'un joueur voit, et ce qu'il ne voit pas.
 *
 * ## Le droit est etabli, pas affirme
 *
 * Chaque temoin envoie une vraie flotte et lit la vraie reponse du point d'entree. Le cas qui
 * compte le plus est le **refus** : la mission d'un tiers vers un tiers, dans le systeme regarde,
 * ne doit pas etre dans la reponse — non pas masquee, absente. Un temoin qui ne verifierait que la
 * presence des siennes passerait sur une carte omnisciente.
 */
class GalaxyFleetLayerTest extends FleetDispatchTestCase
{
    protected int $missionType = 6;

    protected string $missionName = 'Espionage';

    protected function basicSetup(): void
    {
        $this->planetAddUnit('espionage_probe', 5);
        $this->planetAddResources(new Resources(0, 0, 100000, 0));

        $settingsService = resolve(SettingsService::class);
        $settingsService->set('fleet_speed_war', 1);
        $settingsService->set('fleet_speed_holding', 1);
        $settingsService->set('fleet_speed_peaceful', 1);
    }

    protected function messageCheckMissionArrival(): void
    {
    }

    protected function messageCheckMissionReturn(): void
    {
    }

    /**
     * **Les compteurs du bandeau sont dans la charge des flottes, et ils sont vrais.** Cinq sondes,
     * une envoyee : la couche rend quatre sondes et un emplacement ; la photographie du systeme dit
     * la meme chose (une seule source) ; et l'envoi rapide depuis la Galaxie rend ce qui reste
     * **apres** lui — plus les onze sondes et l'emplacement unique de demonstration que le rendu
     * herite ecrivait dans le bandeau apres chaque sonde.
     */
    public function testTheFleetPayloadCarriesTheHeaderCountersAndTheQuickDispatchReportsThem(): void
    {
        $cible = $this->envoyerUneSonde();
        $coordonnees = $cible->getPlanetCoordinates();
        $joueur = $this->planetService->getPlayer();
        $this->assertNotNull($joueur, 'The bench planet has no owner.');

        $couche = $this->getJson(route('galaxy.fleets', ['galaxy' => $coordonnees->galaxy, 'system' => $coordonnees->system]))
            ->assertStatus(200)
            ->json();

        $this->assertSame(4, $couche['counters']['probes'] ?? null, 'The fleet payload does not carry the probes left on the planet.');
        $this->assertSame(0, $couche['counters']['recyclers'] ?? null);
        $this->assertSame(0, $couche['counters']['missiles'] ?? null);
        $this->assertSame(1, $couche['counters']['slotsUsed'] ?? null, 'The fleet payload does not count the mission just sent.');
        $this->assertSame($joueur->getFleetSlotsMax(), $couche['counters']['slotsMax'] ?? null);

        $photographie = $this->post(route('galaxy.ajax'), ['galaxy' => $coordonnees->galaxy, 'system' => $coordonnees->system, '_token' => csrf_token()])
            ->assertStatus(200)
            ->json();

        $this->assertSame(4, $photographie['system']['availableProbes'] ?? null, 'The system photograph and the fleet payload disagree on the probes: two sources.');
        $this->assertSame(1, $photographie['system']['usedFleetSlots'] ?? null);

        $sondesParEnvoi = $joueur->getEspionageProbesAmount() ?? 1;
        $rapide = $this->post(route('fleet.dispatch.sendminifleet'), [
            'galaxy' => $coordonnees->galaxy,
            'system' => $coordonnees->system,
            'position' => $coordonnees->position,
            'type' => PlanetType::Planet->value,
            'mission' => 6,
            'shipCount' => 1,
            '_token' => csrf_token(),
        ])->assertStatus(200)->json();

        $this->assertTrue($rapide['response']['success'] ?? false, 'The quick espionage was refused: ' . json_encode($rapide));
        $this->assertSame(2, $rapide['response']['slots'] ?? null, 'The quick dispatch reports a demonstration slot count instead of the real one.');
        $this->assertSame(4 - $sondesParEnvoi, $rapide['response']['probes'] ?? null, 'The quick dispatch reports demonstration probes instead of what is left after it.');
        $this->assertSame(0, $rapide['response']['recyclers'] ?? null);
        $this->assertSame(0, $rapide['response']['missiles'] ?? null);
    }

    /**
     * L'identifiant du proprietaire d'un corps — etabli, pas suppose : un corps sans joueur ne
     * peut pas servir de tiers dans ces scenarios, et le dire vaut mieux qu'un `null` qui explose.
     */
    private function proprietaireDe(PlanetService $corps): int
    {
        $joueur = $corps->getPlayer();
        $this->assertNotNull($joueur, 'The scenario needs an owned planet and got an orphan one.');

        return $joueur->getId();
    }

    /**
     * Une sonde envoyee vers la planete etrangere voisine.
     */
    private function envoyerUneSonde(): PlanetService
    {
        $this->basicSetup();

        $unites = new UnitCollection();
        $unites->addUnit(ObjectService::getUnitObjectByMachineName('espionage_probe'), 1);

        return $this->sendMissionToOtherPlayerPlanet($unites, new Resources(0, 0, 0, 0));
    }

    /**
     * Une mission ecrite directement, hors de tout service d'envoi : ce n'est pas l'envoi qu'on
     * eprouve, c'est la lecture.
     *
     * @param array<string, mixed> $colonnes
     */
    private function uneMissionBrute(array $colonnes): int
    {
        return (int)DB::table('fleet_missions')->insertGetId($colonnes + [
            'type_from' => 1,
            'type_to' => 1,
            'mission_type' => 1,
            'time_departure' => time(),
            'time_arrival' => time() + 3600,
            'light_fighter' => 10,
            'processed' => 0,
            'canceled' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Sa propre mission apparait, avec ses deux instants et son camp — et sans ses unites.
     */
    public function testAPlayerSeesHisOwnMissionInTheSystemItLeaves(): void
    {
        $this->envoyerUneSonde();

        $depart = $this->planetService->getPlanetCoordinates();
        $reponse = $this->getJson(route('galaxy.fleets', ['galaxy' => $depart->galaxy, 'system' => $depart->system]))
            ->assertStatus(200)
            ->json();

        $this->assertTrue($reponse['success']);
        $this->assertIsInt($reponse['server_now'], 'The server clock is missing: the browser would interpolate on its own clock.');

        $miennes = array_values(array_filter($reponse['movements'], fn (array $m): bool => $m['side'] === 'friendly' && $m['mission_type'] === 6));
        $this->assertCount(1, $miennes, 'The player does not see the probe he just sent.');

        $mouvement = $miennes[0];
        $this->assertFalse($mouvement['is_return']);
        $this->assertGreaterThan($mouvement['time_departure'], $mouvement['time_arrival']);
        $this->assertSame($depart->galaxy, $mouvement['from']['galaxy']);
        $this->assertSame($depart->system, $mouvement['from']['system']);
        $this->assertArrayNotHasKey('units', $mouvement, 'The movement carries units: the map does not need them, and a payload that carries them for nothing will reveal them one day.');
        $this->assertArrayNotHasKey('resources', $mouvement, 'The movement carries resources: same objection.');
    }

    /**
     * **La mission d'un tiers n'est pas envoyee.** C'est le temoin qui distingue une carte juste
     * d'une carte omnisciente : dans le meme systeme, une flotte qui ne concerne pas le lecteur
     * est absente de la reponse, pas masquee.
     */
    public function testAThirdPartyMissionIsAbsentNotHidden(): void
    {
        $depart = $this->planetService->getPlanetCoordinates();
        $etrangere = $this->getNearbyForeignPlanet();
        $autre = $this->getNearbyForeignCleanPlanet();

        $this->assertNotSame($this->proprietaireDe($etrangere), $this->currentUserId);

        $id = $this->uneMissionBrute([
            'user_id' => $this->proprietaireDe($etrangere),
            'planet_id_from' => $etrangere->getPlanetId(),
            'galaxy_from' => $depart->galaxy,
            'system_from' => $depart->system,
            'position_from' => $etrangere->getPlanetCoordinates()->position,
            'planet_id_to' => $autre->getPlanetId(),
            'galaxy_to' => $autre->getPlanetCoordinates()->galaxy,
            'system_to' => $autre->getPlanetCoordinates()->system,
            'position_to' => $autre->getPlanetCoordinates()->position,
        ]);

        $reponse = $this->getJson(route('galaxy.fleets', ['galaxy' => $depart->galaxy, 'system' => $depart->system]))
            ->assertStatus(200)
            ->json();

        $identifiants = array_map(static fn (array $m): int => $m['id'], $reponse['movements']);

        $this->assertNotContains($id, $identifiants, 'A mission between two strangers is sent to the reader: the map is omniscient.');
    }

    /**
     * Une flotte qui vise l'une de ses planetes est visible, et dite hostile.
     */
    public function testAnIncomingAttackOnHisPlanetIsVisibleAndHostile(): void
    {
        $cible = $this->planetService->getPlanetCoordinates();
        $etrangere = $this->getNearbyForeignPlanet();

        $id = $this->uneMissionBrute([
            'user_id' => $this->proprietaireDe($etrangere),
            'planet_id_from' => $etrangere->getPlanetId(),
            'galaxy_from' => $etrangere->getPlanetCoordinates()->galaxy,
            'system_from' => $etrangere->getPlanetCoordinates()->system,
            'position_from' => $etrangere->getPlanetCoordinates()->position,
            'planet_id_to' => $this->planetService->getPlanetId(),
            'galaxy_to' => $cible->galaxy,
            'system_to' => $cible->system,
            'position_to' => $cible->position,
        ]);

        $reponse = $this->getJson(route('galaxy.fleets', ['galaxy' => $cible->galaxy, 'system' => $cible->system]))
            ->assertStatus(200)
            ->json();

        $trouve = null;

        foreach ($reponse['movements'] as $mouvement) {
            if ($mouvement['id'] === $id) {
                $trouve = $mouvement;
            }
        }

        $this->assertNotNull($trouve, 'An attack on the player’s own planet is not shown to him.');
        $this->assertSame('hostile', $trouve['side'], 'An incoming attack is not marked hostile.');
    }

    /**
     * Le filtre par systeme tient : une mission visible n'apparait que dans les systemes qu'elle
     * touche.
     */
    public function testAMissionIsOnlyListedInTheSystemsItTouches(): void
    {
        $this->envoyerUneSonde();

        $mission = FleetMission::where('user_id', $this->currentUserId)->where('processed', 0)->first();
        $this->assertNotNull($mission);

        $ailleurs = null;

        for ($s = 1; $s <= 499; $s++) {
            if ($s !== (int)$mission->system_from && $s !== (int)$mission->system_to) {
                $ailleurs = $s;
                break;
            }
        }

        $this->assertNotNull($ailleurs);

        $reponse = $this->getJson(route('galaxy.fleets', ['galaxy' => (int)$mission->galaxy_from, 'system' => $ailleurs]))
            ->assertStatus(200)
            ->json();

        $identifiants = array_map(static fn (array $m): int => $m['id'], $reponse['movements']);
        $this->assertNotContains((int)$mission->id, $identifiants, 'A mission that touches neither end of the requested system is listed in it.');
    }

    public function testAnInvalidSystemIsRefused(): void
    {
        $this->getJson(route('galaxy.fleets', ['galaxy' => 0, 'system' => 0]))->assertStatus(422);
    }

    /**
     * L'envoi d'une flotte l'annonce aux deux parties, et a personne d'autre.
     */
    public function testSendingAFleetAnnouncesItToBothPartiesOnly(): void
    {
        /*
         * **L'annonce nait de l'ecriture** : c'est l'observateur du modele qu'on eprouve, pas le
         * parcours HTTP d'envoi. La mission est donc ecrite par Eloquent, apres la pose du faux
         * repartiteur. Passer par l'envoi reel ne le permettait pas — les aides du banc rechargent
         * le conteneur en chemin (`refreshApplication()`), et le faux disparaissait avec lui.
         */
        $cible = $this->getNearbyForeignPlanet();
        $depart = $this->planetService->getPlanetCoordinates();
        $arrivee = $cible->getPlanetCoordinates();

        Event::fake([FleetMovementChanged::class]);

        $mission = new FleetMission();
        $mission->user_id = $this->currentUserId;
        $mission->planet_id_from = $this->planetService->getPlanetId();
        $mission->galaxy_from = $depart->galaxy;
        $mission->system_from = $depart->system;
        $mission->position_from = $depart->position;
        $mission->type_from = PlanetType::Planet->value;
        $mission->planet_id_to = $cible->getPlanetId();
        $mission->galaxy_to = $arrivee->galaxy;
        $mission->system_to = $arrivee->system;
        $mission->position_to = $arrivee->position;
        $mission->type_to = PlanetType::Planet->value;
        $mission->mission_type = 6;
        $mission->time_departure = time();
        $mission->time_arrival = time() + 600;
        $mission->espionage_probe = 1;
        $mission->save();

        $destinataires = [];
        Event::assertDispatched(FleetMovementChanged::class, function (FleetMovementChanged $e) use (&$destinataires): bool {
            $destinataires[] = $e->playerId;

            return true;
        });

        $destinataires = array_values(array_unique($destinataires));
        sort($destinataires);

        $attendus = [$this->currentUserId, $this->proprietaireDe($cible)];
        sort($attendus);

        $this->assertSame($attendus, $destinataires, 'The announcement did not go to exactly the sender and the target owner.');
    }

    /**
     * Un champ de debris qui s'ecrit annonce son systeme, avec ses coordonnees et rien d'autre.
     */
    public function testADebrisFieldWriteAnnouncesTheSystem(): void
    {
        Event::fake([GalaxySystemChanged::class]);

        $coordonnees = $this->planetService->getPlanetCoordinates();
        $debris = resolve(DebrisFieldService::class);
        $debris->loadOrCreateForCoordinates($coordonnees);
        $debris->appendResources(new Resources(100, 100, 0, 0));
        $debris->save();

        $vus = 0;
        Event::assertDispatched(GalaxySystemChanged::class, function (GalaxySystemChanged $e) use (&$vus, $coordonnees): bool {
            if ($e->galaxy === $coordonnees->galaxy && $e->system === $coordonnees->system && $e->kind === 'debris') {
                $vus++;
                $this->assertSame(['galaxy', 'system', 'position', 'kind', 'change'], array_keys($e->broadcastWith()), 'The system announcement carries more than coordinates.');
            }

            return true;
        });

        $this->assertGreaterThanOrEqual(1, $vus, 'Writing a debris field did not announce its system.');
    }

    /**
     * Les regles des deux canaux, telles que `routes/channels.php` les enregistre, et le refus de
     * bout en bout par le vrai pilote.
     *
     * Le pilote `log` des essais repond 200 a tout canal : un temoin d'autorisation doit monter le
     * pilote reel, sinon il ne prouve rien. Piege deja paye par `CombatLiveBroadcastTest`, dont ce
     * temoin reprend la forme.
     */
    public function testAnotherPlayersFleetChannelIsRefused(): void
    {
        $autre = $this->proprietaireDe($this->getNearbyForeignPlanet());
        $moi = User::query()->findOrFail($this->currentUserId);
        $this->assertNotSame($autre, (int)$moi->id, 'The scenario compares a player with itself.');

        $regleJoueur = $this->channelRule('galaxy.player.{playerId}');
        $this->assertTrue((bool)$regleJoueur($moi, (string)$moi->id), 'A player is refused his own fleet channel.');
        $this->assertFalse((bool)$regleJoueur($moi, (string)$autre), 'A player is allowed on someone else’s fleet channel: he would hear their movements.');

        $regleSysteme = $this->channelRule('galaxy.system.{galaxy}.{system}');
        $this->assertTrue((bool)$regleSysteme($moi), 'A logged-in player is refused a system channel.');
        $this->assertFalse((bool)$regleSysteme(null), 'A visitor without a session is allowed on a system channel.');

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'clef-de-banc',
            'broadcasting.connections.reverb.secret' => 'secret-de-banc',
            'broadcasting.connections.reverb.app_id' => 'banc',
        ]);

        $this->actingAs($moi);
        $this->post('/broadcasting/auth', ['channel_name' => 'private-galaxy.player.' . $autre, 'socket_id' => '1234.5678'])
            ->assertStatus(403);
    }

    private function channelRule(string $motif): callable
    {
        $diffuseur = Broadcast::driver('log');
        $propriete = new ReflectionProperty(Broadcaster::class, 'channels');
        $propriete->setAccessible(true);
        $canaux = $propriete->getValue($diffuseur);
        $this->assertArrayHasKey($motif, $canaux, 'No authorisation rule is registered for ' . $motif . '.');

        return $canaux[$motif];
    }
}
