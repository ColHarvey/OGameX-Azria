<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use OGame\Facades\AppUtil;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\Resources;
use Tests\AccountTestCase;

/**
 * Le bandeau des ressources, resynchronise sans changer de page.
 *
 * ## Une source, deux lecteurs
 *
 * `/ajax/resourcebox` rend l objet que `reloadResources()` recoit ; la page en recoit un au
 * chargement. Les deux viennent de `ResourceBarViewModel`, et ce banc l exige des deux cotes :
 * la charge de l ajax est comparee **champ par champ** a ce que la planete porte, et la page doit
 * embarquer exactement la meme charge — sinon le bandeau sauterait a la premiere synchronisation.
 *
 * ## Une lecture, et rien qu une lecture
 *
 * Ce point d entree ne passe pas par `globalgame` et n ecrit rien. Deux essais le tiennent, parce
 * que c est une regle de jeu : une veille toutes les trente secondes qui ferait avancer
 * `planets.time_last_update` allumerait en permanence l etoile d activite que les autres joueurs
 * lisent dans la Galaxie pour choisir leur cible, et `users.time` dirait « en ligne » un joueur
 * parti depuis une heure. La production, elle, est **projetee** : le joueur voit ce qu une page
 * lui montrerait, sans qu un octet ne bouge.
 *
 * ## La planete de la page, pas celle du compte
 *
 * `users.planet_current` est une colonne du compte : ouvrir une seconde planete dans un autre
 * onglet la change pour tout le monde. La demande nomme donc la planete que la page affiche.
 */
class ResourceBarTest extends AccountTestCase
{
    public function testTheAjaxSaysWhatThePlanetHoldsFieldByField(): void
    {
        $this->planetAddResources(new Resources(1234, 567, 89, 0));

        $charge = $this->getJson('/ajax/resourcebox')->assertStatus(200)->json();

        $this->planetService->reloadPlanet();
        $planete = $this->planetService;

        $this->assertIsArray($charge['resources']);
        $this->assertSame(['metal', 'crystal', 'deuterium', 'energy', 'darkmatter'], array_keys($charge['resources']));

        foreach (['metal', 'crystal', 'deuterium'] as $nom) {
            $ressource = $planete->{$nom}();
            $stockage = $planete->{$nom . 'Storage'}();
            $fait = $charge['resources'][$nom];

            $this->assertSame(['amount', 'storage', 'baseProduction', 'production', 'tooltip', 'classesListItem'], array_keys($fait), $nom);
            $this->assertEqualsWithDelta($ressource->get(), $fait['amount'], 0.001, $nom . ' : le montant n est pas celui de la planete.');
            $this->assertEqualsWithDelta($stockage->get(), $fait['storage'], 0.001, $nom . ' : le stockage n est pas celui de la planete.');
            // Le revenu de base fait bouger metal et cristal ; le deuterium, lui, attend un synthetiseur.
            if ($nom !== 'deuterium') {
                $this->assertGreaterThan(0, $fait['production'], $nom . ' : la production par seconde est nulle, le compteur ne bougerait pas.');
            }

            $this->assertStringContainsString('|<table class="resourceTooltip">', $fait['tooltip']);
            $this->assertStringContainsString($ressource->getFormattedLong(), $fait['tooltip'], $nom . ' : l infobulle ne porte pas le montant.');
            $this->assertStringContainsString($stockage->getFormattedLong(), $fait['tooltip'], $nom . ' : l infobulle ne porte pas le stockage.');
        }

        $this->assertEqualsWithDelta($planete->getMetalProductionPerSecond(), $charge['resources']['metal']['production'], 0.000001);
        $this->assertEqualsWithDelta($planete->getCrystalProductionPerSecond(), $charge['resources']['crystal']['production'], 0.000001);
        $this->assertEqualsWithDelta($planete->getDeuteriumProductionPerSecond(), $charge['resources']['deuterium']['production'], 0.000001);

        $this->assertSame(['amount', 'tooltip', 'classesListItem'], array_keys($charge['resources']['energy']));
        $this->assertEqualsWithDelta($planete->energy()->get(), $charge['resources']['energy']['amount'], 0.001);
        $this->assertStringContainsString(__('t_ingame.layout.res_energy') . '|', $charge['resources']['energy']['tooltip']);

        $this->assertSame(['amount', 'tooltip', 'classesListItem'], array_keys($charge['resources']['darkmatter']));
        $joueur = resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);
        $this->assertEqualsWithDelta($joueur->getDarkMatter(), $charge['resources']['darkmatter']['amount'], 0.001, 'La matiere noire du bandeau n est pas celle du joueur.');
        $this->assertStringContainsString(AppUtil::formatNumber($joueur->getDarkMatter()), $charge['resources']['darkmatter']['tooltip'], 'L infobulle de la matiere noire ne porte pas son montant.');

