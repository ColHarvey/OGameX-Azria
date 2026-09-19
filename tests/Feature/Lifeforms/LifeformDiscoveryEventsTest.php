<?php

namespace Tests\Feature\Lifeforms;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\Presentation\DiscoveryFleetEvents;
use OGame\Lifeforms\Presentation\GalaxyDiscoveries;
use OGame\Lifeforms\Services\LifeformDiscoveryService;
use OGame\Lifeforms\Services\LifeformInstallationService;
use OGame\Lifeforms\Services\LifeformLevels;
use OGame\Lifeforms\Species;
use OGame\Models\Lifeforms\LifeformAccount;
use OGame\Models\Lifeforms\LifeformBuildingLevel;
use OGame\Models\Lifeforms\LifeformDiscovery;
use OGame\Models\Lifeforms\LifeformPlanet;
use OGame\Models\Lifeforms\LifeformSpeciesProgress;
use OGame\Models\Message;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\PlanetService;
use Tests\AccountTestCase;
use Tests\Support\PinsSettings;

/**
 * **Un vol d exploration se voit dans les evenements du bandeau, comme toute mission** (demande de Keven, journal §163).
 *
 * Le vol part sans vaisseau et ne vit pas dans `fleet_missions` : la boite d evenements ne le voyait pas, et un joueur
 * qui venait de cliquer sur l icone ADN ne voyait rien partir. Ce banc exige les trois faces de la boite — le bandeau
 * ferme (`/ajax/fleet/eventbox/fetch`), le deroulant (`/ajax/fleet/eventlist/fetch`) et `checkevents` — telles que le
 * script les lit.
 */
final class LifeformDiscoveryEventsTest extends AccountTestCase
{
    use PinsSettings;

    private const int RESEARCH_CENTRE = 11103;

    /** @var array<int, int> */
    private array $missions = [];

