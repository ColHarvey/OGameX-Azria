<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OGame\Enums\AllianceClass;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Alliance;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\AllianceClassService;
use OGame\Services\AllianceService;
use OGame\Services\InitialUserDataService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;

/**
 * Les deux analyses de systeme entier : Phalange pour les Chercheurs, espionnage pour les Guerriers.
 *
 * ## Ce que chaque moitie eprouve
 *
 * La Phalange a un point de decision **au serveur** : un systeme entier pour le prix d un seul
 * relevé est un avantage, et un bouton absent ne protege rien. Le banc pose donc la question au
 * serveur, sans classe puis avec, et compare le relevé d une analyse ordinaire a celui de l analyse
 * de systeme — **une planete contre deux, au meme cout**.
 *
 * L espionnage de systeme, lui, ne fait que declencher d un geste les envois que le joueur peut deja
 * declencher un par un : il n y a aucun droit a verifier au serveur, et ce qui s eprouve est donc la
 * page — le bouton est offert a la classe qui le promet, refuse aux autres.
 */
class AllianceClassSystemScanTest extends FleetDispatchTestCase
{
    protected int $missionType = 3; // Transport

    protected string $missionName = 'Transport';

    protected function setUp(): void
    {
        parent::setUp();

        resolve(SettingsService::class)->set('alliance_classes_enabled', '1');
    }

    protected function tearDown(): void
    {
        resolve(SettingsService::class)->set('alliance_classes_enabled', '0');

        parent::tearDown();
    }

    protected function basicSetup(): void
    {
        $this->planetAddUnit('small_cargo', 20);
        $this->planetAddResources(new Resources(5000, 5000, 100000, 0));
    }

    /**
     * **Sans la classe, le serveur refuse.** Le bouton absent de la page ne prouve rien : la requete
     * se rejoue a la main.
     */
    public function testTheSystemScanIsRefusedWithoutTheResearchersClass(): void
    {
        $this->switchToMoon();
        $this->laPhalangeSurLaLune();

        $reponse = $this->postJson(route('phalanx.scan-system'), [
            'galaxy' => $this->moonService->getPlanetCoordinates()->galaxy,
            'system' => $this->moonService->getPlanetCoordinates()->system,
        ])->assertStatus(200);

        $this->assertTrue((bool)$reponse->json('is_error'), 'Le serveur accepte une analyse de systeme sans la classe qui la donne.');
        $this->assertSame(__('t_ingame.galaxy.system_phalanx_not_allowed'), $reponse->json('error_message'));
    }

    /**
     * **Une alliance de Guerriers n ouvre pas la Phalange de systeme.**
     *
     * Sans ce temoin, un droit rendu a toute alliance passerait le precedent.
     */
    public function testAWarriorsAllianceDoesNotOpenTheSystemScan(): void
    {
        $this->switchToMoon();
        $this->laPhalangeSurLaLune();
        $this->uneAllianceDeClasse(AllianceClass::WARRIORS);

        $reponse = $this->postJson(route('phalanx.scan-system'), [
            'galaxy' => $this->moonService->getPlanetCoordinates()->galaxy,
            'system' => $this->moonService->getPlanetCoordinates()->system,
        ])->assertStatus(200);

        $this->assertTrue((bool)$reponse->json('is_error'), 'Une alliance de Guerriers analyse un systeme entier, que sa classe ne promet pas.');
    }

    /**
     * **Un seul relevé couvre le systeme, et coute le prix d un seul relevé.**
     *
     * Deux voisins du meme systeme recoivent chacun un transport. L analyse ordinaire d un seul
     * d entre eux en voit un ; l analyse du systeme les voit tous les deux, pour le meme debit.
     */
    public function testAResearchersAllianceScansEveryPlanetOfTheSystemForOneScanCost(): void
    {
        $this->switchToFirstPlanet();
        $this->basicSetup();

        [$premier, $second] = $this->deuxVoisinsDansLeSysteme();

        $this->envoyerUnTransportVers($premier);
        $this->envoyerUnTransportVers($second);

        $this->switchToMoon();
        $this->laPhalangeSurLaLune();
        $this->uneAllianceDeClasse(AllianceClass::RESEARCHERS);

        $coordonnees = $this->moonService->getPlanetCoordinates();

        $ordinaire = $this->postJson(route('phalanx.scan'), [
            'galaxy' => $coordonnees->galaxy,
            'system' => $coordonnees->system,
            'position' => $premier->getPlanetCoordinates()->position,
        ])->assertStatus(200);

        $this->assertSame(1, (int)$ordinaire->json('fleet_count'), 'L analyse ordinaire ne voit pas exactement le mouvement du voisin vise.');

        $this->moonService->reloadPlanet();
        $avant = (int)$this->moonService->deuterium()->get();

        $systeme = $this->postJson(route('phalanx.scan-system'), [
            'galaxy' => $coordonnees->galaxy,
            'system' => $coordonnees->system,
        ])->assertStatus(200);

        $this->assertNull($systeme->json('is_error'), 'L analyse de systeme est refusee a une alliance de Chercheurs.');
        $this->assertSame(2, (int)$systeme->json('fleet_count'), 'L analyse de systeme ne voit pas les mouvements des deux voisins.');

        $this->moonService->reloadPlanet();
        $this->assertSame(
            $avant - 5000,
            (int)$this->moonService->deuterium()->get(),
            'L analyse de systeme ne coute pas exactement le prix d un seul relevé.'
        );
    }