        $this->assertSame(['resources', 'techs', 'honorScore'], array_keys($charge));
        $this->assertSame([], $charge['techs']);
    }

    /**
     * Les signes et les couleurs que le joueur lit au survol.
     *
     * Une production qui monte est verte et prefixee d un `+` ; une production negative — le
     * deuterium que brule une centrale a fusion — est rouge et porte son signe. Une consommation
     * d energie non nulle est rouge et negative. Sans ces temoins, inverser l un des trois
     * afficherait une perte en vert sans qu aucun essai ne tombe.
     */
    public function testTheTooltipsCarryTheSignsAndTheMarksThePlayerReads(): void
    {
        /*
         * **L etat est construit, pas espere.** Sur un compte neuf, production et consommation
         * valent zero : le juste et le faux coincideraient et les trois regles ne seraient jamais
         * exercees. Une centrale a fusion consomme du deuterium ; c est ainsi qu une production
         * NEGATIVE devient observable. Les statistiques de production ne se recalculent qu au
         * passage d une page — d ou le chargement avant toute mesure.
         */
        $this->planetSetObjectLevel('metal_mine', 10);
        $this->planetSetObjectLevel('solar_plant', 10);
        $this->planetSetObjectLevel('fusion_plant', 5);
        $this->planetAddResources(new Resources(100000, 100000, 100000, 0));
        $this->get('/overview')->assertStatus(200);
        $this->planetService->reloadPlanet();

        // Les premisses, mesurees : sans elles ce banc ne prouverait rien.
        $this->assertGreaterThan(0, $this->planetService->getMetalProductionPerHour(), 'La premisse tombe : le metal ne monte pas.');
        $this->assertLessThan(0, $this->planetService->getDeuteriumProductionPerHour(), 'La premisse tombe : la fusion ne consomme pas de deuterium, la regle du signe negatif n est pas exercee.');
        $this->assertGreaterThan(0, $this->planetService->energyProduction()->get(), 'La premisse tombe : aucune energie produite.');
        $this->assertGreaterThan(0, $this->planetService->energyConsumption()->get(), 'La premisse tombe : aucune energie consommee.');

        $charge = $this->getJson('/ajax/resourcebox?body=' . $this->planetService->getPlanetId())->assertStatus(200)->json();

        // Une production qui monte : verte, avec son plus.
        $this->assertStringContainsString('<span class="undermark">+', $charge['resources']['metal']['tooltip'], 'Une production qui monte n est pas marquee comme telle.');

        // Une production qui descend : rouge, sans plus.
        $this->assertStringContainsString('<span class="overmark">-', $charge['resources']['deuterium']['tooltip'], 'Le deuterium que brule la fusion est affiche comme un gain.');
        $this->assertStringNotContainsString('<span class="undermark">+', $charge['resources']['deuterium']['tooltip']);

        // L energie : produite en vert et prefixee, consommee en rouge et negative.
        $energie = $charge['resources']['energy']['tooltip'];
        $this->assertStringContainsString('<span class="undermark">+', $energie, 'La production d energie n est pas marquee comme un gain.');
        $this->assertStringContainsString('<span class="overmark">-', $energie, 'La consommation d energie est affichee sans son signe ni sa couleur.');
    }

    public function testThePageBootsTheTickerWithTheVeryChargeTheAjaxServes(): void
    {
        $this->planetAddResources(new Resources(4321, 0, 0, 0));

        $ajax = (string)$this->getJson('/ajax/resourcebox?body=' . $this->planetService->getPlanetId())->assertStatus(200)->getContent();
        $page = (string)$this->get('/overview')->assertStatus(200)->getContent();

        /*
         * `@json` echappe `<`, `>`, `&`, `'` et `"` en hexadecimal pour vivre dans un `<script>` ;
         * la reponse JSON, elle, ne les echappe pas. Comparer les objets decodes, pas les textes.
         */
        if (preg_match('/reloadResources\((\{.*?\})\);/s', $page, $m) !== 1) {
            $this->fail('La page n amorce plus le compteur avec un objet.');
        }

        $attendu = json_decode($ajax, true);
        $amorce = json_decode($m[1], true);

        $this->assertIsArray($amorce, 'L objet d amorce n est pas du JSON : le gabarit le construit encore a la main.');
        $this->assertSame($attendu, $amorce, 'La page et l ajax ne disent pas la meme chose : le bandeau sauterait a la premiere synchronisation.');
        $this->assertStringContainsString('data-resourcebox-url="' . route('resourcebox.ajax') . '"', $page, 'Le bandeau ne porte pas l adresse que le module lit.');
        $this->assertStringContainsString('<meta name="ogame-planet-id" content="' . $this->planetService->getPlanetId() . '"', $page, 'La page ne dit pas quelle planete elle affiche : le module ne pourrait pas la nommer.');
    }

    /**
     * **Rien n est ecrit, et l activite ne bouge pas.** C est la regle qui autorise une veille
     * toutes les trente secondes : sans elle, tout joueur ayant un onglet ouvert paraitrait actif
     * en permanence dans la Galaxie de tous les autres.
     */
    public function testTheAjaxWritesNothingAndNeverMarksActivity(): void
    {
        $this->get('/overview')->assertStatus(200);

        $planeteId = $this->planetService->getPlanetId();
        $avant = (array)DB::table('planets')->where('id', $planeteId)->first();
        $joueurAvant = (array)DB::table('users')->where('id', $this->currentUserId)->first();

        // Dix minutes passent : une page ecrirait tout ; l ajax ne doit rien ecrire.
        $this->travel(10)->minutes();

        $charge = $this->getJson('/ajax/resourcebox?body=' . $planeteId)->assertStatus(200)->json();

        $apres = (array)DB::table('planets')->where('id', $planeteId)->first();
        $joueurApres = (array)DB::table('users')->where('id', $this->currentUserId)->first();

        $this->assertSame($avant, $apres, 'La demande du bandeau a ecrit sur la planete : l etoile d activite de la Galaxie s allumerait toutes les trente secondes.');
        $this->assertSame($joueurAvant, $joueurApres, 'La demande du bandeau a ecrit sur le compte : le joueur paraitrait « en ligne » en permanence.');

        // Et pourtant le joueur voit sa production : elle est projetee, pas persistee.
        $this->assertGreaterThan((float)$avant['metal'], $charge['resources']['metal']['amount'], 'La production de dix minutes n est pas projetee : le bandeau resterait fige.');
        $this->assertEqualsWithDelta((float)$avant['metal'] + $this->planetService->getMetalProductionPerHour() / 6, $charge['resources']['metal']['amount'], 1.0, 'La projection ne suit pas la production du jeu.');
    }

    /**
     * Ce qu un transport qui se pose, un butin qui rentre ou un raid subi ecrivent, le bandeau le
     * voit au coup d apres — sans changement de page.
     */
    public function testADeliveryIsSeenBetweenTwoCallsWithoutAnyPageChange(): void
    {
        $avant = $this->getJson('/ajax/resourcebox')->json('resources.metal.amount');

        $this->planetAddResources(new Resources(5000, 0, 0, 0));

        $apres = $this->getJson('/ajax/resourcebox')->json('resources.metal.amount');

        $this->assertEqualsWithDelta($avant + 5000, $apres, 0.001, 'Le second appel ne voit pas ce que le serveur a ecrit entre les deux : la reponse est mise en cache quelque part.');
    }

    /**
     * **La planete de la page l emporte sur celle du compte**, et la demander ne la change pas.
     */
    public function testTheBarFollowsThePlanetThePageShowsNotTheAccountOne(): void
    {
        $premiereId = $this->planetService->getPlanetId();
        $this->planetAddResources(new Resources(7777, 0, 0, 0));
        $premiere = $this->getJson('/ajax/resourcebox?body=' . $premiereId)->json('resources.metal.amount');

        // Un autre onglet ouvre la seconde planete : la colonne du compte change pour tout le monde.
        $this->switchToSecondPlanet();
        $secondeId = (int)DB::table('users')->where('id', $this->currentUserId)->value('planet_current');
        $this->assertNotSame($premiereId, $secondeId, 'La premisse tombe : le compte n a pas change de planete courante.');

        // L onglet reste sur la premiere planete, et son bandeau aussi.
        $this->assertEqualsWithDelta(
            $premiere,
            $this->getJson('/ajax/resourcebox?body=' . $premiereId)->json('resources.metal.amount'),
            1.0,
            'Le bandeau d un onglet a bascule sur la planete ouverte dans un autre onglet.'
        );

        // Et demander le bandeau n a pas change la planete courante du compte.
        $this->assertSame($secondeId, (int)DB::table('users')->where('id', $this->currentUserId)->value('planet_current'), 'La demande du bandeau a change la planete courante du compte.');

        // Sans parametre, c est la planete courante du compte qui repond — l ancien comportement.
        $this->assertEqualsWithDelta(
            $this->getJson('/ajax/resourcebox?body=' . $secondeId)->json('resources.metal.amount'),
            $this->getJson('/ajax/resourcebox')->json('resources.metal.amount'),
            1.0
        );
    }

    /**
     * Un corps qui n appartient pas au joueur ne donne rien : la planete courante repond.
     */
    public function testAForeignBodyIsRefusedAndTheCurrentOneAnswers(): void
    {
        $etranger = $this->getNearbyForeignPlanet();

        $attendu = $this->getJson('/ajax/resourcebox')->json('resources.metal.amount');
        $obtenu = $this->getJson('/ajax/resourcebox?body=' . $etranger->getPlanetId())->json('resources.metal.amount');

        $this->assertEqualsWithDelta($attendu, $obtenu, 1.0, 'Nommer la planete d un autre joueur rend autre chose que la sienne.');
    }

    /**
     * Le HTML du bandeau lit les memes faits : « presque plein » a partir de 90 %, « plein » a
     * la capacite. Trois niveaux, pour que le seuil soit prouve des deux cotes.
     */
    public function testThePageMarksAnAlmostFullStorageAndAFullOne(): void
    {
        $stockage = $this->planetService->metalStorage()->get();
        $aMoitie = (int)floor($stockage * 0.6) - (int)$this->planetService->metal()->get();

        $this->planetAddResources(new Resources($aMoitie, 0, 0, 0));
        $page = (string)$this->get('/overview')->assertStatus(200)->getContent();
        $this->assertMatchesRegularExpression('/<span id="resources_metal"\s+class=""/', $page, 'A 60 % le metal porte une marque.');

        $this->planetAddResources(new Resources((int)ceil($stockage * 0.35), 0, 0, 0));
        $page = (string)$this->get('/overview')->assertStatus(200)->getContent();
        $this->assertMatchesRegularExpression('/<span id="resources_metal"\s+class="middlemark"/', $page, 'A 95 % le metal n est pas marque « presque plein ».');

        $this->planetAddResources(new Resources((int)ceil($stockage * 0.1), 0, 0, 0));
        $page = (string)$this->get('/overview')->assertStatus(200)->getContent();
        $this->assertMatchesRegularExpression('/<span id="resources_metal"\s+class="overmark"/', $page, 'Au-dela de la capacite le metal n est pas marque « plein ».');
    }

    public function testAGuestGetsNothing(): void
    {
        $this->post('/logout');
        $this->assertGuest();

        $this->getJson('/ajax/resourcebox')->assertStatus(401);
    }

    /**
     * **La route reste hors du moteur de jeu**, et c est une regle, pas un detail d implementation.
     *
     * Une veille toutes les trente secondes qui traverserait `globalgame` ferait avancer
     * `planets.time_last_update` et `users.time` : tout joueur ayant un onglet ouvert paraitrait
     * actif en permanence dans la Galaxie des autres, et « en ligne » indefiniment. L essai voisin
     * mesure l effet (aucune ecriture) ; celui-ci nomme la cause, pour qu un jour ou la route
     * serait deplacee dans un autre groupe, le temoin dise **pourquoi** il tombe.
     *
     * `auth` y figure aussi — mais mesure faite le 12 septembre 2026 : le retirer du groupe ne
     * change rien, l intergiciel reste sur la route par un autre chemin et un invite recoit
     * toujours 401. Ce n est donc pas cette ligne qui garde la porte ; c est
     * `testAGuestGetsNothing` qui l etablit, et lui seul.
     */
    public function testTheRouteStaysOutOfTheGameEngine(): void
    {
        $route = Route::getRoutes()->getByName('resourcebox.ajax');

        $this->assertNotNull($route, 'La route du bandeau n existe plus.');
        $this->assertNotContains('globalgame', $route->gatherMiddleware(), 'La route repasse par le moteur de jeu : chaque veille marquerait le joueur actif dans la Galaxie et en ligne.');
    }
}
