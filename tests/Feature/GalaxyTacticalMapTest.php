<?php

namespace Tests\Feature;

use OGame\Services\PlanetService;
use Tests\UnitTestCase;

/**
 * Ce qui tient la carte tactique debout, et qui tomberait sans bruit.
 *
 * ## Pourquoi ces temoins lisent des fichiers
 *
 * La carte est du JavaScript et une feuille de style : aucun essai PHP ne la fera cliquer. Ce
 * fichier ne pretend donc pas eprouver le rendu — il epingle les faits porteurs dont depend le
 * raccordement, et dont la rupture ne se verrait nulle part ailleurs qu'en jeu, tardivement.
 *
 * ## Ce que la premiere version de ce fichier a rate, et la lecon
 *
 * Elle verifiait que la regle de masquage employait bien `>`. Elle passait. **Et le tableau n'a
 * jamais ete masque** : la regle etait battue en specificite par `#galaxyContent .galaxyRow` du
 * jeu. Le temoin lisait la **forme** du selecteur, jamais son **effet**. Les temoins ci-dessous
 * comparent donc des specificites et verifient l'existence reelle des fichiers, au lieu de se
 * contenter de reconnaitre un motif.
 */
class GalaxyTacticalMapTest extends UnitTestCase
{
    /**
     * Le fichier du module, lu une fois.
     */
    private function module(): string
    {
        $source = file_get_contents(resource_path('js/ingame/galaxy-tactical.js'));
        $this->assertIsString($source, 'The tactical map module is missing.');

        return $source;
    }

    /**
     * La feuille de la carte, lue une fois.
     */
    private function feuille(): string
    {
        $source = file_get_contents(resource_path('css/ingame/galaxy-tactical.css'));
        $this->assertIsString($source, 'The tactical map stylesheet is missing.');

        return $source;
    }

    /**
     * La vue de la Galaxie, lue une fois.
     */
    private function vue(): string
    {
        $source = file_get_contents(resource_path('views/ingame/galaxy/index.blade.php'));
        $this->assertIsString($source, 'The galaxy view is missing.');

        return $source;
    }

    /**
     * **Toutes les textures que le serveur peut attribuer existent sur le disque.**
     *
     * La carte construit `/img/planets/medium/{biome}_{variante}.png` depuis `imageInformation`,
     * qui vaut `getPlanetBiomeType() . '_' . getPlanetImageType()`. Si un seul couple manquait, la
     * planete correspondante disparaitrait de la carte pour les seuls joueurs concernes — un defaut
     * que personne ne verrait avant qu'un joueur ne le signale.
     *
     * Ce temoin ne reconnait pas un motif : il ouvre les fichiers.
     */
    public function testEveryTextureTheServerCanAssignExistsOnDisk(): void
    {
        $biomes = ['desert', 'dry', 'gas', 'ice', 'jungle', 'normal', 'water'];
        $manquants = [];

        foreach ($biomes as $biome) {
            for ($variante = 1; $variante <= 10; $variante++) {
                $chemin = public_path('img/planets/medium/' . $biome . '_' . $variante . '.png');

                if (!is_file($chemin)) {
                    $manquants[] = $biome . '_' . $variante;
                }
            }
        }

        $this->assertSame(
            [],
            $manquants,
            'The tactical map asks for planet textures that do not exist: ' . implode(', ', $manquants)
        );

        $this->assertFileExists(
            public_path('img/planets/npc/pirate_base.png'),
            'The hostile faction texture is missing: NPC bases would vanish from the map.'
        );
    }

    /**
     * Les biomes que le temoin ci-dessus enumere sont bien ceux que le serveur produit.
     *
     * Sans ce controle, ajouter un biome au jeu laisserait le temoin precedent vert tout en
     * laissant la nouvelle texture absente : il verifierait une liste perimee, ce qui est pire
     * qu'aucune verification.
     */
    public function testTheBiomeListMatchesWhatTheServerProduces(): void
    {
        $source = file_get_contents(app_path('Services/PlanetService.php'));
        $this->assertIsString($source);

        $debut = strpos($source, 'public function getPlanetBiomeType');
        $this->assertNotFalse($debut, 'getPlanetBiomeType() no longer exists.');

        $bloc = substr($source, $debut, 2000);
        $trouves = [];

        if (preg_match_all("/'(odd|even)' => '([a-z]+)'/", $bloc, $correspondances) > 0) {
            $trouves = array_values(array_unique($correspondances[2]));
        }

        sort($trouves);

        $this->assertSame(
            ['desert', 'dry', 'gas', 'ice', 'jungle', 'normal', 'water'],
            $trouves,
            'The server can now produce biomes the texture witness does not check.'
        );

        $this->assertTrue(
            class_exists(PlanetService::class),
            'PlanetService moved: the biome witness reads a path that no longer names the server.'
        );
    }

