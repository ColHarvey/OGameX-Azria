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
     * Le corps d une fonction du bundle, decoupe jusqu a la fonction suivante.
     *
     * **Comparer des positions sur tout le fichier ne prouve rien.** Un meme appel figure a
     * plusieurs endroits — `dessinerLaSurveillance(carte);` vit aussi dans l invalidation, definie
     * bien plus haut — et `strpos` prend le premier venu, qui n est pas celui qu on juge. Mesure
     * faite : deux assertions d ordre comparaient des sites d appel differents, et c est l essai qui
     * l a dit en rougissant.
     */
    private function corpsDe(string $bundle, string $fonction, string $suivante): string
    {
        $debut = strpos($bundle, 'function ' . $fonction . '(');
        $this->assertIsInt($debut, 'La fonction ' . $fonction . ' est absente du bundle servi.');

        $fin = strpos($bundle, 'function ' . $suivante . '(', $debut);
        $this->assertIsInt($fin, 'La fonction ' . $suivante . ' ne suit pas ' . $fonction . ' : le decoupage serait faux.');

        return substr($bundle, $debut, $fin - $debut);
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
        $corps = $this->corpsDe($bundle, 'chargerLesFlottes', 'redemanderLeSysteme');
        $garde = strpos($corps, 'jeton !== jetonDeSysteme');
        $affectation = strpos($corps, 'contactsDeSurveillance = Array.isArray(reponse.surveillance)');

        $this->assertIsInt($garde);
        $this->assertIsInt($affectation);
        $this->assertLessThan($affectation, $garde, 'La surveillance est lue avant le rejet des reponses tardives.');
    }

    /**
     * La couche est masquee **au depart** de la demande, pas seulement a son retour.
     *
     * ## Ce que le remplacement a reception ne couvre pas
     *
     * Si la reponse tarde, ce qui vient de devenir interdit reste a l ecran pendant tout le vol de
     * la requete. L invalidation doit donc preceder l envoi. Le temoin compare les positions dans le
     * bundle servi : masquer apres l appel reseau serait une autre regle, verte a la lecture et
     * fausse a l execution.
     */
    public function testTheLayerIsClearedBeforeTheRequestLeaves(): void
    {
        $bundle = $this->bundleServi();

        $this->assertStringContainsString('function invaliderLaSurveillance(', $bundle);

        $corps = $this->corpsDe($bundle, 'chargerLesFlottes', 'redemanderLeSysteme');

        $invalidation = strpos($corps, 'invaliderLaSurveillance(carte);');
        $requete = strpos($corps, 'window.jQuery.getJSON(galaxyFleetsUrl');

        $this->assertIsInt($invalidation, 'Rien n invalide la couche dans la demande : une reponse tardive laisserait l interdit a l ecran.');
        $this->assertIsInt($requete);
        $this->assertLessThan($requete, $invalidation, 'La couche est masquee apres le depart de la requete, donc trop tard.');
    }

    /**
     * Une connexion perdue masque, et son retour resynchronise — sans changement d onglet.
     *
     * Une coupure reseau ne produit aucun `visibilitychange` : l onglet reste visible. Ce sont donc
     * les evenements de connexion qui doivent agir, et sur les deux sources — celle du navigateur et
     * celle du diffuseur, aucune ne voyant ce que l autre voit.
     */
    public function testALostConnectionHidesAndItsReturnResynchronises(): void
    {
        $bundle = $this->bundleServi();

        $this->assertStringContainsString('function surveillerLaConnexion(', $bundle);

        // Les deux sources : la carte reseau du navigateur, et le canal du diffuseur.
        $this->assertStringContainsString("window.addEventListener('offline'", $bundle, 'La perte de reseau du navigateur n est pas ecoutee.');
        $this->assertStringContainsString("window.addEventListener('online'", $bundle, 'Le retour de reseau du navigateur n est pas ecoute.');
        $this->assertStringContainsString("connexion.bind('connected'", $bundle, 'Le retour du canal du diffuseur n est pas ecoute.');
        $this->assertStringContainsString("'unavailable', 'disconnected', 'failed'", $bundle, 'La perte du canal du diffuseur n est pas ecoutee.');

        // La perte masque, et le retour redemande : les deux gestes existent et sont distincts.
        $this->assertStringContainsString('connexionPerdue = true;', $bundle);
        $this->assertStringContainsString('connexionPerdue = false;', $bundle);
    }

    /**
     * La couche est reconstruite, et elle est dessinee apres avoir ete remplacee.
     */
    public function testTheLayerIsRebuiltAfterTheListIsReplaced(): void
    {
        $bundle = $this->bundleServi();

        $this->assertStringContainsString('gtSurveillanceLayer', $bundle);
        $this->assertStringContainsString('function dessinerLaSurveillance(', $bundle);

        $corps = $this->corpsDe($bundle, 'chargerLesFlottes', 'redemanderLeSysteme');

        $affectation = strpos($corps, 'contactsDeSurveillance = Array.isArray(reponse.surveillance)');
        $appel = strpos($corps, 'dessinerLaSurveillance(carte);');

        $this->assertIsInt($affectation);
        $this->assertIsInt($appel, 'La couche n est pas dessinee dans la reponse acceptee.');
        $this->assertGreaterThan($affectation, $appel, 'La couche est dessinee avant d avoir recu la liste neuve.');
    }
}
