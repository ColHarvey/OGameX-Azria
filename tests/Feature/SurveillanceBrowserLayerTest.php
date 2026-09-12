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
 *
 * ## Les deux moities du rejet des reponses tardives
 *
 * Le bundle servi porte la correction de la revue 124 : une reponse compare sa generation, et une
 * invalidation ouvre un nouveau contexte. Les deux comptent — comparer sans incrementer laisserait
 * une demande partie avant une revocation revenir et reafficher ce qu elle vient d oter.
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

        /*
         * La liste est **adoptee** depuis le 12 septembre 2026 : l ensemble est celui de la reponse,
         * les objets deja affiches gardent leur identite. C est encore un remplacement de l ensemble.
         */
        $this->assertStringContainsString(
            "contactsDeSurveillance = adopter(contactsDeSurveillance, Array.isArray(reponse.surveillance) ? reponse.surveillance : [], 'contact_id');",
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
            'generation !== generationDuContexte',
            $bundle,
            'Le rejet des reponses tardives a disparu : la surveillance s appuyait sur lui.'
        );

        // Et il protege bien la reponse ou la surveillance est lue : les deux vivent dans la meme
        // fonction, donc le garde precede l affectation.
        $corps = $this->corpsDe($bundle, 'chargerLesFlottes', 'redemanderLeSysteme');
        $garde = strpos($corps, 'generation !== generationDuContexte');
        $affectation = strpos($corps, 'contactsDeSurveillance = adopter(contactsDeSurveillance, Array.isArray(reponse.surveillance)');

        $this->assertIsInt($garde);
        $this->assertIsInt($affectation);
        $this->assertLessThan($affectation, $garde, 'La surveillance est lue avant le rejet des reponses tardives.');

        /*
         * **La seconde moitie, dans le bundle aussi** (revue 124, point 3). Comparer la generation
         * ne sert a rien si aucune invalidation n en ouvre une nouvelle : une demande partie avant
         * une revocation resterait « courante » et sa reponse reafficherait ce qui vient d etre
         * retire. Le motif est cherche dans le corps de l invalidation, pas dans tout le fichier —
         * le compteur est aussi incremente ailleurs, et un `strpos` global trouverait ce site-la.
         */
        $invalidation = $this->corpsDe($bundle, 'invaliderLaSurveillance', 'adresseDePatrouille');

        $this->assertStringContainsString(
            'generationDuContexte++',
            $invalidation,
            'Le bundle servi n ouvre pas de nouveau contexte a l invalidation : une demande en vol au moment d une revocation reafficherait ce qu elle vient d oter.'
        );
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
    public function testTheGenerationIsBumpedBeforeTheRequestLeavesAndAFailureClears(): void
    {
        $bundle = $this->bundleServi();

        $this->assertStringContainsString('function invaliderLaSurveillance(', $bundle);

        $corps = $this->corpsDe($bundle, 'chargerLesFlottes', 'redemanderLeSysteme');

        /*
         * **Perimer avant de partir, masquer a l echec.** Depuis le 12 septembre 2026 (decision de
         * Keven : tout en temps reel), la demande ordinaire ne masque plus la couche au depart —
         * une veille toutes les dix secondes aurait fait clignoter chaque contact. La generation,
         * elle, est toujours incrementee **avant** la requete, pour qu une reponse ancienne soit
         * jetee ; et une requete qui echoue masque, au lieu de laisser l ancien contenu.
         */
        $generation = strpos($corps, 'generationDuContexte++;');
        $requete = strpos($corps, 'window.jQuery.getJSON(galaxyFleetsUrl');
        $echec = strpos($corps, 'invaliderLaSurveillance(carte);');

        $this->assertIsInt($generation, 'Rien ne perime la demande precedente : une reponse tardive laisserait l interdit a l ecran.');
        $this->assertIsInt($requete);
        $this->assertLessThan($requete, $generation, 'La generation est incrementee apres le depart de la requete, donc trop tard.');
        $this->assertIsInt($echec, 'Une requete echouee laisse l ancien contenu a l ecran.');
        $this->assertGreaterThan($requete, $echec, 'Le masquage est au depart de la demande, pas a son echec : la couche clignote a chaque veille.');
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

        $affectation = strpos($corps, 'contactsDeSurveillance = adopter(contactsDeSurveillance, Array.isArray(reponse.surveillance)');
        $appel = strpos($corps, 'dessinerLaSurveillance(carte);');

        $this->assertIsInt($affectation);
        $this->assertIsInt($appel, 'La couche n est pas dessinee dans la reponse acceptee.');
        $this->assertGreaterThan($affectation, $appel, 'La couche est dessinee avant d avoir recu la liste neuve.');
    }

    /**
     * **Aucune issue perimee ne s applique** : ni un echec, ni une reponse mise de cote pendant un
     * glisser (Codex, 12 septembre 2026).
     *
     * Les reponses comparaient leur generation ; les echecs non, et la reponse differee non plus. Les
     * deux gardes sont cherchees dans le corps de la fonction qui les porte, et avant le geste
     * qu elles protegent.
     */
    public function testTheServedBundleIgnoresEveryStaleOutcome(): void
    {
        $bundle = $this->bundleServi();

        // L echec : la garde de generation, avant le masquage.
        $chargement = $this->corpsDe($bundle, 'chargerLesFlottes', 'redemanderLeSysteme');
        $echec = strpos($chargement, '.fail(function () {');
        $garde = strpos($chargement, 'if (generation !== generationDuContexte) {');
        $masquage = strpos($chargement, 'invaliderLaSurveillance(carte);');

        $this->assertIsInt($echec);
        $this->assertIsInt($garde, 'Le bundle servi laisse un echec perime vider la surveillance.');
        $this->assertIsInt($masquage);
        $this->assertGreaterThan($echec, $garde, 'La garde de generation n est pas dans le gestionnaire d echec.');
        $this->assertLessThan($masquage, $garde, 'L echec masque avant de verifier sa generation.');

        // La reponse differee : elle emporte sa generation…
        $this->assertStringContainsString(
            'carte.gtReponseDue = { reponse: reponse, galaxie: galaxie, systeme: systeme, generation: generation };',
            $bundle,
            'La reponse mise de cote pendant un glisser n emporte pas sa generation.'
        );

        // …et la fin du geste la verifie avant d appliquer.
        $fin = $this->corpsDe($bundle, 'finirLeGeste', 'destinationDuClic');
        $verification = strpos($fin, 'if (Number(due.generation) !== generationDuContexte) {');
        $application = strpos($fin, 'appliquerLesFlottes(carte, due.reponse, due.galaxie, due.systeme);');

        $this->assertIsInt($verification, 'Le bundle servi applique une reponse differee sans verifier sa generation.');
        $this->assertIsInt($application);
        $this->assertLessThan($application, $verification, 'La reponse differee est appliquee avant que sa generation soit verifiee.');
    }

    /**
     * **L espionnage de systeme attend le retour de chaque envoi**, dans le bundle servi.
     */
    public function testTheServedBundleSendsTheSystemEspionageOneProbeAtATime(): void
    {
        $bundle = $this->bundleServi();

        $debut = strpos($bundle, 'window.spyWholeSystem = function () {');
        $this->assertIsInt($debut, 'Le bundle servi ne definit pas l espionnage de systeme : le bouton ne ferait rien.');

        $bout = strpos($bundle, 'envoyer(0);', $debut);
        $this->assertIsInt($bout);

        $corps = substr($bundle, $debut, $bout - $debut);

        $this->assertStringContainsString('if (Number(window.shipsendingDone) === 1) {', $corps, 'L espionnage de systeme n attend pas le retour de l envoi precedent.');
        $this->assertStringContainsString('if (Date.now() - depuis > ESPIONNAGE_ATTENTE_MAX) {', $corps, 'Un retour perdu figerait l espionnage de systeme.');
        $this->assertStringNotContainsString('for (var i = 0; i < liens.length; i++) {' . "\n" . '                    liens[i].click();', $corps);
    }

    /**
     * **Un envoi rapide ne laisse jamais son drapeau baisse**, dans le bundle servi : ni en erreur, ni
     * quand l affichage du succes leve. Et l espionnage de systeme ne compte que les envois acceptes.
     */
    public function testTheServedBundleNeverLeavesTheSendFlagDown(): void
    {
        $bundle = $this->bundleServi();
        $envoi = $this->corpsDe($bundle, 'sendShips', 'sendShipsWithPopup');

        $this->assertStringContainsString('} finally {', $envoi, 'Un succes qui leve laisse le drapeau baisse dans le bundle servi.');
        $this->assertStringContainsString('error: function () {', $envoi, 'Une requete en erreur laisse le drapeau baisse dans le bundle servi.');
        $this->assertStringContainsString("window.sendShipsLastOutcome = 'error';", $envoi);
        $this->assertGreaterThanOrEqual(2, substr_count($envoi, 'shipsendingDone = 1;'), 'Le drapeau ne se releve pas sur les deux issues.');

        $debut = strpos($bundle, 'window.spyWholeSystem = function () {');
        $this->assertIsInt($debut);
        $bout = strpos($bundle, 'envoyer(0);', $debut);
        $this->assertIsInt($bout);

        $this->assertStringContainsString(
            "if (window.sendShipsLastOutcome === 'success') {",
            substr($bundle, $debut, $bout - $debut),
            'L espionnage de systeme compte un envoi refuse ou en erreur comme parti.'
        );
    }
}
