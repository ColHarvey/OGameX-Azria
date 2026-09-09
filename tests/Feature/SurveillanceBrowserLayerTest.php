<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Ce que le navigateur execute reellement de la couche de surveillance.
 *
 * ## Le bundle servi, jamais la source
 *
 * Le conteneur du jeu n a pas Node : ce qui s execute est le fichier commite sous
 * `public/build/assets/`, designe par le manifeste. Une regle ajoutee dans `resources/js/` et jamais
 * reconstruite serait verte pour un joueur qui ne la subit pas. Ce temoin ouvre donc le fichier que
 * le manifeste nomme.
 *
 * ## Des formes de code, jamais des mots
 *
 * Chaque motif cherche ce qui ne peut etre que du code — une affectation, un appel avec ses
 * parentheses. Chercher « surveillance » trouverait aussi bien un commentaire, et un commentaire ne
 * s execute pas.
 */
class SurveillanceBrowserLayerTest extends TestCase
{
    /**
     * Le bundle que le manifeste designe pour le jeu.
     */
    private function bundleServi(): string
    {
        $manifeste = json_decode((string)file_get_contents(public_path('build/manifest.json')), true);

        $this->assertIsArray($manifeste);
        $this->assertArrayHasKey('resources/js/ingame.js', $manifeste);

        $chemin = public_path('build/' . $manifeste['resources/js/ingame.js']['file']);
        $this->assertFileExists($chemin, 'Le manifeste designe un bundle qui n est pas commite : le jeu ne servirait rien.');

        return (string)file_get_contents($chemin);
    }

    /**
     * La liste des contacts est **remplacee** a chaque reponse acceptee.
     */
    public function testTheServedBundleReplacesTheContactList(): void
    {
        $bundle = $this->bundleServi();

        $this->assertStringContainsString(
            'contactsDeSurveillance = Array.isArray(reponse.surveillance)',
            $bundle,
            'Le bundle servi ne consomme pas la couche de surveillance : le travail n a aucun effet en jeu.'
        );
    }

    /**
     * Elle n est jamais fusionnee : une fusion garderait ce qu une revocation doit retirer.
     */
    public function testTheServedBundleNeverMergesTheContactList(): void
    {
        $bundle = $this->bundleServi();

        foreach (['contactsDeSurveillance.push(', 'contactsDeSurveillance.concat(', 'contactsDeSurveillance.unshift('] as $fusion) {
            $this->assertStringNotContainsString(
                $fusion,
                $bundle,
                'Le bundle fusionne les contacts (' . $fusion . ') : un contact que le serveur ne renvoie plus resterait a l ecran.'
            );
        }
    }

    /**
     * Le rejet des reponses tardives existe toujours, et la surveillance en depend.
     *
     * **La regle concurrente doit exister.** La surveillance ne porte volontairement pas son propre
     * garde-fou : deux mecanismes de rejet auraient diverge. Si celui de la couche des flottes
     * disparaissait, une ancienne reponse pourrait reintroduire ce qu une revocation vient d oter —
     * et ce temoin deviendrait un vert vide sans cette verification.
     */
    public function testTheLateAnswerGuardTheSurveillanceReliesOnStillExists(): void
    {
        $bundle = $this->bundleServi();

        $this->assertStringContainsString(
            'jeton !== jetonDeSysteme',
            $bundle,
            'Le rejet des reponses tardives a disparu : la surveillance s appuyait sur lui.'
        );

        // Et il protege bien la reponse ou la surveillance est lue : les deux vivent dans la meme
        // fonction, donc le garde precede l affectation.
        $garde = strpos($bundle, 'jeton !== jetonDeSysteme');
        $affectation = strpos($bundle, 'contactsDeSurveillance = Array.isArray(reponse.surveillance)');

        $this->assertIsInt($garde);
        $this->assertIsInt($affectation);
        $this->assertLessThan($affectation, $garde, 'La surveillance est lue avant le rejet des reponses tardives.');
    }

    /**
     * La couche est reconstruite, et elle est dessinee apres avoir ete remplacee.
     */
    public function testTheLayerIsRebuiltAfterTheListIsReplaced(): void
    {
        $bundle = $this->bundleServi();

        $this->assertStringContainsString('gtSurveillanceLayer', $bundle);
        $this->assertStringContainsString('function dessinerLaSurveillance(', $bundle);

        $affectation = strpos($bundle, 'contactsDeSurveillance = Array.isArray(reponse.surveillance)');
        $appel = strpos($bundle, 'dessinerLaSurveillance(carte);');

        $this->assertIsInt($affectation);
        $this->assertIsInt($appel);
        $this->assertGreaterThan($affectation, $appel, 'La couche est dessinee avant d avoir recu la liste neuve.');
    }
}
