<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * **La cle a molette en direct est dans ce que le jeu sert**, et a la place ou elle peut marcher.
 *
 * Les essais jsdom (`tests/js/planet-list-construction.test.js`) jugent le comportement du module ; ceux-ci
 * jugent qu il **arrive** jusqu au joueur : un module juste qui manque au bundle commite n a aucun effet en jeu.
 */
class PlanetListConstructionBrowserTest extends TestCase
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

    public function testTheServedBundleCarriesTheModuleItself(): void
    {
        $source = (string)file_get_contents(resource_path('js/ingame/planet-list-construction.js'));

        $this->assertStringContainsString(
            rtrim(str_replace("\r\n", "\n", $source), "\n"),
            str_replace("\r\n", "\n", $this->bundleServi()),
            'Le bundle servi ne porte pas le module de la liste des planetes tel qu il est ecrit : la cle ne bougerait pas en jeu.'
        );
    }

    /**
     * **Le module vient apres le bandeau dans la liste de construction.** Il ecoute l annonce du bandeau ; place
     * en dernier, une exception de sa part ne peut empecher aucun autre fichier du bundle de s executer.
     */
    public function testTheBuildListCarriesTheModuleAfterTheResourceBar(): void
    {
        $vite = str_replace("\r\n", "\n", (string)file_get_contents(base_path('vite.config.js')));

        if (preg_match('/const ingameScripts = \[(.*?)\n\]/s', $vite, $m) !== 1) {
            $this->fail('La liste ingameScripts est introuvable dans vite.config.js.');
        }

        preg_match_all("/'([^']+)'/", $m[1], $f);
        $liste = $f[1];

        $module = array_search('resources/js/ingame/planet-list-construction.js', $liste, true);
        $bandeau = array_search('resources/js/ingame/resource-bar.js', $liste, true);

        $this->assertIsInt($module, 'planet-list-construction.js n est pas dans la liste de construction : le bundle de la CI ne le portera pas.');
        $this->assertIsInt($bandeau);
        $this->assertGreaterThan($bandeau, $module);
        $this->assertSame(count($liste) - 1, $module, 'Le module n est plus le dernier fichier du bundle.');
    }
}
