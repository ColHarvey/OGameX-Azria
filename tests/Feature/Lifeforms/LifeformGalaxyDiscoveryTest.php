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
use OGame\Models\User;
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
            $this->assertSame(__('t_ingame.galaxy.discovery_locked'), $mission['canSend'], 'position ' . $ligne['position']);
        }

        resolve(LifeformLevels::class)->setLevel($this->currentPlanetId, LifeformKind::Building, self::RESEARCH_CENTRE, 1);
        // Une planete d un AUTRE joueur dans le systeme : elle reste explorable — seules les miennes ne le sont pas (§162).
        $etrangere = $this->aForeignPlanetInMySystem();
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
