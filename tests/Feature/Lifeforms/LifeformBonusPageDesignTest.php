<?php

namespace Tests\Feature\Lifeforms;

use Tests\TestCase;

/**
 * **La presentation de la page des bonus des formes de vie** (releve de Codex, 20 septembre 2026, journal §171).
 *
 * Ce que Keven voyait : des barres de titre qui depassaient des panneaux, des bandes de cadre vides au-dessus des
 * sections, quatre portraits tasses au centre, et des textes colles aux bordures.
 *
 * Les causes, mesurees au navigateur avant toute correction :
 *
 * - les trois images du cadre font **667 px** de large, la boite **670** : `no-repeat` laissait 3 px nus a droite,
 *   `repeat-y` une raie nue sur toute la hauteur ;
 * - `bonus-item-heading` fait `width: 100%` et porte ses embouts a `left: -5px` / `right: -5px` : ils sortaient de
 *   5 px de chaque cote (mesure : **+5** avant, **-6** apres) ;
 * - une rangee flex centree donnait **77 px** par espece dans 662 (mesure apres : **151,5 px**, quatre colonnes) ;
 * - `bonus-item-content-holder` n avait que 4 px de retrait, et la plus petite police valait **9 px**.
 *
 * L effet se prouve au navigateur, pas ici : ces temoins tiennent la **forme** des regles et surtout deux choses
 * qu une relecture ne voit pas — que la feuille **servie** les porte (une source corrigee dont le bundle n est pas
 * reconstruit ne change rien en jeu), et que **rien ne fuit hors de cette page**.
 */
class LifeformBonusPageDesignTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function manifeste(): array
    {
        $manifeste = json_decode((string)file_get_contents(public_path('build/manifest.json')), true);
        $this->assertIsArray($manifeste);

        return $manifeste;
    }

    private function feuilleServie(): string
    {
        $chemin = public_path('build/' . $this->manifeste()['resources/css/ingame.css']['file']);
        $this->assertFileExists($chemin, 'Le manifeste designe une feuille qui n est pas commitee.');

        return (string)file_get_contents($chemin);
    }

    private function source(): string
    {
        return str_replace("\r\n", "\n", (string)file_get_contents(resource_path('css/ingame/lifeform-bonuses-azria.css')));
    }

    private function gabarit(): string
    {
        return str_replace("\r\n", "\n", (string)file_get_contents(resource_path('views/ingame/lifeforms/bonuses.blade.php')));
    }

    public function testThePageStylesheetIsImportedAndReachesTheServedSheet(): void
    {
        $entree = str_replace("\r\n", "\n", (string)file_get_contents(resource_path('css/ingame.css')));
        $this->assertStringContainsString(
            '@import "ingame/lifeform-bonuses-azria.css";',
            $entree,
            'La feuille de la page doit etre importee, sinon elle n arrive jamais dans le bundle.'
        );

        $servie = $this->feuilleServie();
        foreach ([
            '#lfbonusescomponent .headerRS,#lfbonusescomponent .footerRS{background-size:100% 29px}' => 'Le cadre epouse la boite : sans cela, une bande de 3 px reste nue a droite.',
            '#lfbonusescomponent .mainRS{background-size:100%' => 'Le corps du cadre aussi, sur toute sa hauteur.',
            '#lfbonusescomponent bonus-item-heading{box-sizing:border-box;width:auto;margin-left:11px;margin-right:11px}' => 'La barre de titre rentre dans le cadre, embouts compris.',
            '#lfbonusescomponent bonus-item-content-holder{padding:10px 11px 12px}' => 'Le contenu ne colle plus aux bordures.',
            '#lfbonusescomponent .lifeform-species-grid{grid-template-columns:repeat(4,1fr)' => 'Les quatre especes tiennent quatre colonnes regulieres.',
            '#lfbonusescomponent .smallFont{font-size:11px' => 'Les petits textes de cette page sont lisibles.',
            '#lfbonusescomponent lifeform-avatar .lifeform-item-icon{width:80px;height:80px}' => 'L icone fait 80 px, sans quoi l anneau d experience de 88 deborde.',
        ] as $regle => $pourquoi) {
            $this->assertStringContainsString($regle, $servie, $pourquoi);
        }
    }

    /**
     * **Aucune regle ne sort de cette page.** Les batiments, les recherches et les autres panneaux emploient
     * `.headerRS`, `.mainRS` et `bonus-item-heading` : une seule regle non prefixee les habillerait aussi.
     */
    public function testEverySelectorOfThePageStylesheetIsScopedToThisPage(): void
    {
        $source = (string)preg_replace('#/\*.*?\*/#s', '', $this->source());
        $this->assertSame(
            1,
            preg_match_all('#([^{}]+)\{[^{}]*\}#s', $source, $blocs) > 0 ? 1 : 0,
            'La feuille contient des regles.'
        );

        $selecteurs = [];
        foreach ($blocs[1] as $groupe) {
            foreach (explode(',', $groupe) as $selecteur) {
                $selecteur = trim($selecteur);
                if ($selecteur !== '') {
                    $selecteurs[] = $selecteur;
                }
            }
        }

        $this->assertGreaterThan(15, count($selecteurs), 'La feuille porte bien toutes ses regles.');
        foreach ($selecteurs as $selecteur) {
            $this->assertStringStartsWith(
                '#lfbonusescomponent',
                $selecteur,
                "« $selecteur » n est pas limite a la page des bonus : il habillerait aussi les autres panneaux."
            );
        }
    }

    /**
     * **Le debordement se corrige, il ne se masque pas** (consigne explicite de Codex) : ni `overflow: hidden`
     * pose pour cacher ce qui depasse, ni reduction globale de la page.
     */
    public function testNothingIsHiddenBehindAnOverflowRule(): void
    {
        $source = (string)preg_replace('#/\*.*?\*/#s', '', $this->source());
        $this->assertStringNotContainsString('overflow: hidden', $source, 'Un debordement masque reste un debordement.');
        $this->assertStringNotContainsString('overflow:hidden', $source);
        $this->assertStringNotContainsString('text-overflow: ellipsis', $source, 'Un nom long revient a la ligne, il n est pas coupe.');
        $this->assertStringNotContainsString('zoom:', $source, 'La page ne se reduit pas pour faire disparaitre ses defauts.');
        $this->assertStringNotContainsString('transform: scale', $source);
    }

    /**
     * Le gabarit : plus de chapeau vide, plus de style en ligne — un style en ligne bat n importe quelle feuille,
     * et c est ainsi que ces reglages echappaient a toute correction.
     */
    public function testTheTemplateCarriesNoEmptyCapAndNoInlineLayout(): void
    {
        $gabarit = $this->gabarit();

        $this->assertSame(
            0,
            preg_match('#<div class="headerRS"#', $gabarit),
            'Le chapeau `.headerRS` est vide sur cette page : il se lisait comme une bande de cadre nue.'
        );
        $this->assertStringContainsString('class="mainRS lifeform-bonus-box"', $gabarit, 'La boite commence par sa barre de titre.');
        $this->assertStringContainsString('<div class="lifeform-species-grid">', $gabarit, 'Les especes passent par la grille, pas par un style en ligne.');
        $this->assertStringContainsString('class="smallFont lifeform-bonus-intro"', $gabarit);
        $this->assertStringContainsString('class="smallFont lifeform-bonus-empty"', $gabarit, 'Le message « aucun bonus » a sa classe, donc ses marges.');
        $this->assertStringContainsString('class="smallFont lifeform-bonus-row"', $gabarit);

        // Le seul style en ligne qui reste est la part de l anneau d experience, calculee par espece.
        preg_match_all('#style="([^"]*)"#', $gabarit, $styles);
        $this->assertCount(1, $styles[1], 'Un seul style en ligne subsiste : ' . implode(' | ', $styles[1]));
        $this->assertStringContainsString('stroke-dasharray', $styles[1][0], 'Et c est la part de l anneau, qui depend de la progression.');
    }

    /**
     * **Les sections se replient vraiment.** La fleche et le curseur « main » l annoncaient depuis toujours — le
     * composant vient de la page officielle — mais aucun script n ecoutait cette barre : le clic ne faisait rien.
     */
    public function testTheSectionsCanActuallyBeCollapsed(): void
    {
        $gabarit = $this->gabarit();
        $this->assertStringContainsString('closest("bonus-item-heading")', $gabarit, 'Un clic sur la barre de titre est ecoute.');
        $this->assertStringContainsString('classList.toggle("active")', $gabarit, 'Et il bascule l etat de la section.');
        $this->assertStringContainsString('aria-expanded', $gabarit, 'L etat est annonce, pas seulement dessine.');

        $servie = $this->feuilleServie();
        $this->assertStringContainsString(
            '#lfbonusescomponent bonus-item-heading:not(.active)+bonus-item-content-holder{display:none}',
            $servie,
            'Repliee, la section cache son contenu — sinon la bascule ne se verrait pas.'
        );
        $this->assertStringContainsString(
            '#lfbonusescomponent bonus-item-heading:not(.active) arrow-icon',
            $servie,
            'Et la fleche montre l etat : la regle officielle ne dessine le triangle que sur `.active`.'
        );
    }
}
