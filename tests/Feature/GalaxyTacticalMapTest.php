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
     * **L'espace profond est une nebuleuse qui porte la ligne historique**, et rien ne se perd.
     *
     * Trois etats successifs : la bande de la position 16 masquee sans rien a la place (deux
     * fonctions perdues), puis visible sous la carte, puis — consigne de Codex — remplacee par une
     * nebuleuse cliquable dont la fiche deplace `#galaxyRow16` avec son bouton d'expedition, son
     * choix de flotte et ses debris. Ce temoin exige les trois moities a la fois : la bande masquee
     * **avec le prefixe d'identifiant** (sinon la regle perd, cf. le tableau), la nebuleuse posee
     * sous `data-position="16"` (sinon la fiche ne trouve pas la ligne), et le point des
     * trajectoires d'expedition pris sur la nebuleuse et non sur une seizieme orbite.
     */
    public function testTheDeepSpaceNebulaCarriesTheHistoricalSlot(): void
    {
        $feuille = $this->feuille();
        $module = $this->module();

        $this->assertStringContainsString(
            '#galaxyContent .galaxyTable.gtReplaced > .expeditionDebrisSlotBoxRow',
            $feuille,
            'The historical deep-space strip is no longer hidden under the id prefix: it would show twice, or not hide at all.'
        );

        $this->assertStringContainsString(
            "bloc.setAttribute('data-position', String(POSITION_ESPACE_PROFOND));",
            $module,
            'The nebula no longer carries data-position 16: the card cannot find #galaxyRow16, and expedition, fleet template and debris are lost.'
        );

        $this->assertStringContainsString(
            'return pointDeLaNebuleuse();',
            $module,
            'Expedition trajectories no longer end on the nebula: position 16 goes back through the orbit geometry.'
        );

        $this->assertFileExists(
            public_path('img/galaxy-tactical/deep-space-nebula-v2.png'),
            'The nebula sprite is missing.'
        );

        $this->assertStringContainsString(
            'deep-space-nebula-v2.png',
            $feuille,
            'The stylesheet no longer paints the nebula.'
        );

        /* L'alpha du PNG est reel : le premier pixel du coin est transparent. */
        $image = imagecreatefrompng(public_path('img/galaxy-tactical/deep-space-nebula-v2.png'));
        $this->assertNotFalse($image);
        $this->assertGreaterThanOrEqual(120, (imagecolorat($image, 0, 0) >> 24) & 0x7F, 'The nebula PNG lost its transparency: a rectangle of space would sit on the map.');

        $this->assertMatchesRegularExpression(
            '/@keyframes gtNebulaPulse/',
            $feuille,
            'The nebula pulsation is gone.'
        );

        foreach (['fr', 'en'] as $langue) {
            $lignes = require resource_path('lang/' . $langue . '/t_ingame.php');
            $this->assertArrayHasKey('tactical_deep_space', $lignes['galaxy'], 'The deep-space label is missing in ' . $langue . '.');
        }
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
            'contenant.appendChild(ligne)',
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
     * **Un seul soleil, au centre des orbites, et des sprites entiers.** Les deux defauts de la
     * capture de Keven.
     *
     * Le fond v2 du pack ne peint ni soleil ni orbites (mesure : un pixel au-dessus du seuil de
     * luminance) ; le module dessine le soleil **une fois**, depuis `centre()` — le meme point que
     * les orbites. La premiere version le posait a 50 % / 50 % de la boite, quatorze pixels plus bas,
     * et le fond v1 en peignait un autre : deux astres. Et une boite minimale de 22 px sur des
     * sprites de 17 px montrait cinq pixels de l'icone voisine — des icones « empilees ».
     */
    public function testOneSunAndWholeSprites(): void
    {
        $module = $this->module();

        $this->assertSame(
            1,
            substr_count($module, "element('div', 'gtStar')"),
            'The sun must be drawn exactly once by the module: the v2 background paints none.'
        );

        $this->assertStringContainsString(
            "etoile.style.top = Math.round(c.y) + 'px';",
            $module,
            'The sun is no longer placed from centre(): it would sit off the orbits again.'
        );

        /*
         * Cree ne veut pas dire pose. Une mutation qui gardait la creation et retirait l'ajout
         * survivait a ce temoin : la carte n'avait plus de soleil et rien ne rougissait.
         */
        $this->assertStringContainsString(
            'carte.appendChild(etoile);',
            $module,
            'The sun is created but never appended to the map: no sun is drawn, silently.'
        );

        $feuille = $this->feuille();

        $this->assertStringContainsString(
            'galaxy-tactical-background-v2.jpg',
            $feuille,
            'The map no longer uses the v2 background: v1 paints its own sun and orbits, doubling the drawn ones.'
        );

        $this->assertFileExists(
            public_path('img/galaxy-tactical/galaxy-tactical-background-v2.jpg'),
            'The v2 background file is missing: the map would show the panel colour only.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/#galaxyTactical \.gtStar\s*\{[^}]*top:\s*50%/',
            $feuille,
            'The .gtStar rule fixes top: 50% again: that is the box centre, fourteen pixels below the orbit centre.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/\.gtCardBody \.cellAction > \* \{[^}]*min-(width|height)/',
            $feuille,
            'The card forces a minimum box on action icons: 17px sprite strips would bleed into the next icon again.'
        );
    }

    /**
     * Les quatre constats de Codex qui etaient des defauts, chacun epingle par le trait de code qui
     * le ferme.
     */
    public function testTheFourCodexFindingsAreClosed(): void
    {
        $module = $this->module();

        // 1. Une reponse tardive de l'ancien systeme ne remplace pas celui qui est affiche.
        $this->assertStringContainsString(
            'if (courant && (courant.galaxie !== galaxie || courant.systeme !== systeme)) {',
            $module,
            'A late system response is rendered without checking the displayed system: switching fast can show the previous one.'
        );

        // 2. Lune et debris sont des cibles distinctes, et la fiche dit lequel est choisi.
        $this->assertStringContainsString("cibleDistincte(creneau, 'moon'", $module, 'The moon is no longer a distinct target: clicking it selects the planet.');
        $this->assertStringContainsString("cibleDistincte(champ, 'debris'", $module, 'The debris field is no longer a distinct target.');
        $this->assertStringContainsString("f.setAttribute('data-corps', corps);", $module, 'The card no longer says which body is selected.');

        // 3. Les trajectoires suivent le type de la cible, et l'espace profond a son point.
        $this->assertStringContainsString('pointDeCorps(mouvement.to.position, mouvement.to.type)', $module, 'Trajectories ignore the destination type again: a mission to a moon lands on the planet.');
        $this->assertStringContainsString('Number(position) === POSITION_ESPACE_PROFOND', $module, 'Position 16 goes through the orbit geometry again.');

        // 4. Un redessin du meme systeme ne ferme pas la fiche.
        $this->assertStringContainsString('restaurerLaSelection(carte, aRestaurer);', $module, 'A live redraw closes the open card again.');
        $this->assertStringContainsString('var aRestaurer = memeSysteme && deplacee', $module, 'The selection is not remembered before the redraw.');

        foreach (['fr', 'en'] as $langue) {
            $lignes = require resource_path('lang/' . $langue . '/t_ingame.php');

            foreach (['tactical_moon', 'tactical_debris'] as $clef) {
                $this->assertArrayHasKey($clef, $lignes['galaxy'], 'The label ' . $clef . ' is missing in ' . $langue . '.');
            }
        }
    }

    /**
     * **L'enveloppe est posee des l'execution du module, pas a `DOMContentLoaded`.**
     *
     * Le premier chargement du systeme part d'un script du corps de la page, avant l'evenement, et
     * le rendu herite passe la fonction par valeur au `$.post`. Une enveloppe posee trop tard laisse
     * ce premier appel capturer la fonction nue : carte vide jusqu'au premier changement de systeme
     * — le defaut que Keven a vu. `renderContentGalaxy` est une declaration hissee du meme script
     * concatene : elle existe deja quand ce module s'execute.
     */
    public function testTheMapWrapsTheLegacyRenderImmediately(): void
    {
        $module = $this->module();

        $this->assertMatchesRegularExpression(
            '/^    brancher\(\);\R\R    if \(document\.readyState === \'loading\'\) \{/m',
            $module,
            'The module waits for DOMContentLoaded before wrapping renderContentGalaxy: the first system load captures the bare function and the map stays empty until the player changes system.'
        );

        $this->assertStringNotContainsString(
            "    } else {\n        brancher();\n    }",
            str_replace("\r\n", "\n", $module),
            'The wrap is back to deferred-or-immediate: the first load can still capture the bare function.'
        );
    }

    /**
     * Une constante numerique du module, lue dans sa source.
     */
    private function constanteDuModule(string $nom): float
    {
        $ok = preg_match('/var ' . $nom . ' = ([0-9.]+);/', $this->module(), $m);
        $this->assertSame(1, $ok, 'The module no longer declares ' . $nom . '.');

        return (float)$m[1];
    }

    /**
     * **Les quinze corps se tiennent a distance, sur toutes les paires.**
     *
     * Keven : « ne pas mettre les planetes trop proches, qu'elles ne s'empilent pas ». L'angle d'or
     * seul ne garantit rien sur une ellipse aplatie ; le module repousse chaque paire trop proche par
     * un ecartement deterministe. Ce temoin **rejoue la meme arithmetique en PHP**, avec les
     * constantes lues dans le module — pas les miennes —, et exige la distance minimale sur les 105
     * paires. Il prouve la formule et ses parametres ; il ne prouve pas le JavaScript lui-meme, qui
     * n'a pas de banc ici. Une divergence entre les deux se verrait a l'ecran, pas ici : le dire.
     */
    public function testTheLayoutKeepsEveryPairOfBodiesApart(): void
    {
        $positions = (int)$this->constanteDuModule('POSITIONS');
        $rayonMin = $this->constanteDuModule('RAYON_MIN');
        $rayonMax = $this->constanteDuModule('RAYON_MAX');
        $aplat = $this->constanteDuModule('APLATISSEMENT');
        $angleOr = $this->constanteDuModule('ANGLE_OR');
        $distanceMin = $this->constanteDuModule('DISTANCE_MIN');
        $passes = (int)$this->constanteDuModule('PASSES_D_ECARTEMENT');

        $rayon = static fn (int $p): float => $rayonMin + (($p - 1) * ($rayonMax - $rayonMin)) / ($positions - 1);
        $point = static fn (int $p, float $a) => [$rayon($p) * cos($a), $rayon($p) * $aplat * sin($a)];

        $angles = [];

        for ($i = 1; $i <= $positions; $i++) {
            $angles[$i] = (($i * $angleOr - 90) * M_PI) / 180;
        }

        for ($passe = 0; $passe < $passes; $passe++) {
            for ($i = 1; $i <= $positions; $i++) {
                for ($j = $i + 1; $j <= $positions; $j++) {
                    [$ax, $ay] = $point($i, $angles[$i]);
                    [$bx, $by] = $point($j, $angles[$j]);
                    $d = sqrt(($bx - $ax) ** 2 + ($by - $ay) ** 2);

                    if ($d >= $distanceMin) {
                        continue;
                    }

                    $manque = ($distanceMin - $d) / 2;
                    $ecart = atan2(sin($angles[$j] - $angles[$i]), cos($angles[$j] - $angles[$i]));
                    $sens = $ecart >= 0 ? 1 : -1;
                    $angles[$i] -= ($sens * $manque) / $rayon($i);
                    $angles[$j] += ($sens * $manque) / $rayon($j);
                }
            }
        }

        $plusProche = INF;

        for ($i = 1; $i <= $positions; $i++) {
            for ($j = $i + 1; $j <= $positions; $j++) {
                [$ax, $ay] = $point($i, $angles[$i]);
                [$bx, $by] = $point($j, $angles[$j]);
                $plusProche = min($plusProche, sqrt(($bx - $ax) ** 2 + ($by - $ay) ** 2));
            }
        }

        $this->assertGreaterThanOrEqual(
            $distanceMin - 0.5,
            $plusProche,
            sprintf('Two bodies end up %.1f px apart with these constants: planets would stack again.', $plusProche)
        );

        /* Et l'ellipse la plus large tient dans la carte, corps compris. */
        $this->assertLessThanOrEqual(656 / 2 - 24, $rayonMax, 'The outer orbit runs past the map edge.');
    }

    /**
     * La nebuleuse n'a pas le halo rond des planetes, et la boite historique s'y range proprement.
     */
    public function testTheNebulaAndItsCardLookLikeThemselves(): void
    {
        $feuille = $this->feuille();

        $this->assertMatchesRegularExpression(
            '/#galaxyTactical \.gtDeepSpace::before \{[^}]*display:\s*none/',
            $feuille,
            'The round planet halo is back on the nebula: a ring around a cloud, the effect Keven called weird.'
        );

        $this->assertMatchesRegularExpression(
            '/\.gtCardBody \.expeditionDebrisSlotBox h3\.title \{[^}]*display:\s*none/',
            $feuille,
            'The historical box repeats its own title under the card head.'
        );

        $this->assertStringContainsString(
            '#expeditionDebrisSlotDebrisContainer:not(:has(*))',
            $feuille,
            'An empty debris container keeps a line of blank space in the deep-space card.'
        );
    }

    /**
     * Le soleil vit, et se fige sous la reduction des mouvements — pseudo-elements compris, que `*`
     * n'atteint pas.
     */
    public function testTheSunIsAnimatedAndStillsUnderReducedMotion(): void
    {
        $module = $this->module();
        $feuille = $this->feuille();

        /*
         * Le soleil est celui du pack (revue 113 de Codex) : l'image `sun-detailed-v1.png` dans le
         * composant `.ogx-sun`, dont la feuille anime le halo et la lumiere. Le SVG au bruit fractal
         * a disparu entierement — un seul soleil, un seul halo.
         */
        $this->assertStringContainsString("var SOLEIL_DE_CODEX = '/img/galaxy-tactical/sun-detailed-v1.png';", $module, 'The map no longer uses the Codex sun image.');
        $this->assertStringContainsString("element('span', 'ogx-sun')", $module, 'The sun is no longer wrapped in the .ogx-sun component: its CSS effects do not apply.');
        $this->assertStringContainsString("composant.setAttribute('aria-hidden', 'true');", $module, 'The decorative sun is exposed to screen readers.');
        $this->assertStringNotContainsString('<feTurbulence', $module, 'The old SVG sun is back: two suns, two halos.');

        $this->assertFileExists(public_path('img/galaxy-tactical/sun-detailed-v1.png'), 'The Codex sun image is missing.');

        /* L'alpha du PNG est reel : le coin est transparent, pas un carre noir sur la carte. */
        $image = imagecreatefrompng(public_path('img/galaxy-tactical/sun-detailed-v1.png'));
        $this->assertNotFalse($image);
        $this->assertGreaterThanOrEqual(120, (imagecolorat($image, 0, 0) >> 24) & 0x7F, 'The sun PNG lost its transparency.');

        /* La feuille de Codex, reprise telle quelle : les trois animations et leur extinction. */
        foreach (['@keyframes ogx-sun-light {', '@keyframes ogx-sun-corona {', '@keyframes ogx-sun-flare {'] as $animation) {
            $this->assertStringContainsString($animation, $feuille, 'The sun animation ' . $animation . ' is gone.');
        }

        $this->assertStringContainsString(
            '@media (prefers-reduced-motion: reduce) { .ogx-sun img, .ogx-sun::before, .ogx-sun::after { animation: none; } }',
            $feuille,
            'Reduced motion no longer stills the sun: the universal selector does not reach pseudo-elements.'
        );

        $this->assertDoesNotMatchRegularExpression('/#galaxyTactical \.gtSunSvg/', $feuille, 'The old SVG sun rules are back.');
    }

    /**
     * **Le survol est un rond de la grosseur de la planete, et rien d'autre.**
     *
     * Keven : « je veux pas le carre, je veux juste l'effet de genre planete, le rond » — sur une
     * position libre comme sur une planete prise. Le bloc ne dessine ni fond ni bordure ; l'anneau
     * fait 44 px, la taille de la texture ; la silhouette d'une position libre fait la meme taille
     * pour que l'anneau soit le meme.
     */
    public function testHoverIsARingTheSizeOfThePlanetAndNothingElse(): void
    {
        $feuille = $this->feuille();

        $this->assertMatchesRegularExpression(
            '/#galaxyTactical \.gtBody:hover,\s*#galaxyTactical \.gtBody:focus-visible,\s*#galaxyTactical \.gtBody\.gtSelected \{[^}]*background: none;[^}]*border-color: transparent;/s',
            $feuille,
            'Hover draws a box around the body again: Keven asked for the ring alone.'
        );

        $this->assertMatchesRegularExpression(
            '/#galaxyTactical \.gtBody::before \{[^}]*width: 44px;[^}]*height: 44px;[^}]*border-radius: 50%;/s',
            $feuille,
            'The hover ring is no longer a 44px circle: it does not match the texture.'
        );

        $this->assertStringNotContainsString(
            'planet-hover-halo.svg',
            $feuille,
            'The hover ring is an image again: scaled, blurry, and not the size of the planet.'
        );

        $this->assertMatchesRegularExpression(
            '/#galaxyTactical \.gtEmpty \{[^}]*width: 44px;[^}]*height: 44px;/s',
            $feuille,
            'The free-slot silhouette is smaller than the ring: hovering an empty slot shows a ring around nothing.'
        );
    }

    /**
     * La boite historique de l'espace profond, dans la fiche, ne garde ni ses largeurs en pour cent
     * ni le bloc de son titre — c'etait le vide de la capture de Keven.
     */
    public function testTheDeepSpaceCardUnfoldsTheHistoricalBox(): void
    {
        $feuille = $this->feuille();

        $this->assertMatchesRegularExpression(
            '/\.gtCardBody \.expeditionDebrisSlotBox > div \{[^}]*width: auto;/s',
            $feuille,
            'The historical box keeps its percentage widths in the card: its blocks are crushed and a blank appears.'
        );

        $this->assertStringContainsString(
            '.expeditionDebrisSlotBox > div:has(> h3.title)',
            $feuille,
            'The block that only carried the hidden title stays in the card as an empty flex item.'
        );
    }

    /**
     * **Un vol vers un autre systeme se suit d'un systeme a l'autre.**
     *
     * Keven voyait une sonde aller au bord pendant tout le vol puis « rebondir » avec le retour. Le
     * modele : la premiere moitie du vol se joue dans le systeme de depart (planete → bord), la
     * seconde dans celui de l'arrivee (bord → cible) ; pendant l'autre moitie le marqueur est au bord,
     * en transit, avec un bouton qui charge l'autre systeme pour suivre la flotte. « Si je vais dans
     * l'autre systeme je veux la voir continuer jusqu'a la planete » — c'est ce bouton, et cette
     * seconde moitie.
     */
    public function testAnInterSystemFlightIsFollowedFromOneSystemToTheNext(): void
    {
        $module = $this->module();

        /*
         * Decision de Keven : pas de traversee des systemes intermediaires — un quart du vol pour
         * sortir, un quart pour entrer, et entre les deux la flotte est en hyperespace : le vaisseau
         * n'est sur aucune carte, une trainee au bord le dit a sa place.
         */
        $this->assertStringContainsString('var PART_LOCALE = 0.25;', $module, 'The local share of an inter-system flight changed: the two views disagree on where the fleet is.');
        $this->assertStringContainsString("classList.toggle('gtWarpActive', local.enTransit)", $module, 'The hyperspace streak no longer follows the transit state.');
        $this->assertStringContainsString("locaDeLaCarte(carte, 'hyperspace'", $module, 'The streak no longer says the fleet is in hyperspace.');

        $feuille = $this->feuille();
        $this->assertMatchesRegularExpression('/#galaxyTactical \.gtFleetMarker\.gtInTransit \{[^}]*display: none/', $feuille, 'A fleet in hyperspace is still drawn at the edge: it is on no map at that moment.');
        $this->assertStringContainsString('@keyframes gtWarpFlow {', $feuille, 'The hyperspace streak no longer flows.');
        $this->assertStringContainsString('data-loca-hyperspace=', $this->vue(), 'The view no longer publishes the hyperspace label.');

        foreach (['fr', 'en'] as $langue) {
            $lignes = require resource_path('lang/' . $langue . '/t_ingame.php');
            $this->assertArrayHasKey('tactical_hyperspace', $lignes['galaxy'], 'The hyperspace label is missing in ' . $langue . '.');
        }
        $this->assertStringContainsString('global >= PART_LOCALE', $module, 'An outbound flight no longer leaves the system after its local share.');
        $this->assertStringContainsString('(global - (1 - PART_LOCALE)) / PART_LOCALE', $module, 'An inbound flight no longer enters during its local share: it would sit at the edge until arrival.');
        $this->assertStringContainsString("classList.toggle('gtInTransit', local.enTransit)", $module, 'A fleet in the other system is no longer marked in transit.');
        $this->assertStringContainsString("'route-jump-destination'", $module, 'The edge no longer offers the jump to the destination system: the player cannot follow the fleet.');
        $this->assertStringContainsString('allerAuSysteme(Number(autre.galaxy), Number(autre.system))', $module, 'The jump button no longer loads the other system.');

        $this->assertMatchesRegularExpression(
            '/#galaxyTactical \.gtJump \{[^}]*pointer-events: auto/',
            $this->feuille(),
            'The jump button inherits pointer-events: none from the layer: it cannot be clicked.'
        );

        $this->assertStringContainsString('data-loca-follow=', $this->vue(), 'The view no longer publishes the follow label.');

        foreach (['fr', 'en'] as $langue) {
            $lignes = require resource_path('lang/' . $langue . '/t_ingame.php');
            $this->assertArrayHasKey('tactical_follow', $lignes['galaxy'], 'The follow label is missing in ' . $langue . '.');
        }
    }

    /**
     * **Le marqueur d'une flotte est le vaisseau blanc de la page de mouvement, tourne dans le sens
     * du vol.**
     *
     * Keven : « les petits vaisseaux blancs animes du voyage, et qu'il pointe dans la bonne direction ».
     * Le GIF regarde vers la droite (lu : cap natif 0 degre) ; l'angle du segment depart → arrivee le
     * fait pointer la ou il va. Les missiles gardent leur propre marqueur.
     */
    public function testTheFleetMarkerIsTheWhiteShipPointingAlongItsPath(): void
    {
        $module = $this->module();

        $this->assertStringContainsString(
            "var VAISSEAU_BLANC = '/img/icons/f9cb590cdf265f499b0e2e5d91fc75.gif';",
            $module,
            'The fleet marker is no longer the white ship of the movement page.'
        );

        $this->assertFileExists(public_path('img/icons/f9cb590cdf265f499b0e2e5d91fc75.gif'), 'The white ship GIF is missing.');

        $this->assertStringContainsString(
            'Math.atan2(bouts.arrivee.y - bouts.depart.y, bouts.arrivee.x - bouts.depart.x)',
            $module,
            'The heading is no longer computed from the trajectory: the ship would not point where it goes.'
        );

        $this->assertStringContainsString(
            "transform: 'rotate(' + capDe(bouts).toFixed(1) + ')'",
            $module,
            'The ship is no longer rotated by its heading.'
        );

        $this->assertStringContainsString(
            "'missile-incoming' : 'missile-outgoing'",
            $module,
            'Missiles lost their own marker: a missile would look like a ship.'
        );
    }

    /**
     * **La fenetre d'hyperespace se joue une fois par transition observee, et a l'envers a la sortie.**
     *
     * Ressource de Codex (revue 114, `wormhole-entry.js`), reprise dans `galaxy-wormhole.js` et
     * concatenee **avant** la carte. La carte ne la joue que sur une transition qu'elle a vue —
     * l'etat precedent connu et different —, jamais au premier trace, au retour dans le systeme ou a
     * la reconnexion ; un registre par mission et par phase ferme le doublon ; le changement de
     * systeme annule tout. La sortie remonte les images par `previewAt(D − t)`.
     */
    public function testTheHyperspaceWindowPlaysOnceOnEachObservedTransition(): void
    {
        $module = $this->module();
        $ressource = file_get_contents(resource_path('js/ingame/galaxy-wormhole.js'));
        $this->assertIsString($ressource, 'The wormhole resource is missing.');

        $this->assertStringContainsString('window.playOGameXWormhole = function (canvas) {', $ressource, 'The wormhole resource no longer exposes playOGameXWormhole.');
        $this->assertStringContainsString("matchMedia('(prefers-reduced-motion:reduce)')", $ressource, 'The wormhole ignores prefers-reduced-motion.');

        $configuration = file_get_contents(base_path('vite.config.js'));
        $this->assertIsString($configuration);
        $this->assertLessThan(
            strpos($configuration, "'resources/js/ingame/galaxy-tactical.js'"),
            strpos($configuration, "'resources/js/ingame/galaxy-wormhole.js'"),
            'The wormhole resource is concatenated after the map: playOGameXWormhole is undefined when the map needs it.'
        );

        $this->assertStringContainsString("mouvement._etatPrecedent !== undefined && mouvement._etatPrecedent !== local.enTransit", $module, 'The window no longer waits for an observed transition: it would replay on every refresh or reconnection.');
        $this->assertStringContainsString("jouerLaFenetre(mouvement, 'entree', mouvement._bouts.arrivee, false)", $module, 'Entering hyperspace no longer opens the window at the exit edge.');
        $this->assertStringContainsString("jouerLaFenetre(mouvement, 'sortie', mouvement._bouts.depart, true)", $module, 'Leaving hyperspace no longer plays the window reversed at the entry edge.');
        $this->assertStringContainsString("if (fenetresJouees[clef] ||", $module, 'The per-mission-and-phase registry is gone: the window can play twice.');
        $this->assertStringContainsString('effet.previewAt(FENETRE_DUREE - ecoule);', $module, 'The reversed playback no longer drives previewAt backwards.');
        $this->assertStringContainsString("        annulerLesFenetres();\n", str_replace("\r\n", "\n", $module), 'Changing system no longer cancels the windows in flight.');

        $this->assertMatchesRegularExpression('/#galaxyTactical \.gtWormhole \{[^}]*pointer-events: none/', $this->feuille(), 'The wormhole canvas catches pointer events.');
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
