<?php

namespace Tests\Feature;

use Tests\UnitTestCase;

/**
 * Ce qui tient la carte tactique debout, et qui tomberait sans bruit.
 *
 * ## Pourquoi ces temoins lisent des fichiers
 *
 * La carte est du JavaScript et une feuille de style : aucun essai PHP ne la fera cliquer. Ce
 * fichier ne pretend donc pas eprouver le rendu — il epingle les **quatre faits porteurs** dont
 * depend le raccordement des actions, et dont la rupture ne se verrait nulle part ailleurs qu'en
 * jeu, tardivement.
 *
 * Chacun a ete choisi en se demandant : « si quelqu'un ecrivait ceci autrement, un joueur
 * perdrait-il une fonction sans qu'aucun outil ne le dise ? » Quand la reponse est oui, il y a un
 * temoin.
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
     * Le bandeau de detail existe, et la carte sait ou le trouver.
     *
     * Sans lui, choisir une position deplace la ligne vers `null` : le module sort sans rien faire,
     * et le joueur clique dans le vide sans le moindre message.
     */
    public function testTheDetailStripExistsInTheViewAndTheModuleLooksForIt(): void
    {
        $this->assertStringContainsString(
            'id="galaxyTacticalDetail"',
            $this->vue(),
            'The galaxy view no longer carries the detail strip: selecting a body has nowhere to move its row.'
        );

        $this->assertStringContainsString(
            "getElementById('galaxyTacticalDetail')",
            $this->module(),
            'The module no longer looks for the detail strip.'
        );
    }

    /**
     * **La regle de masquage vise un enfant direct, et tout le raccordement en depend.**
     *
     * La ligne choisie redevient visible pour une seule raison : deplacee dans le bandeau, elle
     * n'est plus un enfant **direct** de `.galaxyTable`. Ecrite en descendant
     * (`.galaxyTable.gtReplaced .ctContentRow`), la meme regle continuerait de l'atteindre partout,
     * et le bandeau s'ouvrirait vide.
     *
     * C'est exactement le genre de reecriture qu'un nettoyage de feuille fait sans y penser. Aucun
     * outil ne la signalerait ; ce temoin, si.
     */
    public function testTheHidingRuleTargetsADirectChildOnly(): void
    {
        $feuille = $this->feuille();

        $this->assertStringContainsString(
            '.galaxyTable.gtReplaced > .ctContentRow',
            $feuille,
            'The hiding rule no longer uses the direct-child combinator: a row moved into the detail strip would stay invisible, and the strip would open empty.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/\.galaxyTable\.gtReplaced\s+\.ctContentRow/',
            $feuille,
            'A descendant-form hiding rule was added: it reaches the moved row wherever it goes, and the detail strip opens empty.'
        );
    }

    /**
     * Le creneau d'espace profond n'est plus masque.
     *
     * Il portait la regle de masquage des lignes du tableau, et la carte ne le dessine pas : les
     * debris d'expedition et le modele de flotte d'expedition avaient purement disparu du jeu. Ce
     * n'est pas une question de style, c'est deux fonctions rendues.
     */
    public function testTheDeepSpaceSlotIsNoLongerHidden(): void
    {
        $this->assertStringNotContainsString(
            '.galaxyTable.gtReplaced > .expeditionDebrisSlotBoxRow',
            $this->feuille(),
            'The deep space slot is hidden again: expedition debris and the expedition fleet template are unreachable, and the tactical map does not draw them either.'
        );
    }

    /**
     * **La ligne est deplacee, jamais recopiee.**
     *
     * Le rendu herite accroche ses gestionnaires sur ces noeuds a chaque passage — infobulles,
     * overlay de missile, demande d'ami, mise a l'ignore. Un `cloneNode` les perdrait **tous**, et
     * en silence : la ligne s'afficherait, les liens seraient la, et rien ne repondrait au clic.
     *
     * C'est le defaut le plus couteux possible ici, parce qu'il ressemble a une reussite.
     */
    public function testTheRowIsMovedAndNeverCloned(): void
    {
        $module = $this->module();

        $this->assertStringContainsString(
            'b.appendChild(ligne)',
            $module,
            'The module no longer moves the table row into the strip.'
        );

        /*
         * Le motif vise l'**appel**, `cloneNode(`, et non le mot. La premiere version cherchait
         * `cloneNode` tout court et rougissait sur le commentaire du module, qui nomme le piege
         * pour l'expliquer. Un temoin qui lit la prose au lieu du code se declenche sur une phrase
         * juste et se tairait sur un appel ecrit autrement.
         */
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
     * position 3, choisie puis quittee, se retrouverait apres la 15. Le tableau reste masque, donc
     * personne ne le verrait — jusqu'au jour ou la classe `gtReplaced` est retiree.
     */
    public function testTheRowGoesBackWhereItCameFrom(): void
    {
        $this->assertStringContainsString(
            'deplacee.parent.insertBefore(deplacee.noeud, deplacee.suivant)',
            $this->module(),
            'The row is not put back at its original rank: the hidden table silently reorders itself with every selection.'
        );
    }

    /**
     * L'attribut `hidden` du bandeau est retabli par une regle d'identite.
     *
     * `hidden` n'agit que par `[hidden] { display: none }` de la feuille du navigateur, de
     * specificite minuscule. Le panneau d'emoji du chat general a coute exactement cette lecon :
     * une regle d'identite qui pose un `display` gagne, et le bandeau ne se referme plus jamais.
     */
    public function testTheStripCanActuallyClose(): void
    {
        $this->assertMatchesRegularExpression(
            '/#galaxyContent \.gtDetail\[hidden\]\s*\{[^}]*display:\s*none/',
            $this->feuille(),
            'Nothing re-establishes [hidden] for the detail strip: any id-level display rule beats the browser default and the strip never closes.'
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
     * Le libelle du bandeau existe dans les deux langues qui portent celui de la carte.
     *
     * `__('t_ingame.galaxy.tactical_detail')` rendrait la clef elle-meme si elle manquait — une
     * chaine lisible, sans erreur, dans un `aria-label`. Le genre de defaut qu'on ne voit jamais.
     */
    public function testTheStripLabelExistsWhereTheMapLabelDoes(): void
    {
        foreach (['fr', 'en'] as $langue) {
            $lignes = require resource_path('lang/' . $langue . '/t_ingame.php');

            $this->assertArrayHasKey(
                'tactical_detail',
                $lignes['galaxy'],
                'The detail strip label is missing in ' . $langue . ': the aria-label would read the translation key itself.'
            );
        }
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