    /**
     * La carte demande la texture du serveur, pas la planche de sprites.
     *
     * La premiere version posait `class="microplanet desert_7"`, dont la variante n'est definie que
     * sous `#galaxyContent .ctContentRow .cellPlanet` : hors du tableau, la vignette n'avait aucune
     * image de fond et les planetes etaient invisibles.
     */
    public function testTheMapAsksForTheServerTexture(): void
    {
        $module = $this->module();

        $this->assertStringContainsString(
            "'/img/planets/medium/'",
            $module,
            'The map no longer builds its planet images from the authoritative texture folder.'
        );

        $this->assertStringNotContainsString(
            "element('div', 'microplanet')",
            $module,
            'The map builds a sprite tile again: its variant class is only defined inside the table, so the planets render with no image at all.'
        );
    }

    /**
     * **La regle de masquage doit battre celle du jeu, pas seulement exister.**
     *
     * C'est le defaut que la premiere version de ce fichier a laisse passer. `#galaxyContent
     * .galaxyRow { display: flex }` pese (1,1,0) ; une regle de masquage en classes seules pese
     * (0,3,0) et perd. Ce temoin exige le prefixe d'identifiant **et** etablit que la regle
     * concurrente existe toujours — sans quoi il exigerait un prefixe devenu inutile sans le dire.
     */
    public function testTheHidingRuleOutweighsTheGameRule(): void
    {
        $feuille = $this->feuille();

        $this->assertStringContainsString(
            '#galaxyContent .galaxyTable.gtReplaced > .ctContentRow',
            $feuille,
            'The hiding rule lost its id prefix: #galaxyContent .galaxyRow beats it and the whole table stays visible under the map.'
        );

        $jeu = file_get_contents(resource_path('css/ingame/469500b3cd5158332fb20a56b14b2c.css'));
        $this->assertIsString($jeu);

        $this->assertMatchesRegularExpression(
            '/#galaxyContent \.galaxyRow \{[^}]*display:\s*flex/',
            $jeu,
            'The competing game rule is gone: this witness now demands a prefix nothing requires.'
        );
    }

    /**
     * La bascule existe des deux cotes : le bouton dans la vue, le raccordement dans le module.
     *
     * Decision de Keven : on garde la vue liste pendant la mise au point, comme reference de
     * parite, et on la retire quand la carte aura fait ses preuves.
     */
    public function testTheViewSwitchIsWiredOnBothSides(): void
    {
        $this->assertStringContainsString(
            'id="gtViewTactical"',
            $this->vue(),
            'The tactical view button is missing from the galaxy view.'
        );

        $this->assertStringContainsString(
            'id="gtViewList"',
            $this->vue(),
            'The list view button is missing from the galaxy view.'
        );

        $this->assertStringContainsString(
            "classList.toggle('gtReplaced'",
            $this->module(),
            'Nothing switches the gtReplaced class: the buttons are decoration and the list view is unreachable.'
        );

        $this->assertMatchesRegularExpression(
            '/#galaxyContent \.galaxyTable:not\(\.gtReplaced\) > #galaxyTactical \{[^}]*display:\s*none/',
            $this->feuille(),
            'The map stays visible in list view: both views would show at once.'
        );
    }

    /**
     * Le creneau d'espace profond n'est pas masque.
     *
     * Il portait la regle de masquage des lignes du tableau, et la carte ne le dessine pas : les
     * debris d'expedition et le modele de flotte d'expedition avaient purement disparu du jeu.
     */
    public function testTheDeepSpaceSlotIsNeverHidden(): void
    {
        $this->assertStringNotContainsString(
            'gtReplaced > .expeditionDebrisSlotBoxRow',
            $this->feuille(),
            'The deep space slot is hidden again: expedition debris and the expedition fleet template are unreachable, and the map does not draw them either.'
        );
    }

