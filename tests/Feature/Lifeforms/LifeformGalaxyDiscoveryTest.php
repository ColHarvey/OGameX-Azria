<?php

namespace Tests\Feature\Lifeforms;

use Illuminate\Support\Facades\Date;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\Discovery\LifeformDiscoveryRules;
use OGame\Lifeforms\Presentation\GalaxyDiscoveries;
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
use OGame\Models\Resources;
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

        $charge = $this->systemeAjax();
        $this->assertFalse($charge['lifeformEnabled']);
        foreach ($charge['system']['galaxyContent'] as $ligne) {
            $this->assertSame([], $this->missionDeDecouverte($ligne), 'Sans espece, aucune ligne ne propose de vol.');
        }

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
            $this->assertSame(__('t_ingame.galaxy.discovery_locked'), $mission['canSend'], 'position ' . $ligne['position']);
        }

        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Building, self::RESEARCH_CENTRE, 1);
        $avecCentre = $this->systemeAjax();
        $lignes = 0;
        foreach ($avecCentre['system']['galaxyContent'] as $ligne) {
            if ((int)$ligne['position'] > 15) {
                continue;
            }
            $lignes++;
            $mission = $this->missionDeDecouverte($ligne);
            $this->assertSame(['missionType', 'canSend', 'discoveryCount', 'link', 'name'], array_keys($mission), 'position ' . $ligne['position']);
            $this->assertTrue($mission['canSend'], 'position ' . $ligne['position'] . ' : centre ouvert, quota plein, position vierge.');
            $this->assertSame(LifeformDiscoveryRules::QUOTA_PER_DAY, $mission['discoveryCount']);
            $this->assertSame(route('lifeforms.discoveries.galaxy'), $mission['link']);
        }
        $this->assertSame(15, $lignes, 'Les quinze positions, planetes et cases vides comprises.');

        $page = $this->get('/galaxy');
        $page->assertSee('"lifeformEnabled": true', false);
        $page->assertSee('id="galaxyHeaderDiscoveryCount">', false);
        $page->assertSee(__('t_ingame.galaxy.discoveries') . ': ' . LifeformDiscoveryRules::QUOTA_PER_DAY, false);
        $page->assertSee('var showDiscoveryWarning = false;', false);
        LifeformPagesTest::assertScriptsCarryNoHtmlEntity((string)$page->getContent());
    }

    public function testAClickOnTheIconLaunchesAFlightAndTheAnswerHasTheShapeTheBundleReads(): void
    {
        resolve(LifeformInstallationService::class)->chooseSpecies($this->currentUserId, Species::Humans, (int)Date::now()->timestamp);
        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Building, self::RESEARCH_CENTRE, 1);
        $metalAvant = $this->planetService->metal()->get();

        $reponse = $this->postJson(route('lifeforms.discoveries.galaxy'), $this->coordonnees(2) + ['_token' => csrf_token()]);
        $reponse->assertStatus(200);
        $this->assertIsString($reponse->json('newAjaxToken'));
        $this->assertTrue($reponse->json('response.success'));
        $this->assertStringContainsString($this->coordonneesTexte(2), (string)$reponse->json('response.message'));
        $this->assertSame($this->coordonnees(2), $reponse->json('response.coordinates'));
        $this->assertSame(LifeformDiscoveryRules::QUOTA_PER_DAY - 1, $reponse->json('response.discovery.discoveryCount'));
        $this->assertTrue($reponse->json('response.discovery.canSendDiscovery'), 'Les autres positions restent ouvertes.');
        $this->assertSame(__('t_ingame.galaxy.discoveries') . ': ' . (LifeformDiscoveryRules::QUOTA_PER_DAY - 1), $reponse->json('response.discovery.galaxyHeader.LOCA_GALAXY_LIFEFORM_DISCOVERY_COUNT'));
        // Ce que displayMiniFleetMessage() lit encore : les compteurs de l en-tete de la Galaxie.
        foreach (['slots', 'probes', 'recyclers', 'missiles', 'planetType'] as $clef) {
            $this->assertArrayHasKey($clef, $reponse->json('response'));
        }

        $vol = LifeformDiscovery::query()->where('user_id', $this->currentUserId)->first();
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
            if ((int)$ligne['position'] === $this->positionVisee(2)) {
                $this->assertSame(__('t_ingame.galaxy.discovery_underway'), $mission['canSend']);
            } else {
                $this->assertTrue($mission['canSend'], 'position ' . $ligne['position']);
            }
        }

        // Un second vol vers la meme position est refuse — par le service, avec sa raison, sans consommer le quota.
        $refus = $this->postJson(route('lifeforms.discoveries.galaxy'), $this->coordonnees(2) + ['_token' => csrf_token()]);
        $this->assertFalse($refus->json('response.success'));
        $this->assertSame(__('t_lifeforms_ui.refused.recently_explored', ['coordinates' => $this->coordonneesTexte(2)]), $refus->json('response.message'));
        $this->assertSame(LifeformDiscoveryRules::QUOTA_PER_DAY - 1, $refus->json('response.discovery.discoveryCount'));
        $this->assertSame(1, LifeformDiscovery::query()->where('user_id', $this->currentUserId)->count());

        // Un quota epuise grise toutes les icones, et la reponse le dit pour que le script les grise sans recharger.
        LifeformAccount::query()->where('user_id', $this->currentUserId)->update(['discoveries_available' => 1]);
        $dernier = $this->postJson(route('lifeforms.discoveries.galaxy'), $this->coordonnees(3) + ['_token' => csrf_token()]);
        $this->assertTrue($dernier->json('response.success'));
        $this->assertSame(0, $dernier->json('response.discovery.discoveryCount'));
        $this->assertSame(__('t_lifeforms_ui.refused.quota_exhausted'), $dernier->json('response.discovery.canSendDiscovery'));
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
        $depart = $this->planetService->getPlanetCoordinates()->position;
        $candidates = array_values(array_filter(range(1, 15), static fn (int $p): bool => $p !== $depart));

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