    private PlanetService $etrangere;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pinSettings(['lifeforms_enabled' => 1, 'economy_speed' => 1]);
        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Humans, (int)Date::now()->timestamp);
        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Building, self::RESEARCH_CENTRE, 1);
        $this->planetAddResources(new Resources(100000, 100000, 100000, 0));
        $this->etrangere = $this->getNearbyForeignPlanet();
    }

    protected function tearDown(): void
    {
        DB::table('fleet_missions')->whereIn('id', $this->missions)->delete();
        $planetes = Planet::query()->where('user_id', $this->currentUserId)->pluck('id');
        LifeformDiscovery::query()->where('user_id', $this->currentUserId)->delete();
        Message::query()->where('user_id', $this->currentUserId)->where('key', 'lifeform_discovery_report')->delete();
        LifeformBuildingLevel::query()->whereIn('planet_id', $planetes)->delete();
        LifeformPlanet::query()->whereIn('planet_id', $planetes)->delete();
        LifeformAccount::query()->where('user_id', $this->currentUserId)->delete();
        LifeformSpeciesProgress::query()->where('user_id', $this->currentUserId)->delete();
        $this->restorePinnedSettings();
        parent::tearDown();
    }

    public function testTheClosedBannerCountsTheFlightAndNamesItWhenItIsTheNextEvent(): void
    {
        $maintenant = (int)Date::now()->timestamp;
        $avant = $this->getJson(route('fleet.eventbox.fetch'))->json();
        $this->assertSame(0, $avant['friendly'] + $avant['neutral'] + $avant['hostile'], 'Premisse : aucun mouvement avant le vol.');

        $vol = resolve(LifeformDiscoveryService::class)->launch($this->planetService, $this->cible(), $maintenant);
        $this->assertGreaterThan($maintenant, $vol->ends_at, 'Premisse : le vol dure.');

        $boite = $this->getJson(route('fleet.eventbox.fetch'));
        $boite->assertStatus(200);
        $this->assertSame(1, $boite->json('friendly'), 'Le vol compte comme un mouvement ami.');
        $this->assertSame(0, $boite->json('neutral'));
        $this->assertSame(0, $boite->json('hostile'));
        $this->assertSame('friendly', $boite->json('eventType'));
        $this->assertSame(__('t_ingame.fleet.mission_discovery'), $boite->json('eventText'), 'Le prochain evenement est nomme, traduit.');
        $this->assertSame((int)$vol->ends_at - $maintenant, $boite->json('eventTime'), 'Le compte a rebours du bandeau vise l echeance du vol.');

        // Une mission de flotte qui arrive APRES le vol : elle compte, le vol reste le prochain evenement.
        $this->uneMission(1, (int)$vol->ends_at + 600);
        $boite = $this->getJson(route('fleet.eventbox.fetch'));
        $this->assertSame(2, $boite->json('friendly'));
        $this->assertSame(__('t_ingame.fleet.mission_discovery'), $boite->json('eventText'));
        $this->assertSame((int)$vol->ends_at - $maintenant, $boite->json('eventTime'));

        // Une mission qui arrive AVANT le vol : c est elle, le prochain evenement — le vol ne s impose pas.
        $this->uneMission(1, (int)$vol->ends_at - 60);
        $boite = $this->getJson(route('fleet.eventbox.fetch'));
        $this->assertSame(3, $boite->json('friendly'));
        $this->assertSame(__('t_ingame.fleet.mission_attack'), $boite->json('eventText'));
        $this->assertSame((int)$vol->ends_at - 60 - $maintenant, $boite->json('eventTime'));

        // Un second vol, plus court que la mission : le prochain evenement redevient un vol.
        $second = resolve(LifeformDiscoveryService::class)->launch($this->planetService, $this->cible(1), $maintenant);
        LifeformDiscovery::query()->whereKey($second->id)->update(['ends_at' => (int)$vol->ends_at - 120]);
        $boite = $this->getJson(route('fleet.eventbox.fetch'));
        $this->assertSame(4, $boite->json('friendly'));
        $this->assertSame(__('t_ingame.fleet.mission_discovery'), $boite->json('eventText'));
        $this->assertSame((int)$vol->ends_at - 120 - $maintenant, $boite->json('eventTime'), 'Le plus proche des deux vols.');
    }

    public function testTheListShowsTheFlightAsARowInArrivalOrderWithItsCountdownAndNoRecall(): void
    {
        $maintenant = (int)Date::now()->timestamp;
        $vol = resolve(LifeformDiscoveryService::class)->launch($this->planetService, $this->cible(), $maintenant);
        $ligne = DiscoveryFleetEvents::rowId((int)$vol->id);
        $avant = $this->uneMission(1, (int)$vol->ends_at - 60);
        $apres = $this->uneMission(1, (int)$vol->ends_at + 600);

        $liste = $this->get(route('fleet.eventlist.fetch'));
        $liste->assertStatus(200);
        $html = (string)$liste->getContent();

        $debut = strpos($html, 'id="eventRow-' . $ligne . '"');
        $this->assertNotFalse($debut, 'La ligne du vol existe, avec un identifiant hors de l espace des missions.');
        $fin = strpos($html, '</tr>', $debut);
        $this->assertNotFalse($fin);
        $rang = substr($html, $debut, $fin - $debut);

        $this->assertStringContainsString('data-mission-type="' . GalaxyDiscoveries::MISSION_TYPE . '"', $rang, 'Le type de mission du jeu officiel.');
        $this->assertStringContainsString('data-return-flight="false"', $rang);
        $this->assertStringContainsString('data-arrival-time="' . $vol->ends_at . '"', $rang);
        $this->assertStringContainsString('id="counter-eventlist-' . $ligne . '"', $rang, 'Le compte a rebours que le script anime.');
        $this->assertStringContainsString(__('t_ingame.layout.eventbox_own_fleet') . ' | ' . __('t_ingame.fleet.mission_discovery'), $rang);
        $this->assertStringContainsString('planetDiscoverIcons planetDiscoverDefault', $rang, 'L icone ADN de la Galaxie.');
        $this->assertStringContainsString(e($this->planetService->getPlanetName()), $rang, 'La planete de depart, par son nom.');
        $this->assertStringContainsString('[' . $this->planetService->getPlanetCoordinates()->asString() . ']', $rang);
        $this->assertStringContainsString('[' . $this->cible()->asString() . ']', $rang, 'La position visee.');
        $this->assertStringContainsString(e(__('t_ingame.galaxy.discovery_title')) . ':', $rang, 'Le detail nomme le vaisseau d exploration.');
        $this->assertStringNotContainsString('recallFleet', $rang, 'Un vol d exploration ne se rappelle pas.');
        $this->assertStringNotContainsString('/img/fleet/' . GalaxyDiscoveries::MISSION_TYPE . '.gif', $rang, 'Aucune image de mission inexistante.');

        // Le script du compte a rebours vise cette ligne, avec la meme adresse `checkevents` que les missions.
        $this->assertStringContainsString('$("#counter-eventlist-' . $ligne . '")', $html);
        $this->assertStringContainsString('[' . $ligne . ']', $html);
        $this->assertSame(1, substr_count($html, 'id="eventRow-' . $ligne . '"'), 'Une ligne par vol.');

        // Trie par echeance avec les missions : la mission qui arrive avant precede le vol, celle qui arrive apres le suit.
        $positionVol = strpos($html, 'id="eventRow-' . $ligne . '"');
        $positionAvant = strpos($html, 'id="eventRow-' . $avant . '"');
        $positionApres = strpos($html, 'id="eventRow-' . $apres . '"');
        $this->assertNotFalse($positionAvant);
        $this->assertNotFalse($positionApres);
        $this->assertLessThan($positionVol, $positionAvant, 'La mission plus proche vient avant le vol.');
        $this->assertGreaterThan($positionVol, $positionApres, 'La mission plus lointaine vient apres le vol.');
    }

    public function testTheTargetPlanetIsNamedWhenThereIsOneAndAnEmptyPositionShowsItsCoordinatesAlone(): void
    {
        $maintenant = (int)Date::now()->timestamp;
        // La planete d un autre joueur, dans mon systeme, sous un nom que rien d autre dans la ligne ne porte.
        $cible = $this->cible();
        $autre = User::factory()->create();
        Planet::factory()->create(['user_id' => $autre->id, 'galaxy' => $cible->galaxy, 'system' => $cible->system, 'planet' => $cible->position, 'name' => 'Ophiuchus Visee']);
        $vol = resolve(LifeformDiscoveryService::class)->launch($this->planetService, $cible, $maintenant);
        $ligne = DiscoveryFleetEvents::rowId((int)$vol->id);
        $vide = $this->cible(1);
        $volVide = resolve(LifeformDiscoveryService::class)->launch($this->planetService, $vide, $maintenant);
        $ligneVide = DiscoveryFleetEvents::rowId((int)$volVide->id);

        $html = (string)$this->get(route('fleet.eventlist.fetch'))->getContent();
        $debut = strpos($html, 'id="eventRow-' . $ligne . '"');
        $this->assertNotFalse($debut);
        $rang = substr($html, $debut, (int)strpos($html, '</tr>', $debut) - $debut);
        $this->assertStringContainsString('Ophiuchus Visee', $rang, 'La planete visee, par son nom.');
        $this->assertStringContainsString('[' . $cible->asString() . ']', $rang);
        $this->assertSame(2, substr_count($rang, 'class="planetIcon planet'), 'Une icone de planete au depart, une a l arrivee.');

        $debut = strpos($html, 'id="eventRow-' . $ligneVide . '"');
        $this->assertNotFalse($debut);
        $rangVide = substr($html, $debut, (int)strpos($html, '</tr>', $debut) - $debut);
        $this->assertStringContainsString('[' . $vide->asString() . ']', $rangVide);
        $this->assertSame(1, substr_count($rangVide, 'class="planetIcon planet'), 'Une position vide : ses coordonnees, sans planete inventee.');
        $this->assertStringNotContainsString('Ophiuchus Visee', $rangVide);
    }

    public function testCheckEventsKeepsTheRowUntilTheFlightEndsAndDropsItAfter(): void
    {
        $maintenant = (int)Date::now()->timestamp;
        $vol = resolve(LifeformDiscoveryService::class)->launch($this->planetService, $this->cible(), $maintenant);
        $ligne = DiscoveryFleetEvents::rowId((int)$vol->id);
        $inconnue = 123456789;

        $reponse = $this->post(route('fleet.eventlist.checkevents'), ['ids' => [$ligne, $inconnue]]);
        $reponse->assertStatus(200);
        $lignes = json_decode((string)$reponse->getContent(), true)['rows'];
        $this->assertContains($inconnue, $lignes, 'Premisse : une ligne inconnue est retiree.');
        $this->assertNotContains($ligne, $lignes, 'Le vol en cours garde sa ligne.');

        $this->travelTo(Date::createFromTimestamp((int)$vol->ends_at + 1));
        // La regle elle-meme, avant le passage du joueur qui reglera le vol : un vol encore « en cours » mais echu n est plus affiche.
        $this->assertSame('running', (string)LifeformDiscovery::query()->whereKey($vol->id)->value('status'), 'Premisse : rien n a encore regle le vol.');
        $this->assertNotContains($ligne, resolve(DiscoveryFleetEvents::class)->displayedRowIds($this->currentUserId, (int)$vol->ends_at + 1));
        $this->assertContains($ligne, resolve(DiscoveryFleetEvents::class)->displayedRowIds($this->currentUserId, (int)$vol->ends_at - 1));
        $resume = resolve(DiscoveryFleetEvents::class)->summary($this->currentUserId, (int)$vol->ends_at + 1);
        $this->assertSame(1, $resume['count'], 'Le vol echu compte encore tant qu il n est pas regle.');
        $this->assertNull($resume['next_ends_at'], 'Mais il n est plus un evenement a venir.');
        $reponse = $this->post(route('fleet.eventlist.checkevents'), ['ids' => [$ligne]]);
        $lignes = json_decode((string)$reponse->getContent(), true)['rows'];
        $this->assertContains($ligne, $lignes, 'A l echeance, la ligne disparait.');
        $this->assertNotSame('running', (string)LifeformDiscovery::query()->whereKey($vol->id)->value('status'), 'Le passage du joueur a regle le vol.');
    }

    public function testClosedLifeformsHideTheFlightsFromTheBox(): void
    {
        $maintenant = (int)Date::now()->timestamp;
        $vol = resolve(LifeformDiscoveryService::class)->launch($this->planetService, $this->cible(), $maintenant);
        $ligne = DiscoveryFleetEvents::rowId((int)$vol->id);
        $this->pinSettings(['lifeforms_enabled' => 0]);

        $boite = $this->getJson(route('fleet.eventbox.fetch'));
        $this->assertSame(0, $boite->json('friendly'), 'Module ferme : le vol ne compte pas.');
        $this->assertSame('', $boite->json('eventText'));
        $this->assertStringNotContainsString('id="eventRow-' . $ligne . '"', (string)$this->get(route('fleet.eventlist.fetch'))->getContent());
        $lignes = json_decode((string)$this->post(route('fleet.eventlist.checkevents'), ['ids' => [$ligne]])->getContent(), true)['rows'];
        $this->assertContains($ligne, $lignes);
    }

    public function testRowIdsLiveOutsideTheFleetMissionSpaceAndReadBothWays(): void
    {
        $this->assertSame(DiscoveryFleetEvents::ROW_OFFSET + 5, DiscoveryFleetEvents::rowId(5));
        $this->assertSame(5, DiscoveryFleetEvents::flightIdOf(DiscoveryFleetEvents::rowId(5)));
        $this->assertNull(DiscoveryFleetEvents::flightIdOf(5), 'Un identifiant de mission de flotte n est pas un vol.');
        $this->assertNull(DiscoveryFleetEvents::flightIdOf(DiscoveryFleetEvents::ROW_OFFSET), 'Le decalage seul n est aucun vol.');
        $this->assertGreaterThan(DB::table('fleet_missions')->max('id') ?? 0, DiscoveryFleetEvents::ROW_OFFSET, 'Le decalage depasse tout identifiant de mission connu.');
    }

    /** Une position libre du systeme de depart, autre que la mienne et que la planete etrangere. */
    private function cible(int $rang = 0): Coordinate
    {
        $depart = $this->planetService->getPlanetCoordinates();
        $occupees = Planet::query()->where('galaxy', $depart->galaxy)->where('system', $depart->system)->pluck('planet')->map(static fn ($p): int => (int)$p)->all();
        $libres = array_values(array_filter(range(1, 15), static fn (int $p): bool => !in_array($p, $occupees, true)));
        $this->assertArrayHasKey($rang, $libres, 'Premisse : une position libre dans mon systeme.');

        return new Coordinate($depart->galaxy, $depart->system, $libres[$rang]);
    }

    /** Une mission de flotte du compte vers la planete etrangere, qui arrive a l instant donne ; rend son identifiant. */
    private function uneMission(int $genre, int $arrivee): int
    {
        $depart = $this->planetService->getPlanetCoordinates();
        $cible = $this->etrangere->getPlanetCoordinates();
        $id = (int)DB::table('fleet_missions')->insertGetId([
            'user_id' => $this->currentUserId,
            'planet_id_from' => $this->planetService->getPlanetId(),
            'galaxy_from' => $depart->galaxy, 'system_from' => $depart->system, 'position_from' => $depart->position,
            'planet_id_to' => $this->etrangere->getPlanetId(),
            'galaxy_to' => $cible->galaxy, 'system_to' => $cible->system, 'position_to' => $cible->position,
            'type_from' => 1, 'type_to' => 1, 'mission_type' => $genre,
            'time_departure' => (int)Date::now()->timestamp, 'time_arrival' => $arrivee, 'light_fighter' => 10,
            'processed' => 0, 'canceled' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->missions[] = $id;

        return $id;
    }
}