    /**
     * **La ligne est deplacee dans la fiche, jamais recopiee.**
     *
     * Le rendu herite accroche ses gestionnaires sur ces noeuds a chaque passage — infobulles,
     * overlay de missile, demande d'ami, mise a l'ignore. Un `cloneNode` les perdrait **tous**, et
     * en silence : la fiche s'afficherait, les liens seraient la, et rien ne repondrait au clic.
     *
     * Le motif vise l'**appel**, `cloneNode(`, et non le mot : une premiere version cherchait
     * `cloneNode` tout court et rougissait sur le commentaire qui nomme le piege pour l'expliquer.
     */
    public function testTheRowIsMovedIntoTheCardAndNeverCloned(): void
    {
        $module = $this->module();

        $this->assertStringContainsString(
            'corps.appendChild(ligne)',
            $module,
            'The module no longer moves the table row into the card.'
        );

        $this->assertStringNotContainsString(
            'cloneNode(',
            $module,
            'The module clones the row instead of moving it: every handler the game bound to it is lost, and the actions look present while doing nothing.'
        );
    }

    /**
     * La ligne retourne d'ou elle vient, au meme rang.
     *
     * Un simple retour a la fin du tableau reordonnerait les positions a chaque selection : la
     * position 3, choisie puis quittee, se retrouverait apres la 15. Le tableau etant masque en vue
     * tactique, personne ne le verrait — jusqu'a ce qu'on repasse en vue liste.
     */
    public function testTheRowGoesBackWhereItCameFrom(): void
    {
        $this->assertStringContainsString(
            'deplacee.parent.insertBefore(deplacee.noeud, deplacee.suivant)',
            $this->module(),
            'The row is not put back at its original rank: the list view silently reorders itself with every selection.'
        );
    }

    /**
     * La fiche peut se refermer.
     *
     * `hidden` n'agit que par `[hidden] { display: none }` de la feuille du navigateur, de
     * specificite minuscule. Le panneau d'emoji du chat general a coute exactement cette lecon :
     * une regle d'identite qui pose un `display` gagne, et la fiche ne se referme plus jamais.
     */
    public function testTheCardCanActuallyClose(): void
    {
        $this->assertMatchesRegularExpression(
            '/#galaxyTactical \.gtCard\[hidden\]\s*\{[^}]*display:\s*none/',
            $this->feuille(),
            'Nothing re-establishes [hidden] for the card: the id-level display rule beats the browser default and the card never closes.'
        );
    }

    /**
     * Le clavier ouvre ce que la souris ouvre.
     *
     * Les corps annoncent `role="button"` et prennent le focus. Un element qui annonce un bouton et
     * ne repond ni a Entree ni a Espace ment a qui navigue au clavier.
     */
    public function testTheKeyboardOpensWhatTheMouseOpens(): void
    {
        $module = $this->module();

        $this->assertStringContainsString(
            "evenement.key === 'Enter'",
            $module,
            'The map announces role="button" on its bodies but never answers Enter.'
        );

        $this->assertStringContainsString(
            "evenement.key === ' '",
            $module,
            'The map announces role="button" on its bodies but never answers Space.'
        );
    }

    /**
     * Les libelles de la carte existent dans les deux langues, et la vue les publie.
     *
     * `__()` rendrait la clef elle-meme si elle manquait — une chaine lisible, sans erreur, dans un
     * `aria-label` ou sur un bouton. Le genre de defaut qu'on ne voit jamais.
     */
    public function testTheMapLabelsExistInBothLanguages(): void
    {
        foreach (['fr', 'en'] as $langue) {
            $lignes = require resource_path('lang/' . $langue . '/t_ingame.php');

            foreach (['tactical_map', 'tactical_switch', 'tactical_view', 'list_view', 'tactical_close'] as $clef) {
                $this->assertArrayHasKey(
                    $clef,
                    $lignes['galaxy'],
                    'The label ' . $clef . ' is missing in ' . $langue . ': the page would print the translation key itself.'
                );
            }
        }

        /*
         * Les deux textes de la fiche passent par des attributs de la vue, pas par `jsloca` : une
         * clef absente de cette table rendrait `undefined` sans erreur, et le repli anglais
         * s'afficherait a un joueur francais.
         */
        $this->assertStringContainsString(
            'data-loca-close=',
            $this->vue(),
            'The card close label is no longer published by the view: the module would fall back to its hard-coded French.'
        );
    }

