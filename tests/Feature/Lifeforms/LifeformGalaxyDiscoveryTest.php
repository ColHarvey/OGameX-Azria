<?php

namespace Tests\Feature\Lifeforms;

use Illuminate\Support\Facades\Date;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\Discovery\LifeformDiscoveryRules;
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
use OGame\Services\SettingsService;
use Tests\AccountTestCase;
use Tests\Support\PinsSettings;

/**
 * **Les decouvertes depuis la Galaxie, comme le jeu officiel** (releve de Codex, journal §155.24).
 *
 * Le bundle de la Galaxie porte deja l icone ADN et son clic (`getDiscoveryLinkIcon`, `discoverPlanet`) ; il lit
 * `constants.lifeformEnabled`, une mission de type `constants.discover` sur chaque LIGNE (`canSend` vrai ou raison,
 * `discoveryCount`, `link`), et une reponse au vol qui porte `response.success`, `message`, `coordinates` et
 * `discovery`. Ce banc exige ces formes-la, telles que le script les lit — pas un mot.
 */
final class LifeformGalaxyDiscoveryTest extends AccountTestCase
{
    use PinsSettings;

    private int|null $etrangere = null;

    private const int RESEARCH_CENTRE = 11103;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pinSettings(['lifeforms_enabled' => 1, 'economy_speed' => 1]);
        $this->planetAddResources(new Resources(100000, 100000, 100000, 0));
    }

    protected function tearDown(): void
    {
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

    public function testWithoutASpeciesTheGalaxyOffersNoDiscovery(): void
    {
        $page = $this->get('/galaxy');
        $page->assertStatus(200);
        $page->assertSee('"lifeformEnabled": false', false);
        $page->assertSee('"discover": ' . GalaxyDiscoveries::MISSION_TYPE, false);
        $page->assertDontSee('galaxyHeaderDiscoveryListLink', false); // Le lien de secours vers la vue liste n existe plus (journal §160).

        $charge = $this->systemeAjax();
        $this->assertFalse($charge['lifeformEnabled']);
        foreach ($charge['system']['galaxyContent'] as $ligne) {
            $this->assertSame([], $this->missionDeDecouverte($ligne), 'Sans espece, aucune ligne ne propose de vol.');
        }

        // Un vol demande quand meme (adresse forgee) : refuse, et la reponse dit au bundle de griser toutes les icones.
        $refus = $this->postJson(route('lifeforms.discoveries.galaxy'), $this->coordonnees(1) + ['_token' => csrf_token()]);
        $this->assertFalse($refus->json('response.success'));
        $this->assertSame(__('t_ingame.galaxy.discovery_locked'), $refus->json('response.discovery.canSendDiscovery'), 'Sans espece, l etat general est un refus, jamais « vrai ».');
        $this->assertSame(0, $refus->json('response.discovery.discoveryCount'));

        $refus = $this->postJson(route('lifeforms.discoveries.galaxy'), $this->coordonnees(2) + ['_token' => csrf_token()]);
        $refus->assertStatus(200);
        $this->assertFalse($refus->json('response.success'));
        $this->assertNotSame('', (string)$refus->json('response.message'));
        $this->assertIsString($refus->json('newAjaxToken'));
    }

    public function testEveryRowCarriesTheDiscoveryMissionTheBundleReads(): void
    {
        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Humans, (int)Date::now()->timestamp);

        // Sans centre de recherche : l icone existe, grise, avec la raison.
        $sansCentre = $this->systemeAjax();
        $this->assertTrue($sansCentre['lifeformEnabled']);
        $this->assertSame(__('t_ingame.galaxy.discoveries') . ': ' . LifeformDiscoveryRules::QUOTA_PER_DAY, $sansCentre['lifeformDiscoveryHeader']);
        foreach ($sansCentre['system']['galaxyContent'] as $ligne) {
            if ((int)$ligne['position'] > 15) {
                continue;
            }
            $mission = $this->missionDeDecouverte($ligne);
            if (in_array((int)$ligne['position'], $this->mesPositions(), true)) {
                // « Chez soi » d abord, comme le service : quel que soit le centre ou le quota, cette position ne s explore pas (§163).
                $this->assertSame(__('t_lifeforms_ui.refused.own_planet'), $mission['canSend'], 'position ' . $ligne['position']);
                continue;
            }
            $this->assertSame(__('t_ingame.galaxy.discovery_locked'), $mission['canSend'], 'position ' . $ligne['position']);
        }

        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Building, self::RESEARCH_CENTRE, 1);
        // Une planete d un AUTRE joueur dans le systeme : elle reste explorable — seules les miennes ne le sont pas (§162).
        $etrangere = $this->aForeignPlanetInMySystem();
        // Mes colonies d un AUTRE systeme et d une AUTRE galaxie, au numero d une case libre d ici : la case reste offerte (§163).
        $homonyme = $this->positionVisee(1);
        $this->assertContains($homonyme, $this->mesColoniesHomonymes($homonyme), 'Premisse : deux colonies du compte portent ce numero ailleurs.');
        $avecCentre = $this->systemeAjax();
        $lignes = 0;
        $etrangereVue = false;
        foreach ($avecCentre['system']['galaxyContent'] as $ligne) {
            if ((int)$ligne['position'] > 15) {
                continue;
            }
            $lignes++;
            $mission = $this->missionDeDecouverte($ligne);
            $this->assertSame(['missionType', 'canSend', 'discoveryCount', 'link', 'name'], array_keys($mission), 'position ' . $ligne['position']);
            // La Galaxie et le service partagent la regle : la raison « chez soi » est exactement le verdict d isOwnBody().
            $depart = $this->planetService->getPlanetCoordinates();
            $this->assertSame(
                LifeformDiscoveryService::isOwnBody($this->currentUserId, new Coordinate($depart->galaxy, $depart->system, (int)$ligne['position'])),
                $mission['canSend'] === __('t_lifeforms_ui.refused.own_planet'),
                'position ' . $ligne['position'] . ' : la Galaxie et le service ne disent pas la meme chose.'
            );
            if ((int)$ligne['position'] === $homonyme) {
                $this->assertTrue($mission['canSend'], 'Une colonie du compte ailleurs, au meme numero, ne grise pas cette case.');
            }
            if (in_array((int)$ligne['position'], $this->mesPositions(), true)) {
                // On n explore pas chez soi (journal §162) : mes planetes portent l icone grise avec la raison, avant le clic.
                $this->assertSame(__('t_lifeforms_ui.refused.own_planet'), $mission['canSend'], 'Ma propre planete ne s explore pas.');
                continue;
            }
            $this->assertTrue($mission['canSend'], 'position ' . $ligne['position'] . ' : centre ouvert, quota plein, position vierge ou planete d un autre.');
            $etrangereVue = $etrangereVue || (int)$ligne['position'] === $etrangere;
            $this->assertSame(LifeformDiscoveryRules::QUOTA_PER_DAY, $mission['discoveryCount']);
            $this->assertSame(route('lifeforms.discoveries.galaxy'), $mission['link']);
        }
        $this->assertSame(15, $lignes, 'Les quinze positions, planetes et cases vides comprises.');
        $this->assertTrue($etrangereVue, 'Premisse : la planete etrangere est dans le systeme et son vol est offert.');

        $page = $this->get('/galaxy');
        $page->assertSee('"lifeformEnabled": true', false);
        $page->assertSee('id="galaxyHeaderDiscoveryCount">', false);
        $page->assertSee(__('t_ingame.galaxy.discoveries') . ': ' . LifeformDiscoveryRules::QUOTA_PER_DAY, false);
        $page->assertSee('var showDiscoveryWarning = false;', false);
        // L icone ADN vit dans la vue liste ET dans la fiche de la carte tactique (action « decouvrir », journal §160) : le
        // lien de secours vers la vue liste (§155.26) a disparu, la fiche publie le libelle et la raison de l action.
        $page->assertDontSee('galaxyHeaderDiscoveryListLink', false);
        $page->assertSee('"decouvrir":' . json_encode(__('t_ingame.galaxy.discovery_title')), false);
        $page->assertSee('"discovery":' . json_encode(__('t_ingame.galaxy.tactical_reason_discovery')), false);
        $page->assertSee('id="gtViewList"', false); // La vue liste reste a un clic.
        LifeformPagesTest::assertScriptsCarryNoHtmlEntity((string)$page->getContent());
    }

    public function testAClickOnTheIconLaunchesAFlightAndTheAnswerHasTheShapeTheBundleReads(): void
    {
        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Humans, (int)Date::now()->timestamp);
        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Building, self::RESEARCH_CENTRE, 1);
        $metalAvant = $this->planetService->metal()->get();
        $etrangere = $this->aForeignPlanetInMySystem();

        // Un premier vol vers la position 1 : elle devient « en approche ». La reponse d un vol suivant doit dire que les
        // AUTRES positions restent ouvertes (`canSendDiscovery` = vrai) — le bundle grise toutes les icones sinon. Elle
        // le disait d apres la seule position 1, deja prise : toutes les icones du systeme se grisaient apres le second
        // vol (vu au navigateur, journal §160).
        $premier = $this->postJson(route('lifeforms.discoveries.galaxy'), $this->coordonnees(1) + ['_token' => csrf_token()]);
        $this->assertTrue($premier->json('response.success'));
        $metalAvant -= LifeformDiscoveryRules::cost()->metal->get();

        $reponse = $this->postJson(route('lifeforms.discoveries.galaxy'), $this->coordonnees(2) + ['_token' => csrf_token()]);
        $reponse->assertStatus(200);
        $this->assertIsString($reponse->json('newAjaxToken'));
        $this->assertTrue($reponse->json('response.success'));
        $this->assertStringContainsString($this->coordonneesTexte(2), (string)$reponse->json('response.message'));
        $this->assertSame($this->coordonnees(2), $reponse->json('response.coordinates'));
        $this->assertSame(LifeformDiscoveryRules::QUOTA_PER_DAY - 2, $reponse->json('response.discovery.discoveryCount'));
        $this->assertTrue($reponse->json('response.discovery.canSendDiscovery'), 'Les autres positions restent ouvertes, meme quand la position 1 est prise.');
        $this->assertSame(__('t_ingame.galaxy.discoveries') . ': ' . (LifeformDiscoveryRules::QUOTA_PER_DAY - 2), $reponse->json('response.discovery.galaxyHeader.LOCA_GALAXY_LIFEFORM_DISCOVERY_COUNT'));
        // Ce que displayMiniFleetMessage() lit encore : les compteurs de l en-tete de la Galaxie.
        foreach (['slots', 'probes', 'recyclers', 'missiles', 'planetType'] as $clef) {
            $this->assertArrayHasKey($clef, $reponse->json('response'));
        }

        $vol = LifeformDiscovery::query()->where('user_id', $this->currentUserId)->where('position', $this->positionVisee(2))->first();
        $this->assertNotNull($vol, 'Le vol existe : c est le meme service que le formulaire.');
        $this->assertSame('running', $vol->status);
        $this->planetService->reloadPlanet();
        $this->assertEqualsWithDelta($metalAvant - LifeformDiscoveryRules::cost()->metal->get(), $this->planetService->metal()->get(), 1, 'Le cout est debite : le bundle redemande le bandeau pour le montrer.');

        // La position visee est maintenant « en approche », les autres restent ouvertes.
        $charge = $this->systemeAjax();
        foreach ($charge['system']['galaxyContent'] as $ligne) {
            if ((int)$ligne['position'] > 15) {
                continue;
            }
            $mission = $this->missionDeDecouverte($ligne);
            if (in_array((int)$ligne['position'], [$this->positionVisee(1), $this->positionVisee(2)], true)) {
                $this->assertSame(__('t_ingame.galaxy.discovery_underway'), $mission['canSend']);
            } elseif (in_array((int)$ligne['position'], $this->mesPositions(), true)) {
                $this->assertSame(__('t_lifeforms_ui.refused.own_planet'), $mission['canSend']);
            } else {
                $this->assertTrue($mission['canSend'], 'position ' . $ligne['position']);
            }
        }

        // Un vol vers la planete d un autre joueur : accepte — la regle ne vise que les miennes.
        $depart = $this->planetService->getPlanetCoordinates();
        $versUnAutre = $this->postJson(route('lifeforms.discoveries.galaxy'), ['galaxy' => $depart->galaxy, 'system' => $depart->system, 'position' => $etrangere, '_token' => csrf_token()]);
        $this->assertTrue($versUnAutre->json('response.success'), 'La planete d un autre joueur s explore.');
        $this->assertSame(3, LifeformDiscovery::query()->where('user_id', $this->currentUserId)->count());

        // Un vol vers ma propre planete, par l adresse du bundle : refuse par le service, avec sa raison, rien d ecrit, et
        // l etat general des autres positions reste ouvert.
        $depart = $this->planetService->getPlanetCoordinates();
        $chezMoi = $this->postJson(route('lifeforms.discoveries.galaxy'), ['galaxy' => $depart->galaxy, 'system' => $depart->system, 'position' => $depart->position, '_token' => csrf_token()]);
        $this->assertFalse($chezMoi->json('response.success'));
        $this->assertSame(__('t_lifeforms_ui.refused.own_planet'), $chezMoi->json('response.message'));
        $this->assertTrue($chezMoi->json('response.discovery.canSendDiscovery'));
        $this->assertSame(LifeformDiscoveryRules::QUOTA_PER_DAY - 3, $chezMoi->json('response.discovery.discoveryCount'));
        $this->assertSame(3, LifeformDiscovery::query()->where('user_id', $this->currentUserId)->count());

        // Un second vol vers la meme position est refuse — par le service, avec sa raison, sans consommer le quota.
        $refus = $this->postJson(route('lifeforms.discoveries.galaxy'), $this->coordonnees(2) + ['_token' => csrf_token()]);
        $this->assertFalse($refus->json('response.success'));
        $this->assertSame(__('t_lifeforms_ui.refused.recently_explored', ['coordinates' => $this->coordonneesTexte(2)]), $refus->json('response.message'));
        $this->assertSame(LifeformDiscoveryRules::QUOTA_PER_DAY - 3, $refus->json('response.discovery.discoveryCount'));
        $this->assertTrue($refus->json('response.discovery.canSendDiscovery'), 'Un refus propre a une position ne grise pas les autres.');
        $this->assertSame(3, LifeformDiscovery::query()->where('user_id', $this->currentUserId)->count());

        // Un quota epuise grise toutes les icones, et la reponse le dit pour que le script les grise sans recharger.
        LifeformAccount::query()->where('user_id', $this->currentUserId)->update(['discoveries_available' => 1]);
        $dernier = $this->postJson(route('lifeforms.discoveries.galaxy'), $this->coordonnees(3) + ['_token' => csrf_token()]);
        $this->assertTrue($dernier->json('response.success'));
        $this->assertSame(0, $dernier->json('response.discovery.discoveryCount'));
        $this->assertSame(__('t_lifeforms_ui.refused.quota_exhausted'), $dernier->json('response.discovery.canSendDiscovery'));
    }

    /**
     * **Le bouton « Decouvertes » de la barre de la Galaxie fait ce qu il annonce** (Keven, journal §163) : « Lancez une
     * mission de decouverte dans tous les endroits possibles ». Le bundle officiel porte `sendSystemDiscoveryMission()`,
     * qui lit `sendDiscoverSystemUrl` (vide jusqu ici : bouton grise, inerte) et une reponse `success`, `message`,
     * `sentToCoordinates`. Le serveur envoie un vol vers chaque position du systeme affiche que la Galaxie offre — ni
     * chez soi, ni en approche, ni exploree depuis moins de sept jours — dans l ordre des positions, et s arrete au quota
     * ou aux ressources ; le service reste le seul juge de chaque vol.
     */
    public function testTheDiscoveriesButtonOfTheToolbarSendsToEveryOpenPositionOfTheSystem(): void
    {
        // Sans espece : le bouton existe, grise, avec la raison ; l adresse est publiee quand meme (le bundle la lit).
        $page = $this->get('/galaxy');
        $page->assertSee('id="discoverSystemBtn"', false);
        $page->assertSee('var sendDiscoverSystemUrl = ' . json_encode(route('lifeforms.discoveries.galaxy_system')) . ';', false);
        $this->assertMatchesRegularExpression('/id="discoverSystemBtn"[^>]*disabled="disabled"/', (string)$page->getContent(), 'Sans espece, le bouton est grise.');
        $this->assertDoesNotMatchRegularExpression('/id="discoverSystemBtn"[^>]*onclick=/', (string)$page->getContent());

        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Humans, (int)Date::now()->timestamp);
        $depart = $this->planetService->getPlanetCoordinates();
        // Espece choisie, centre absent : la salve ne tente rien et repond la raison generale — celle que la Galaxie montre
        // sur chaque icone et sur le bouton grise, pas le refus du service.
        $sansCentre = $this->postJson(route('lifeforms.discoveries.galaxy_system'), ['galaxy' => $depart->galaxy, 'system' => $depart->system, '_token' => csrf_token()]);
        $this->assertFalse($sansCentre->json('response.success'));
        $this->assertSame(__('t_ingame.galaxy.discovery_locked'), $sansCentre->json('response.message'));
        $this->assertSame([], $sansCentre->json('response.sentToCoordinates'));
        $this->assertSame(0, LifeformDiscovery::query()->where('user_id', $this->currentUserId)->count());

        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Building, self::RESEARCH_CENTRE, 1);
        $etrangere = $this->aForeignPlanetInMySystem();

        // Avec espece et centre : le bouton est actif et branche sur la fonction du bundle.
        $page = $this->get('/galaxy');
        $this->assertMatchesRegularExpression('/id="discoverSystemBtn"[^>]*onclick="sendSystemDiscoveryMission\(\);"/', (string)$page->getContent(), 'Le bouton appelle la fonction officielle.');
        $this->assertDoesNotMatchRegularExpression('/id="discoverSystemBtn"[^>]*disabled=/', (string)$page->getContent());
        $page->assertSee('title="' . e(__('t_ingame.galaxy.discoveries_tooltip')) . '"', false);

        // Ce que la Galaxie offre avant le clic : les positions ouvertes, dans l ordre — ni les miennes.
        $ouvertes = [];
        foreach ($this->systemeAjax()['system']['galaxyContent'] as $ligne) {
            if ((int)$ligne['position'] <= 15 && $this->missionDeDecouverte($ligne)['canSend'] === true) {
                $ouvertes[] = (int)$ligne['position'];
            }
        }
        $this->assertNotContains($depart->position, $ouvertes);
        $this->assertContains($etrangere, $ouvertes, 'Premisse : la planete d un autre est offerte.');
        $this->assertGreaterThanOrEqual(10, count($ouvertes), 'Premisse : la plupart du systeme est ouverte.');
        $metalAvant = $this->planetService->metal()->get();

        $reponse = $this->postJson(route('lifeforms.discoveries.galaxy_system'), ['galaxy' => $depart->galaxy, 'system' => $depart->system, '_token' => csrf_token()]);
        $reponse->assertStatus(200);
        $this->assertIsString($reponse->json('newAjaxToken'));
        $this->assertTrue($reponse->json('response.success'));
        $envoyees = array_map(static fn (array $c): int => (int)$c['position'], $reponse->json('response.sentToCoordinates'));
        $this->assertSame($ouvertes, $envoyees, 'Un vol vers chaque position offerte, dans l ordre, et vers rien d autre.');
        foreach ($reponse->json('response.sentToCoordinates') as $c) {
            $this->assertSame(['galaxy' => $depart->galaxy, 'system' => $depart->system], ['galaxy' => $c['galaxy'], 'system' => $c['system']]);
        }
        $this->assertSame(count($ouvertes), $reponse->json('response.shipsSent'));
        $this->assertSame($depart->galaxy . ':' . $depart->system, $reponse->json('response.coordinates.galaxy') . ':' . $reponse->json('response.coordinates.system'));
        $this->assertStringContainsString((string)count($ouvertes), (string)$reponse->json('response.message'));
        $this->assertStringNotContainsString('t_lifeforms_ui', (string)$reponse->json('response.message'));
        foreach (['slots', 'probes', 'recyclers', 'missiles', 'planetType'] as $clef) {
            $this->assertArrayHasKey($clef, $reponse->json('response'), 'Ce que displayMiniFleetMessage() lit.');
        }
        $this->assertSame(LifeformDiscoveryRules::QUOTA_PER_DAY - count($ouvertes), $reponse->json('response.discovery.discoveryCount'));
        $this->assertTrue($reponse->json('response.discovery.canSendDiscovery'), 'Le quota n est pas epuise : les autres systemes restent ouverts.');
        $this->assertSame(__('t_ingame.galaxy.discoveries') . ': ' . (LifeformDiscoveryRules::QUOTA_PER_DAY - count($ouvertes)), $reponse->json('response.discovery.galaxyHeader.LOCA_GALAXY_LIFEFORM_DISCOVERY_COUNT'));

        $vols = LifeformDiscovery::query()->where('user_id', $this->currentUserId)->where('status', 'running')->orderBy('position')->get();
        $this->assertSame($ouvertes, $vols->map(static fn (LifeformDiscovery $v): int => (int)$v->position)->all(), 'Chaque vol est en base, par le meme service que l icone.');
        $this->assertSame(count($ouvertes), $vols->filter(static fn (LifeformDiscovery $v): bool => $v->odds !== null)->count(), 'Chaque vol porte ses cotes scellees.');
        $this->planetService->reloadPlanet();
        $this->assertEqualsWithDelta($metalAvant - count($ouvertes) * LifeformDiscoveryRules::cost()->metal->get(), $this->planetService->metal()->get(), 1, 'Chaque vol est paye.');

        // Le systeme est desormais entierement en approche : un second clic n envoie rien, et le dit.
        $rien = $this->postJson(route('lifeforms.discoveries.galaxy_system'), ['galaxy' => $depart->galaxy, 'system' => $depart->system, '_token' => csrf_token()]);
        $this->assertFalse($rien->json('response.success'));
        $this->assertSame([], $rien->json('response.sentToCoordinates'));
        $this->assertSame(__('t_lifeforms_ui.discoveries.nothing_to_discover'), $rien->json('response.message'));
        $this->assertSame(count($ouvertes), LifeformDiscovery::query()->where('user_id', $this->currentUserId)->count());

        // Le quota borne la salve : deux vols disponibles, deux vols partent vers le systeme voisin, et la reponse dit le quota epuise.
        LifeformAccount::query()->where('user_id', $this->currentUserId)->update(['discoveries_available' => 2]);
        $voisin = $depart->system < 499 ? $depart->system + 1 : $depart->system - 1;
        $deux = $this->postJson(route('lifeforms.discoveries.galaxy_system'), ['galaxy' => $depart->galaxy, 'system' => $voisin, '_token' => csrf_token()]);
        $this->assertTrue($deux->json('response.success'));
        $this->assertCount(2, $deux->json('response.sentToCoordinates'));
        $this->assertSame(2, $deux->json('response.shipsSent'));
        $this->assertSame(0, $deux->json('response.discovery.discoveryCount'));
        $this->assertSame(__('t_lifeforms_ui.refused.quota_exhausted'), $deux->json('response.discovery.canSendDiscovery'), 'Le bundle grise toutes les icones.');
        $this->assertSame(count($ouvertes) + 2, LifeformDiscovery::query()->where('user_id', $this->currentUserId)->count());

        // Quota a zero : refus avec la raison, rien d ecrit.
        $epuise = $this->postJson(route('lifeforms.discoveries.galaxy_system'), ['galaxy' => $depart->galaxy, 'system' => $voisin, '_token' => csrf_token()]);
        $this->assertFalse($epuise->json('response.success'));
        $this->assertSame(__('t_lifeforms_ui.refused.quota_exhausted'), $epuise->json('response.message'));
        $this->assertSame(count($ouvertes) + 2, LifeformDiscovery::query()->where('user_id', $this->currentUserId)->count());

        // Coordonnees invalides : 422, comme le vol seul.
        $this->postJson(route('lifeforms.discoveries.galaxy_system'), ['galaxy' => $depart->galaxy, 'system' => 500, '_token' => csrf_token()])->assertStatus(422);

        // Module ferme : refus propre, rien d ecrit.
        $this->pinSettings(['lifeforms_enabled' => 0]);
        $ferme = $this->postJson(route('lifeforms.discoveries.galaxy_system'), ['galaxy' => $depart->galaxy, 'system' => $voisin, '_token' => csrf_token()]);
        $this->assertFalse($ferme->json('response.success'));
        $this->assertSame(__('t_ingame.galaxy.discovery_locked'), $ferme->json('response.message'));

        // Le bundle : un seul message pour la salve, les icones des positions parties grisees, le compteur remis.
        $source = str_replace("\r\n", "\n", (string)file_get_contents(resource_path('js/ingame/e7c74974620fa35b197315ebdbb8c2.js')));
        $manifeste = json_decode((string)file_get_contents(public_path('build/manifest.json')), true);
        $this->assertIsArray($manifeste);
        $servi = str_replace("\r\n", "\n", (string)file_get_contents(public_path('build/' . $manifeste['resources/js/ingame.js']['file'])));
        foreach (['source' => $source, 'servi' => $servi] as $nom => $js) {
            $debut = strpos($js, 'function sendSystemDiscoveryMission() {');
            $this->assertNotFalse($debut, "$nom : la fonction officielle existe.");
            $fonction = substr($js, $debut, (int)strpos($js, 'function addToTable(', $debut) - $debut);
            $this->assertSame(1, substr_count($fonction, 'displayMiniFleetMessage('), "$nom : un seul message pour la salve, pas un par position.");
            $this->assertStringContainsString('displayMiniFleetMessage({ ...res.response,', $fonction, "$nom : le message porte les premieres coordonnees (la fonction les exige).");
            $this->assertStringContainsString("          refreshFleetEvents(true);\n", $fonction, "$nom : le deroulant des evenements se redemande de force.");
            $this->assertStringContainsString("document.getElementById('galaxyHeaderDiscoveryCount').innerHTML = res.response.discovery.galaxyHeader.LOCA_GALAXY_LIFEFORM_DISCOVERY_COUNT;", $fonction, "$nom : le compteur de l en-tete est remis.");
            $this->assertStringContainsString('fadeBox(galaxyLoca.discoveryFailed, true);', $fonction, "$nom : une reponse en erreur se dit.");
        }
    }

    /**
     * **Le clic ne fait rien** (Keven, §162) : `discoverPlanet()` du bundle officiel envoie sans gestionnaire d erreur, et
     * une reponse 500, 419 ou 422 restait muette. Le bundle dit l echec (`fadeBox`, phrase publiee par la page), et apres
     * un vol reussi redemande le deroulant des evenements pour que le vol y apparaisse (§163). Temoin sur la source ET
     * sur le bundle servi : un bundle non reconstruit n aurait aucun effet en jeu.
     */
    public function testTheBundleTellsAFailedLaunchAndRefreshesTheEventsAfterOne(): void
    {
        $source = str_replace("\r\n", "\n", (string)file_get_contents(resource_path('js/ingame/e7c74974620fa35b197315ebdbb8c2.js')));
        $manifeste = json_decode((string)file_get_contents(public_path('build/manifest.json')), true);
        $this->assertIsArray($manifeste);
        $servi = str_replace("\r\n", "\n", (string)file_get_contents(public_path('build/' . $manifeste['resources/js/ingame.js']['file'])));

        foreach (['source' => $source, 'servi' => $servi] as $nom => $js) {
            $debut = strpos($js, 'function discoverPlanet(url, data, success = () => {}) {');
            $this->assertNotFalse($debut, "$nom : discoverPlanet() existe.");
            $fonction = substr($js, $debut, (int)strpos($js, 'if (showDiscoveryWarning) {', $debut) - $debut);
            $this->assertStringContainsString("}, \"json\").fail(function () {", $fonction, "$nom : l envoi n a pas de gestionnaire d echec — un 500 reste muet.");
            $this->assertStringContainsString('fadeBox(galaxyLoca.discoveryFailed, true);', $fonction, "$nom : l echec n est pas dit au joueur.");
            $this->assertStringContainsString("        getAjaxEventbox();\n", $fonction);
            // `true` : de force, comme `sendShips` — replie, le deroulant garderait l etat d avant le vol jusqu au rechargement.
            $this->assertStringContainsString("          refreshFleetEvents(true);\n", $fonction, "$nom : le deroulant des evenements n est pas redemande de force apres le vol.");
            $this->assertLessThan(strpos($fonction, 'success();'), strpos($fonction, 'refreshFleetEvents(true);'), "$nom : le deroulant se redemande dans la branche du succes.");
        }

        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Humans, (int)Date::now()->timestamp);
        $page = $this->get('/galaxy');
        $page->assertSee('"discoveryFailed":' . json_encode(__('t_ingame.galaxy.discovery_failed')), false);
        $this->assertNotSame('t_ingame.galaxy.discovery_failed', __('t_ingame.galaxy.discovery_failed'));
    }

    /**
     * Deux colonies du compte qui portent le numero de position donne : l une dans un autre systeme de ma galaxie, l autre
     * dans une autre galaxie, au meme systeme que moi. Rend les numeros crees (le meme, deux fois) pour la premisse.
     *
     * @return array<int, int>
     */
    private function mesColoniesHomonymes(int $position): array
    {
        $depart = $this->planetService->getPlanetCoordinates();
        $galaxies = resolve(SettingsService::class)->numberOfGalaxies();
        $this->assertGreaterThanOrEqual(2, $galaxies, 'Premisse : au moins deux galaxies.');
        $autreGalaxie = $depart->galaxy < $galaxies ? $depart->galaxy + 1 : $depart->galaxy - 1;
        $autreSysteme = $depart->system < 499 ? $depart->system + 1 : $depart->system - 1;
        $faites = [];
        foreach ([[$depart->galaxy, $autreSysteme], [$autreGalaxie, $depart->system]] as [$galaxie, $systeme]) {
            $existante = Planet::query()->where('galaxy', $galaxie)->where('system', $systeme)->where('planet', $position)->first(['user_id']);
            if ($existante !== null) {
                // La base partagee d un processus a pu y poser un corps : le temoin exige le sien, il ne suppose pas.
                $this->assertSame($this->currentUserId, (int)$existante->user_id, "Premisse : la position $galaxie:$systeme:$position est prise par un autre.");
                $faites[] = $position;
                continue;
            }
            Planet::factory()->create(['user_id' => $this->currentUserId, 'galaxy' => $galaxie, 'system' => $systeme, 'planet' => $position]);
            $faites[] = $position;
        }

        return $faites;
    }

    /**
     * Une planete d un autre joueur dans mon systeme de depart, a une position libre ; rend sa position. Idempotent : la
     * premiere creee est reprise.
     */
    private function aForeignPlanetInMySystem(): int
    {
        if ($this->etrangere !== null) {
            return $this->etrangere;
        }
        $depart = $this->planetService->getPlanetCoordinates();
        $existante = Planet::query()->where('galaxy', $depart->galaxy)->where('system', $depart->system)->where('user_id', '!=', $this->currentUserId)->where('planet_type', 1)->orderBy('planet')->first();
        if ($existante !== null) {
            return $this->etrangere = (int)$existante->planet;
        }
        $occupees = Planet::query()->where('galaxy', $depart->galaxy)->where('system', $depart->system)->pluck('planet')->map(static fn ($p): int => (int)$p)->all();
        $libre = collect(range(1, 15))->first(static fn (int $p): bool => !in_array($p, $occupees, true));
        $this->assertNotNull($libre, 'Premisse : une position libre dans mon systeme.');
        $autre = User::factory()->create();
        Planet::factory()->create(['user_id' => $autre->id, 'galaxy' => $depart->galaxy, 'system' => $depart->system, 'planet' => $libre]);

        return $this->etrangere = (int)$libre;
    }

    /**
     * Les positions du systeme de depart qui portent une planete ou une lune du compte (la base d un processus en garde
     * d autres essais) : la planete de depart en fait toujours partie.
     *
     * @return array<int, int>
     */
    private function mesPositions(): array
    {
        $depart = $this->planetService->getPlanetCoordinates();
        $positions = Planet::query()->where('user_id', $this->currentUserId)->where('galaxy', $depart->galaxy)->where('system', $depart->system)->pluck('planet')->map(static fn ($p): int => (int)$p)->all();
        $this->assertContains($depart->position, $positions, 'Premisse : ma planete de depart est dans le systeme.');

        return $positions;
    }

    /**
     * @return array<string, mixed>
     */
    private function systemeAjax(): array
    {
        $depart = $this->planetService->getPlanetCoordinates();
        $reponse = $this->post('/ajax/galaxy', ['_token' => csrf_token(), 'galaxy' => $depart->galaxy, 'system' => $depart->system]);
        $reponse->assertStatus(200);
        $charge = $reponse->json();
        $this->assertIsArray($charge);

        return $charge;
    }

    /**
     * @param array<string, mixed> $ligne
     * @return array<string, mixed>
     */
    private function missionDeDecouverte(array $ligne): array
    {
        $missions = array_values(array_filter($ligne['availableMissions'], static fn (array $m): bool => ($m['missionType'] ?? 0) === GalaxyDiscoveries::MISSION_TYPE));
        $this->assertLessThanOrEqual(1, count($missions), 'Au plus une mission de decouverte par ligne.');

        return $missions[0] ?? [];
    }

    /** Une position du systeme de depart autre que celle de la planete : la n-ieme position libre de ce choix. */
    private function positionVisee(int $rang): int
    {
        // Jamais une position du compte (on n explore pas chez soi, §162), ni celle de la planete etrangere que l essai vise a part.
        $exclues = array_merge($this->mesPositions(), $this->etrangere === null ? [] : [$this->etrangere]);
        $candidates = array_values(array_filter(range(1, 15), static fn (int $p): bool => !in_array($p, $exclues, true)));

        return $candidates[$rang - 1];
    }

    /**
     * @return array{galaxy: int, system: int, position: int}
     */
    private function coordonnees(int $rang): array
    {
        $depart = $this->planetService->getPlanetCoordinates();

        return ['galaxy' => $depart->galaxy, 'system' => $depart->system, 'position' => $this->positionVisee($rang)];
    }

    private function coordonneesTexte(int $rang): string
    {
        $c = $this->coordonnees($rang);

        return $c['galaxy'] . ':' . $c['system'] . ':' . $c['position'];
    }
}
