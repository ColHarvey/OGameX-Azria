<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Le carreau du Reseau de surveillance, dans le bundle que le jeu sert reellement.
 *
 * ## Pourquoi lire le bundle construit, et pas la source
 *
 * Le conteneur du jeu part de `php:8.5-fpm` et n a pas Node : ce qui est servi est le fichier
 * **commite** sous `public/build/assets/`, designe par le manifeste. Une regle ajoutee dans
 * `resources/css/` et jamais reconstruite n a donc aucun effet en jeu, et un temoin qui lirait la
 * source serait vert pour un joueur qui ne voit rien. Ce temoin ouvre le fichier que le manifeste
 * nomme.
 *
 * ## Pourquoi verifier l ordre, et pas seulement la presence
 *
 * La regle du carreau et celle de la feuille partagee ont la meme specificite (0,2,0) : a egalite,
 * c est la derniere ecrite qui gagne. Une regle presente mais placee avant serait battue en
 * silence — le cas s est produit trois fois sur ce depot. Le temoin compare donc les positions, et
 * exige que la regle concurrente existe encore : si la feuille partagee disparaissait, la
 * comparaison n aurait plus de sens et ce temoin deviendrait un vert vide.
 */
class SurveillanceNetworkTileTest extends TestCase
{
    /**
     * La feuille de sprites que tout le jeu lit, et que le carreau doit remplacer.
     */
    private const string FEUILLE_PARTAGEE = '/img/content/sprite.jpg';

    /**
     * L image propre au carreau.
     */
    private const string IMAGE_DU_CARREAU = '/img/objects/buildings/surveillance_network_tile.jpg';

    /**
     * Le bundle que le manifeste designe, lu tel que le serveur le sert.
     */
    private function bundleServi(): string
    {
        $manifeste = json_decode((string)file_get_contents(public_path('build/manifest.json')), true);

        $this->assertIsArray($manifeste);
        $this->assertArrayHasKey('resources/css/ingame.css', $manifeste);

        $fichier = $manifeste['resources/css/ingame.css']['file'];
        $chemin = public_path('build/' . $fichier);

        $this->assertFileExists($chemin, 'The manifest names a bundle that is not committed: the game would serve nothing.');

        return (string)file_get_contents($chemin);
    }

    /**
     * Le bundle servi porte la regle, avec son image.
     */
    public function testTheServedBundleCarriesTheTileRule(): void
    {
        $bundle = $this->bundleServi();

        // **La forme de code, jamais le mot.** « surveillance » apparait aussi dans des textes ;
        // `.sprite.surveillanceNetwork{` ne peut etre qu un selecteur suivi de son bloc. Le
        // minifieur ecrit `:before`, pas `::before` — chercher la forme source ne trouverait rien.
        $this->assertStringContainsString('.sprite.surveillanceNetwork{', $bundle);
        $this->assertStringContainsString(self::IMAGE_DU_CARREAU, $bundle);
    }

    /**
     * La regle remplace les trois proprietes que la feuille impose.
     */
    public function testTheRuleOverridesTheThreePropertiesTheSheetImposes(): void
    {
        $bundle = $this->bundleServi();

        $debut = strpos($bundle, '.sprite.surveillanceNetwork{');
        $this->assertIsInt($debut);

        $fin = strpos($bundle, '}', $debut);
        $this->assertIsInt($fin);

        $bloc = substr($bundle, $debut, $fin - $debut);

        // Sans la taille de fond, les regles de dimension etireraient une image de 200 px aux
        // dimensions de la feuille entiere et n en montreraient qu un coin.
        $this->assertStringContainsString('background-image:', $bloc);
        $this->assertStringContainsString('background-position:', $bloc);
        $this->assertStringContainsString('background-size:', $bloc);
    }

    /**
     * La regle gagne : elle vient apres celle qu elle doit battre, qui existe toujours.
     */
    public function testTheRuleComesAfterTheSharedSheetItMustBeat(): void
    {
        $bundle = $this->bundleServi();

        $feuille = strpos($bundle, self::FEUILLE_PARTAGEE);
        $carreau = strpos($bundle, '.sprite.surveillanceNetwork{');

        // **La regle concurrente doit exister.** Si la feuille partagee disparaissait, il n y
        // aurait plus rien a battre et cette comparaison serait un vert vide.
        $this->assertIsInt($feuille, 'The shared sprite sheet is gone: this witness would prove nothing.');
        $this->assertIsInt($carreau);

        $this->assertGreaterThan(
            $feuille,
            $carreau,
            'The tile rule is written before the shared sheet: at equal specificity it loses, and the tile shows the sheet.'
        );
    }

    /**
     * L image que la regle designe est bien livree.
     */
    public function testTheImageTheRulePointsAtIsShipped(): void
    {
        $this->assertFileExists(public_path(ltrim(self::IMAGE_DU_CARREAU, '/')));
    }
}