    /**
     * **Aucune cellule porteuse d'action n'est masquee dans la fiche.**
     *
     * C'est le temoin de parite, et il ferme un defaut reel : la feuille masquait `.cellPlanet` et
     * `.cellPlanetName` au motif que la carte porte deja la vignette et le nom. Or `.cellPlanet`
     * porte le lien d'espionnage rapide, l'etoile d'activite et l'infobulle de planete, et
     * `.cellPlanetName` porte le lien de phalange. Deux fonctions disparaissaient de la fiche sans
     * qu'aucun essai ne s'en plaigne.
     *
     * Seule `.cellPosition` peut etre masquee : elle ne porte qu'un numero, que les coordonnees de
     * la fiche redisent.
     */
    public function testNoCellThatCarriesAnActionIsHiddenInTheCard(): void
    {
        $feuille = $this->feuille();

        foreach (['cellPlanet', 'cellPlanetName', 'cellMoon', 'cellDebris', 'cellPlayerName', 'cellAlliance', 'cellAction'] as $cellule) {
            $this->assertDoesNotMatchRegularExpression(
                '/\.gtCardBody[^{}]*\.' . $cellule . '\b[^{}]*\{[^}]*display:\s*none/',
                $feuille,
                'The card hides .' . $cellule . ', which carries actions rendered by the server: they vanish from the map with no error anywhere.'
            );
        }
    }

    /**
     * L'inventaire sur lequel le temoin precedent repose est verifie sur le rendu herite.
     *
     * Sans cela, il interdirait de masquer des cellules pour une raison qui aurait pu disparaitre —
     * une regle qu'on ne peut plus justifier est une regle qu'on finit par retirer.
     */
    public function testTheLegacyRenderStillPutsThoseActionsInThoseCells(): void
    {
        $herite = file_get_contents(resource_path('js/ingame/e7c74974620fa35b197315ebdbb8c2.js'));
        $this->assertIsString($herite);

        $this->assertStringContainsString(
            '.cellPlanet").html(`<a href="javascript: void(0);" onclick="${getEspionageMission(',
            $herite,
            'Quick espionage is no longer rendered into .cellPlanet: the parity witness now protects a cell for a reason that no longer holds.'
        );

        $this->assertStringContainsString(
            '.cellPlanetName").append(\'<a class="phalanxlink"',
            $herite,
            'The phalanx link is no longer rendered into .cellPlanetName: the parity witness now protects a cell for a reason that no longer holds.'
        );
    }

    /**
     * Le filtre des positions libres agit aussi sur la carte.
     *
     * `filterToggle()` pose `filtered_filter_empty` sur les elements presents **au moment du clic**.
     * La carte etant reconstruite a chaque changement de systeme, ses corps naissent apres : sans
     * rappel de l'etat, le filtre paraitrait s'eteindre tout seul.
     */
    public function testTheEmptySlotFilterReachesTheMap(): void
    {
        $module = $this->module();

        $this->assertStringContainsString(
            "'gtFree empty_filter'",
            $module,
            'Free positions no longer carry empty_filter: the E filter of the header does nothing on the map.'
        );

        $this->assertStringContainsString(
            "classList.add('filtered_filter_empty')",
            $module,
            'The filter state is not re-applied after a redraw: changing system would silently switch the filter off.'
        );
    }

    /**
     * Les quatre autres filtres restent sans emetteur cote serveur.
     *
     * **Mesure faite plutot que supposee** : seuls `empty_filter` et `expedition_debris` sont emis
     * par `GalaxyController`. Les reproduire sur la carte n'aurait rien restaure — ca aurait ajoute
     * une fonction que le jeu n'a pas. Si l'un des quatre apparaissait un jour, ce temoin le dirait,
     * et la carte devrait alors le porter comme elle porte le premier.
     */
    public function testTheOtherFiltersStillHaveNoServerSideEmitter(): void
    {
        $controleur = file_get_contents(app_path('Http/Controllers/GalaxyController.php'));
        $this->assertIsString($controleur);

        foreach (['inactive_filter', 'vacation_filter', 'strong_filter', 'newbie_filter'] as $filtre) {
            $this->assertStringNotContainsString(
                $filtre,
                $controleur,
                'The server now emits ' . $filtre . ': the tactical map must dim those bodies too, as it already does for empty slots.'
            );
        }

        $this->assertStringContainsString(
            "'positionFilters' => 'empty_filter'",
            $controleur,
            'The empty-slot filter class is no longer emitted: the map carries a class nothing targets.'
        );
    }