    /**
     * **La page offre chaque bouton a la classe qui le promet, et a elle seule.**
     *
     * Le temoin vise la **forme du code** — l appel pose sur le bouton — et non un mot de la page :
     * le libelle des deux boutons existe de toute facon, grise.
     */
    public function testTheGalaxyPageOpensEachSystemButtonOnlyForItsClass(): void
    {
        $sansAlliance = (string)$this->get('/galaxy')->assertStatus(200)->getContent();

        $this->assertStringNotContainsString('onclick="spyWholeSystem();"', $sansAlliance, 'L espionnage de systeme est offert sans alliance.');
        $this->assertStringNotContainsString('onclick="scanSystemWithPhalanx();"', $sansAlliance, 'La Phalange de systeme est offerte sans alliance.');

        $this->uneAllianceDeClasse(AllianceClass::WARRIORS);
        $guerriers = (string)$this->get('/galaxy')->assertStatus(200)->getContent();

        $this->assertStringContainsString('onclick="spyWholeSystem();"', $guerriers, 'Une alliance de Guerriers n a pas l espionnage de systeme.');
        $this->assertStringNotContainsString('onclick="scanSystemWithPhalanx();"', $guerriers, 'Une alliance de Guerriers a la Phalange de systeme, que sa classe ne promet pas.');
    }

    /**
     * Une Phalange de niveau 1 sur la lune, et de quoi payer un relevé.
     *
     * Niveau 1 vaut une portee de zero systeme : le systeme de la lune, et lui seul. C est
     * exactement ce que le banc vise.
     */
    private function laPhalangeSurLaLune(): void
    {
        $this->moonService->setObjectLevel(ObjectService::getObjectByMachineName('sensor_phalanx')->id, 1);
        $this->moonService->addResources(new Resources(0, 0, 20000, 0));
        $this->moonService->reloadPlanet();
    }

    /**
     * Deux planetes neuves **dans le systeme de la lune**, appartenant a deux joueurs crees ici.
     *
     * Elles doivent etre dans ce systeme : une Phalange de niveau 1 n atteint rien d autre. Et elles
     * doivent etre neuves, faute de quoi un mouvement laisse par un essai voisin s ajouterait au
     * comptage.
     *
     * @return array{0: PlanetService, 1: PlanetService}
     */
    private function deuxVoisinsDansLeSysteme(): array
    {
        $coordonnees = $this->planetService->getPlanetCoordinates();
        $voisins = [];

        for ($position = 1; $position <= 15 && count($voisins) < 2; $position++) {
            $occupee = DB::table('planets')
                ->where('galaxy', $coordonnees->galaxy)
                ->where('system', $coordonnees->system)
                ->where('planet', $position)
                ->exists();

            if (!$occupee) {
                $voisins[] = $this->unePlaneteDUnJoueurNeuf(new Coordinate($coordonnees->galaxy, $coordonnees->system, $position));
            }
        }

        $this->assertCount(2, $voisins, 'Le systeme du banc n a pas deux cases libres pour ses voisins.');

        return [$voisins[0], $voisins[1]];
    }

    private function unePlaneteDUnJoueurNeuf(Coordinate $ou): PlanetService
    {
        $etranger = User::factory()->create(['username' => 'phalange_' . Str::random(16)]);

        // Le crochet `created` du modele promeut le premier utilisateur d'une transaction en admin,
        // et l'analyse ecarte les planetes de l'administration.
        if ($etranger->hasRole('admin')) {
            $etranger->removeRole('admin');
            $etranger->save();
        }

        resolve(InitialUserDataService::class)->createFor($etranger);

        $joueur = resolve(PlayerServiceFactory::class)->make((int)$etranger->id, true);
        $planete = resolve(PlanetServiceFactory::class)->createAdditionalPlanetForPlayer($joueur, $ou);

        $this->assertNotNull($planete, 'La planete du voisin neuf n a pas ete creee.');

        return $planete;
    }

    private function envoyerUnTransportVers(PlanetService $cible): void
    {
        $flotte = new UnitCollection();
        $flotte->addUnit(ObjectService::getUnitObjectByMachineName('small_cargo'), 1);

        $this->dispatchFleet($cible->getPlanetCoordinates(), $flotte, new Resources(0, 0, 0, 0), PlanetType::Planet);
    }

    private function uneAllianceDeClasse(AllianceClass $classe): Alliance
    {
        $alliance = resolve(AllianceService::class)->createAlliance(
            $this->currentUserId,
            'SY' . substr(md5(uniqid((string)mt_rand(), true)), 0, 5),
            'Systeme ' . substr(md5(uniqid((string)mt_rand(), true)), 0, 8)
        );

        $this->assertNotNull($alliance);

        // Une alliance fondee a l instant n'a pas les quatorze jours qui offrent le premier choix.
        DB::table('users')->where('id', $this->currentUserId)->increment('dark_matter', AllianceClass::PRICE_IN_DARK_MATTER);

        resolve(AllianceClassService::class)->choose(
            User::query()->findOrFail($this->currentUserId),
            $alliance,
            $classe
        );

        return $alliance;
    }
}
