<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Ce que le navigateur execute reellement du bandeau des ressources.
 *
 * ## Le bundle servi, jamais la source
 *
 * Le conteneur du jeu n a pas Node : ce qui s execute est le fichier commite sous
 * `public/build/assets/`, designe par le manifeste. Un module ecrit dans `resources/js/` et jamais
 * reconstruit serait vert au banc jsdom pour un joueur qui ne le recoit pas. Ces temoins ouvrent le
 * fichier que le manifeste nomme, et le gabarit qui le charge.
 *
 * ## L identite, pas la presence
 *
 * Chercher quelques ancres ne ferme que l absence totale : une correction du module — une garde
 * retiree, une regle changee — laisserait les ancres en place et le joueur recevrait l ancien
 * code. Le temoin principal exige donc que le bundle contienne la **source entiere**, normalisee
 * en LF comme la construction le fait.
 */
class ResourceBarBrowserTest extends TestCase
{
    private function bundleServi(): string
    {
        $manifeste = json_decode((string)file_get_contents(public_path('build/manifest.json')), true);

        $this->assertIsArray($manifeste);
        $this->assertArrayHasKey('resources/js/ingame.js', $manifeste);

        $chemin = public_path('build/' . $manifeste['resources/js/ingame.js']['file']);
        $this->assertFileExists($chemin, 'Le manifeste designe un bundle qui n est pas commite : le jeu ne servirait rien.');

        return (string)file_get_contents($chemin);
    }

    private function gabarit(): string
    {
        return (string)file_get_contents(resource_path('views/ingame/layouts/main.blade.php'));
    }

    /**
     * Le bundle servi porte le module **tel qu il est ecrit**, a l octet.
     */
    public function testTheServedBundleCarriesTheModuleItself(): void
    {
        $source = (string)file_get_contents(resource_path('js/ingame/resource-bar.js'));

        /*
         * Le depot est lu en CRLF sur un poste Windows, la construction lit en LF : c est la forme
         * LF qui se retrouve dans le bundle. On normalise pour **chercher**, jamais pour reecrire.
         */
        $this->assertStringContainsString(
            rtrim(str_replace("\r\n", "\n", $source), "\n"),
            $this->bundleServi(),
            'Le bundle servi ne porte pas le module tel qu il est ecrit : le jeu sert une version perimee du bandeau.'
        );
    }

    /**
     * Le module remplace le talon du bundle d origine, et il vient apres lui.
     *
     * Le bundle est une concatenation : deux definitions de `getAjaxResourcebox` y vivent — la
     * declaration vide du fichier d origine, hissee, et l affectation du module, executee. C est
     * l affectation qui gagne, parce qu elle s execute ; ce temoin exige qu elle existe **et** que
     * le module suive le fichier d origine, pour que la lecture du bundle dise la meme chose que
     * son execution.
     */
    public function testTheServedBundleReplacesTheStubAfterTheOriginalFile(): void
    {
        $bundle = $this->bundleServi();

        $talon = strpos($bundle, 'function getAjaxResourcebox(callback) {');
        $module = strpos($bundle, 'window.getAjaxResourcebox = synchroniser;');

        $this->assertIsInt($talon, 'Le talon d origine a disparu : ce temoin ne juge plus la bonne chose.');
        $this->assertIsInt($module, 'Le bundle servi ne porte pas le module du bandeau : le jeu telecharge toujours la Vue generale pour rien.');
        $this->assertGreaterThan($talon, $module, 'Le module precede le fichier d origine dans le bundle.');
        $this->assertSame(1, substr_count($bundle, 'window.getAjaxResourcebox = synchroniser;'));
    }

    /**
     * Les declencheurs, dans le module servi : l annonce de flotte, la veille, le retour d onglet.
     */
    public function testTheServedModuleListensWatchesAndWakesOnTabReturn(): void
    {
        $bundle = $this->bundleServi();
        $debut = strpos($bundle, 'var CADENCE_DE_LA_VEILLE = 30000;');

        $this->assertIsInt($debut, 'La cadence de la veille n est pas celle annoncee (trente secondes).');

        $module = substr($bundle, $debut);

        $this->assertStringContainsString("window.Echo.private('galaxy.player.' + id)", $module, 'Le module ne s abonne pas au canal des mouvements du joueur.');
        $this->assertStringContainsString(".listen('.FleetMovementChanged', function () {", $module, 'Le module n ecoute pas les mouvements de flotte.');
        $this->assertStringContainsString('veille = window.setInterval(function () {', $module, 'Aucune veille n est armee : sans Echo, plus rien ne bouge.');
        $this->assertStringContainsString("document.addEventListener('visibilitychange', function () {", $module, 'Le retour sur l onglet ne resynchronise pas.');
        $this->assertStringContainsString('window.jQuery.getJSON(adresse(), corps === null ? {} : { body: corps })', $module, 'La demande ne nomme pas la planete de la page : un second onglet ferait afficher une autre planete.');
    }

    /**
     * Le gabarit publie l adresse **sur le bandeau** et ne redefinit plus la fonction.
     */
    public function testTheLayoutPublishesTheAddressOnTheBarAndNoLongerOverridesTheFunction(): void
    {
        $gabarit = $this->gabarit();

        $this->assertStringNotContainsString('function getAjaxResourcebox(', $gabarit, 'Le gabarit redefinit getAjaxResourcebox : sa version, executee apres le bundle, ecrase le module.');
        $this->assertStringNotContainsString('fetchResources', $gabarit, 'L ancien appel qui telechargeait la Vue generale est encore la.');
        $this->assertStringContainsString('id="resourcesbarcomponent" class="" data-resourcebox-url="{{ route(\'resourcebox.ajax\') }}"', $gabarit, 'Le bandeau ne porte pas l adresse : une variable globale enfermee un jour dans une fermeture ferait taire le module sans un mot.');
        $this->assertStringContainsString('reloadResources(@json($resourceBarTicker));', $gabarit, 'Le compteur n est plus amorce par la classe partagee : la page et l ajax divergeraient.');
        $this->assertStringNotContainsString('"honorScore": 11', $gabarit, 'L objet construit a la main est encore dans le gabarit.');
    }

    /**
     * La liste de construction porte le module, apres le fichier d origine et apres Echo.
     */
    public function testTheBuildListCarriesTheModuleAfterTheOriginalFileAndEcho(): void
    {
        $vite = (string)file_get_contents(base_path('vite.config.js'));

        if (preg_match('/const ingameScripts = \[(.*?)\n\]/s', $vite, $m) !== 1) {
            $this->fail('La liste ingameScripts est introuvable dans vite.config.js.');
        }

        preg_match_all("/'([^']+)'/", $m[1], $f);
        $liste = $f[1];

        $module = array_search('resources/js/ingame/resource-bar.js', $liste, true);
        $origine = array_search('resources/js/ingame/e7c74974620fa35b197315ebdbb8c2.js', $liste, true);
        $echo = array_search('resources/js/ingame/echo.js', $liste, true);

        $this->assertIsInt($module, 'resource-bar.js n est pas dans la liste de construction : le bundle de la CI ne le portera pas.');
        $this->assertIsInt($origine);
        $this->assertIsInt($echo);
        $this->assertGreaterThan($origine, $module);
        $this->assertGreaterThan($echo, $module);
    }
}