    /**
     * **La carte lit les lignes la ou le serveur les met.**
     *
     * La premiere version lisait `json.galaxy`, qui n'existe pas : la reponse porte les lignes sous
     * `system.galaxyContent`, comme `renderContentGalaxy()` les lit depuis toujours. La garde
     * sortait avant de rien tracer, et l'etoile vue en jeu venait du fond JPG du pack. Trois faits
     * sont epingles : le module lit le bon chemin, le rendu herite lit le meme, et le controleur
     * l'emet sous cette clef.
     */
    public function testTheMapReadsTheRowsWhereTheServerPutsThem(): void
    {
        $module = $this->module();

        $this->assertStringContainsString(
            'systeme.galaxyContent',
            $module,
            'The map no longer reads system.galaxyContent: it draws nothing, silently.'
        );

        $this->assertStringNotContainsString(
            'json.galaxy.forEach',
            $module,
            'The map iterates json.galaxy, which the response never carries: the loop never runs.'
        );

        $this->assertStringNotContainsString(
            '!json.galaxy)',
            $module,
            'The map guards on json.galaxy, which the response never carries: it returns before drawing.'
        );

        $herite = file_get_contents(resource_path('js/ingame/e7c74974620fa35b197315ebdbb8c2.js'));
        $this->assertIsString($herite);
        $this->assertStringContainsString(
            'json.system.galaxyContent',
            $herite,
            'The legacy render no longer reads system.galaxyContent: the payload shape changed and the map must follow.'
        );

        $controleur = file_get_contents(app_path('Http/Controllers/GalaxyController.php'));
        $this->assertIsString($controleur);
        $this->assertStringContainsString(
            "'galaxyContent' => \$galaxyContent",
            $controleur,
            'The controller no longer emits galaxyContent: both renders would go blind.'
        );
    }

    /**
     * La couche des flottes est raccordee de bout en bout : la vue publie l'adresse, la route
     * existe, le module s'abonne aux deux canaux et quitte l'ancien systeme avant le nouveau.
     */
    public function testTheFleetLayerIsWiredEndToEnd(): void
    {
        $this->assertStringContainsString(
            "var galaxyFleetsUrl = \"{{ route('galaxy.fleets') }}\";",
            $this->vue(),
            'The view no longer publishes galaxyFleetsUrl: the module never asks for fleets.'
        );

        $module = $this->module();

        foreach (["'galaxy.system.'", "'galaxy.player.'", ".listen('.GalaxySystemChanged'", ".listen('.FleetMovementChanged'", 'window.Echo.leave('] as $motif) {
            $this->assertStringContainsString(
                $motif,
                $module,
                'The fleet layer lost ' . $motif . ': live updates would silently stop for that channel.'
            );
        }

        $this->assertStringContainsString(
            'jeton !== jetonDeSysteme',
            $module,
            'A late response from the previous system can overwrite the new one: the sequence token is gone.'
        );

        $this->assertStringContainsString(
            "'(prefers-reduced-motion: reduce)'",
            $module,
            'The fleet animation ignores prefers-reduced-motion.'
        );
    }

    /**
     * La couche ne capte aucun clic sauf sur ses marqueurs : les corps et la fiche restent
     * cliquables sous les trajectoires.
     */
    public function testTheFleetLayerDoesNotStealClicksFromTheBodies(): void
    {
        $this->assertMatchesRegularExpression(
            '/#galaxyTactical \.gtFleetLayer \{[^}]*pointer-events:\s*none/',
            $this->feuille(),
            'The fleet layer catches pointer events: every trajectory that crosses a planet makes it unclickable.'
        );
    }

    /**
     * Le module est bien dans le paquet construit par Vite.
     *
     * Un fichier de `resources/js` que `vite.config.js` ne nomme pas n'est concatene nulle part :
     * il est parfait, relu, teste — et absent du jeu.
     */
    public function testTheModuleIsPartOfTheBundle(): void
    {
        $configuration = file_get_contents(base_path('vite.config.js'));
        $this->assertIsString($configuration);

        $this->assertStringContainsString(
            'resources/js/ingame/galaxy-tactical.js',
            $configuration,
            'The tactical map module is not listed in vite.config.js: it is never concatenated into the bundle the game serves.'
        );
    }
}
