<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OGame\Enums\AllianceClass;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameConstants\UniverseConstants;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Alliance;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\AllianceClassService;
use OGame\Services\AllianceService;
use OGame\Services\InitialUserDataService;
use OGame\Services\ObjectService;
use OGame\Services\PhalanxService;
use OGame\Services\PlanetService;
use OGame\Services\SettingsService;
use stdClass;
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

        $versLePremier = $this->envoyerUnTransportVers($premier);
        $versLeSecond = $this->envoyerUnTransportVers($second);

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

        /*
         * **Les deux voisins, par identifiant.** Compter seulement laisserait passer un relevé qui
         * verrait deux mouvements etrangers a l essai et aucun des siens.
         */
        $releve = (string)$systeme->json('content_html');
        $this->assertStringContainsString('id="eventRow-' . $versLePremier . '"', $releve, 'L analyse de systeme ne voit pas le transport vers le premier voisin.');
        $this->assertStringContainsString('id="eventRow-' . $versLeSecond . '"', $releve, 'L analyse de systeme ne voit pas le transport vers le second voisin.');

        /*
         * **Et exactement ce que les releves ordinaires verraient, planete par planete.** La base
         * survit entre les essais d un passage : le systeme peut porter les restes d un autre essai.
         * Le compte attendu se construit sur le systeme reel, avec la regle d exclusion redite ici —
         * planetes seulement, habitees, ni au joueur qui analyse, ni a l administration.
         */
        $administrateurs = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', 'admin')
            ->where('model_has_roles.model_type', User::class)
            ->pluck('model_id')
            ->map(static fn (mixed $id): int => (int)$id)
            ->all();

        $analysables = DB::table('planets')
            ->where('galaxy', $coordonnees->galaxy)
            ->where('system', $coordonnees->system)
            ->where('planet_type', PlanetType::Planet->value)
            ->whereNotNull('user_id')
            ->where('user_id', '!=', $this->currentUserId)
            ->pluck('user_id', 'id')
            ->filter(static fn (mixed $proprietaire): bool => !in_array((int)$proprietaire, $administrateurs, true))
            ->keys()
            ->map(static fn (mixed $id): int => (int)$id)
            ->all();

        $this->assertContains($premier->getPlanetId(), $analysables, 'La premisse manque : le premier voisin n est pas analysable.');
        $this->assertContains($second->getPlanetId(), $analysables, 'La premisse manque : le second voisin n est pas analysable.');

        $attendus = 0;

        foreach ($analysables as $planete) {
            $attendus += count(resolve(PhalanxService::class)->scanPlanetFleets($planete, $this->currentUserId));
        }

        $this->assertSame(
            $attendus,
            (int)$systeme->json('fleet_count'),
            'L analyse de systeme ne rend pas exactement la somme des releves ordinaires des planetes du systeme.'
        );

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

        // Le libelle qu un envoi rapide en echec affiche : sans lui, l echec resterait muet.
        $this->assertStringContainsString('"LOCA_FLEET_SEND_FAILED":', $sansAlliance, 'La page ne publie pas le libelle d un envoi en echec.');

        $this->uneAllianceDeClasse(AllianceClass::WARRIORS);
        $guerriers = (string)$this->get('/galaxy')->assertStatus(200)->getContent();

        $this->assertStringContainsString('onclick="spyWholeSystem();"', $guerriers, 'Une alliance de Guerriers n a pas l espionnage de systeme.');
        $this->assertStringNotContainsString('onclick="scanSystemWithPhalanx();"', $guerriers, 'Une alliance de Guerriers a la Phalange de systeme, que sa classe ne promet pas.');

        // **La page ne redefinit pas la fonction du module.** Une declaration dans un script en ligne
        // cree une globale qui ecrase celle du module, ou l inverse, selon l ordre de chargement.
        $this->assertStringNotContainsString('function spyWholeSystem(', $guerriers, 'Le gabarit redefinit l espionnage de systeme par-dessus le module.');
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

    /**
     * Envoyer un petit transporteur vers cette planete, et rendre l identifiant de la mission creee.
     */
    private function envoyerUnTransportVers(PlanetService $cible): int
    {
        $avant = (int)(FleetMission::query()->max('id') ?? 0);

        $flotte = new UnitCollection();
        $flotte->addUnit(ObjectService::getUnitObjectByMachineName('small_cargo'), 1);

        $this->dispatchFleet($cible->getPlanetCoordinates(), $flotte, new Resources(0, 0, 0, 0), PlanetType::Planet);

        $mission = FleetMission::query()
            ->where('id', '>', $avant)
            ->where('user_id', $this->currentUserId)
            ->where('planet_id_to', $cible->getPlanetId())
            ->orderByDesc('id')
            ->value('id');

        $this->assertNotNull($mission, 'Le transport n a cree aucune mission vers la planete visee.');

        return (int)$mission;
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

    /**
     * **Un systeme sans planete a analyser se refuse gratuitement.**
     *
     * L'analyse d'une seule planete refuse une case vide sans rien prendre ; celle du systeme
     * faisait payer 5 000 de deuterium pour un relevé vide (audit du 12 septembre 2026).
     */
    public function testAnEmptySystemIsRefusedWithoutCharging(): void
    {
        $this->switchToMoon();
        $this->laPhalangeSurLaLune();

        // Niveau 5 : vingt-quatre systemes de portee, de quoi trouver un systeme sans aucun corps.
        $this->moonService->setObjectLevel(ObjectService::getObjectByMachineName('sensor_phalanx')->id, 5);
        $this->moonService->reloadPlanet();
        $this->uneAllianceDeClasse(AllianceClass::RESEARCHERS);

        $lune = $this->moonService->getPlanetCoordinates();
        $vide = null;

        for ($ecart = 1; $ecart <= 24 && $vide === null; $ecart++) {
            foreach ([$lune->system + $ecart, $lune->system - $ecart] as $systeme) {
                if ($systeme < 1 || $systeme > UniverseConstants::MAX_SYSTEM_COUNT) {
                    continue;
                }

                if (!DB::table('planets')->where('galaxy', $lune->galaxy)->where('system', $systeme)->exists()) {
                    $vide = $systeme;

                    break;
                }
            }
        }

        $this->assertNotNull($vide, 'Aucun systeme vide a portee : le temoin ne peut pas se poser.');

        $avant = (int)DB::table('planets')->where('id', $this->moonService->getPlanetId())->value('deuterium');

        $reponse = $this->postJson(route('phalanx.scan-system'), [
            'galaxy' => $lune->galaxy,
            'system' => $vide,
        ])->assertStatus(200);

        $this->assertTrue((bool)$reponse->json('is_error'), 'Un systeme vide a ete analyse.');
        $this->assertSame(__('t_ingame.galaxy.system_phalanx_nothing_to_scan'), $reponse->json('error_message'));
        $this->assertSame(
            $avant,
            (int)DB::table('planets')->where('id', $this->moonService->getPlanetId())->value('deuterium'),
            'Un systeme sans rien a analyser a ete facture.'
        );
    }

    /**
     * **Un debit perdu devant une ecriture concurrente est un refus lisible, et ne revele rien.**
     *
     * Le controle du solde lit la valeur chargee au debut de la requete ; une ecriture concurrente
     * — un envoi de flotte depuis la meme lune — la fait tomber avant le debit. Autrefois : relevé
     * calcule, puis exception au debit, et une erreur 500. L'ecriture concurrente est injectee au
     * moment exact ou le controleur liste les corps a analyser, apres le controle et avant le debit.
     */
    public function testADebitLostToAConcurrentWriteIsRefusedCleanlyAndRevealsNothing(): void
    {
        $this->switchToFirstPlanet();
        $this->basicSetup();

        [$premier] = $this->deuxVoisinsDansLeSysteme();
        $this->envoyerUnTransportVers($premier);

        $this->switchToMoon();
        $this->laPhalangeSurLaLune();
        $this->uneAllianceDeClasse(AllianceClass::RESEARCHERS);

        $luneId = $this->moonService->getPlanetId();

        // Un objet et non un booleen capture : PHPStan tiendrait le booleen pour constant.
        $concurrente = new stdClass();
        $concurrente->ecrite = false;

        DB::listen(function (QueryExecuted $requete) use ($concurrente, $luneId): void {
            if ($concurrente->ecrite) {
                return;
            }

            $sql = str_replace('`', '"', $requete->sql);

            if (str_contains($sql, 'from "planets"') && str_contains($sql, '"user_id" is not null')) {
                $concurrente->ecrite = true;
                DB::table('planets')->where('id', $luneId)->update(['deuterium' => 0]);
            }
        });

        $coordonnees = $this->moonService->getPlanetCoordinates();

        $reponse = $this->postJson(route('phalanx.scan-system'), [
            'galaxy' => $coordonnees->galaxy,
            'system' => $coordonnees->system,
        ]);

        $this->assertTrue($concurrente->ecrite, 'La premisse manque : l ecriture concurrente n a pas eu lieu.');
        $reponse->assertStatus(200);
        $this->assertTrue((bool)$reponse->json('is_error'), 'Le relevé a ete rendu alors que le debit a echoue.');
        $this->assertSame(__('t_ingame.galaxy.system_phalanx_not_enough_deuterium'), $reponse->json('error_message'));
        $this->assertNull($reponse->json('content_html'), 'Des mouvements ont ete reveles a un joueur qui n a rien paye.');
        $this->assertSame(0, (int)DB::table('planets')->where('id', $luneId)->value('deuterium'));
    }

    /**
     * **Le relevé sans mouvement se dit dans la langue du joueur.**
     *
     * La phrase etait ecrite en anglais dans la vue ; l'analyse de systeme la rendait plus souvent.
     * Temoin de forme sur la vue, et de fond sur la clef : elle existe en francais.
     */
    public function testThePhalanxReportSaysNoMovementInThePlayersLanguage(): void
    {
        $vue = (string)file_get_contents(base_path('resources/views/ingame/phalanx/content.blade.php'));

        $this->assertStringNotContainsString('>No fleet movements detected at this location.<', $vue);
        $this->assertStringContainsString("{{ __('t_ingame.galaxy.phalanx_no_movement') }}", $vue);
        $this->assertNotSame(
            't_ingame.galaxy.phalanx_no_movement',
            trans('t_ingame.galaxy.phalanx_no_movement', [], 'fr'),
            'La clef n existe pas en francais : le joueur lirait son nom.'
        );
    }
}
