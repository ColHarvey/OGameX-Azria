<?php

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\AccountTestCase;

/**
 * Les chaines traduites arrivent-elles lisibles jusqu'au JavaScript ?
 *
 * Elles etaient ecrites `"{{ __('cle') }}"` dans les blocs `<script>`. Blade les echappe alors
 * **pour du HTML** : une apostrophe devient `&#039;`. Tant que la chaine finit dans du HTML,
 * le navigateur la decode et personne ne voit rien. Mais le JavaScript en ecrit une partie
 * avec `.text()`, qui n'interprete pas les entites — le joueur lisait donc, en toutes lettres :
 *
 *      Mission : Rien n&#039;a ete selectionne
 *
 * `@json()` produit un litteral JavaScript correct, sans entite HTML. 352 chaines ont ete
 * converties dans 16 gabarits : pas seulement les quelques-unes visiblement cassees, car une
 * traduction future portant une apostrophe rouvrirait le defaut.
 *
 * Ce qui est **volontairement** laisse tel quel : les chaines qui contiennent du balisage
 * (`"<tr><th>{{ __('cle') }}</th>..."`). Elles sont inserees avec `.html()`, ou l'echappement
 * de Blade est correct et necessaire.
 */
class JavascriptTranslationEscapingTest extends AccountTestCase
{
    /**
     * Assert that no bare translation is HTML-escaped into a script block.
     */
    public function testNoBareTranslationIsHtmlEscapedIntoAScriptBlock(): void
    {
        $fautifs = [];

        $iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views')));

        foreach ($iterateur as $fichier) {
            if (!str_ends_with($fichier->getFilename(), '.blade.php')) {
                continue;
            }

            $contenu = file_get_contents($fichier->getPathname());

            if (!is_string($contenu) || !preg_match_all('#<script\b[^>]*>(.*?)</script>#is', $contenu, $blocs)) {
                continue;
            }

            foreach ($blocs[1] as $bloc) {
                // Uniquement la forme nue : une chaine dont le contenu est exactement une
                // traduction. Celles melees a du balisage sont un autre cas, traite plus bas.
                if (preg_match_all('/"\{\{ *__\(\'([^\']+)\'\) *\}\}"/', $bloc, $trouves)) {
                    foreach ($trouves[1] as $cle) {
                        $fautifs[] = str_replace(resource_path('views') . DIRECTORY_SEPARATOR, '', $fichier->getPathname()) . ' → ' . $cle;
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $fautifs,
            "These translations reach JavaScript HTML-escaped: an apostrophe becomes &#039; and shows literally wherever the script writes it with .text(). Use @json(__('...')) instead.\n  - "
            . implode("\n  - ", array_slice($fautifs, 0, 15))
        );
    }

    /**
     * Assert that no translation is emitted raw into a script block.
     *
     * La tentation, en voyant « &#039; » a l'ecran, est de passer a `{!! __('cle') !!}` : les
     * entites disparaissent et le probleme semble regle. C'est le pire des trois choix — la
     * chaine sort telle quelle, sans echappement JavaScript, et une apostrophe y termine le
     * litteral qui la contient. Une traduction suffit alors a casser tout le script de la page.
     *
     * `@json()` echappe pour le JavaScript, ce qui est le besoin reel.
     *
     * `{!! json_encode(...) !!}` reste legitime et n'est pas vise : la serialisation a deja eu
     * lieu, il ne reste qu'a l'ecrire sans y ajouter d'entites HTML.
     */
    public function testNoTranslationIsEmittedRawIntoAScriptBlock(): void
    {
        $fautifs = [];

        $iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views')));

        foreach ($iterateur as $fichier) {
            if (!str_ends_with($fichier->getFilename(), '.blade.php')) {
                continue;
            }

            $contenu = file_get_contents($fichier->getPathname());

            if (!is_string($contenu) || !preg_match_all('#<script\b[^>]*>(.*?)</script>#is', $contenu, $blocs)) {
                continue;
            }

            foreach ($blocs[1] as $bloc) {
                if (preg_match_all('/\{!! *__\([^)]*\) *!!\}/', $bloc, $trouves)) {
                    foreach ($trouves[0] as $extrait) {
                        $fautifs[] = str_replace(resource_path('views') . DIRECTORY_SEPARATOR, '', $fichier->getPathname()) . ' : ' . $extrait;
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $fautifs,
            "A translation is emitted raw into a script block. An apostrophe in the translated text then closes the JavaScript literal that holds it and breaks the whole page script. Use @json(__('...')) instead.\n  - "
            . implode("\n  - ", array_slice($fautifs, 0, 10))
        );
    }

    /**
     * Assert that the fleet page really delivers a readable apostrophe to its script.
     *
     * Le controle structurel dit que la bonne forme est employee ; celui-ci verifie ce que le
     * navigateur recoit vraiment, sur la chaine meme qui a revele le defaut.
     */
    public function testTheFleetPageDeliversAReadableApostropheToItsScript(): void
    {
        $reponse = $this->withSession(['locale' => 'fr'])->get('/fleet');
        $reponse->assertStatus(200);

        $html = $reponse->getContent();
        $this->assertIsString($html);

        $scripts = '';

        if (preg_match_all('#<script\b[^>]*>(.*?)</script>#is', $html, $blocs)) {
            $scripts = implode("\n", $blocs[1]);
        }

        // « Rien n'a été sélectionné » : la chaine que le joueur voyait ecrite « Rien n&#039;a ».
        $this->assertStringNotContainsString(
            'Rien n&#039;a',
            $scripts,
            'The fleet script still receives the mission label HTML-escaped, so the player reads "Rien n&#039;a été sélectionné".'
        );

        // Elle doit y etre, sous une forme que le JavaScript sait lire : soit l'apostrophe
        // brute, soit son echappement JSON.
        $this->assertTrue(
            str_contains($scripts, "Rien n'a") || str_contains($scripts, 'Rien n' . chr(92) . 'u0027a'),
            'The fleet script no longer carries the mission label at all.'
        );
    }
}
