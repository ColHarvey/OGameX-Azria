<?php

namespace Tests\Feature;

use Tests\UnitTestCase;

/**
 * La vue generale peut-elle encore se defiler quand son contenu s'allonge ?
 *
 * La mise en page est historiquement construite en flottants, sur deux niveaux : `#inhalt`
 * flotte dans `#contentWrapper`, et `#contentWrapper`, `#links` et `#rechts` flottent dans
 * `#box`. Un conteneur dont tous les enfants flottent a une hauteur calculee de zero : des
 * qu'une file de construction allongeait la page, le contenu debordait de la hauteur
 * declaree, le navigateur ne voyait rien a faire defiler, et le panneau du bas — le taux de
 * provocation — devenait inatteignable.
 *
 * Ce test surveille le report a la main entre la source et l'asset construit. C'est l'erreur
 * la plus probable de ce depot : `npm run build` est impossible ici — le conteneur part de
 * `php:8.5-fpm` et n'embarque pas Node — donc les assets sont edites et commites a la main,
 * et une correction CSS qui ne vit que dans la source n'a aucun effet en jeu.
 */
class OverviewScrollFixTest extends UnitTestCase
{
    /**
     * Assert that the source sheet carries the clearfix and is loaded last.
     */
    public function testTheSourceSheetCarriesTheClearfixAndIsLoadedLast(): void
    {
        $feuille = file_get_contents(resource_path('css/ingame/azria.css'));
        $this->assertIsString($feuille, 'The project override sheet is missing.');

        foreach (['#contentWrapper::after', '#box::after'] as $selecteur) {
            $this->assertStringContainsString(
                $selecteur,
                $feuille,
                "The override sheet no longer clears the floats on {$selecteur}, so that container collapses to zero height."
            );
        }

        $this->assertStringContainsString('clear: both', $feuille, 'The clearfix no longer clears anything.');

        // L'ordre compte : la feuille doit etre importee apres toutes les feuilles
        // historiques, sinon ses regles perdent a specificite egale.
        $entree = file_get_contents(resource_path('css/ingame.css'));
        $this->assertIsString($entree);

        // Les blocs de commentaires du fichier contiennent d anciens @import desactives :
        // les compter ferait croire que notre feuille n est pas la derniere.
        $actif = preg_replace("#/\*.*?\*/#s", "", $entree);
        $this->assertIsString($actif);

        $imports = [];
        foreach (explode("\n", $actif) as $ligne) {
            if (str_starts_with(trim($ligne), '@import')) {
                $imports[] = trim($ligne);
            }
        }

        $notre = null;
        foreach ($imports as $rang => $import) {
            if (str_contains($import, 'ingame/azria.css')) {
                $notre = $rang;
            }
        }

        $this->assertNotNull($notre, 'The override sheet is not imported at all, so none of its rules reach the game.');
        $this->assertSame(
            count($imports) - 1,
            $notre,
            'The override sheet is no longer imported last, so a historical sheet can win over it.'
        );
    }

    /**
     * Assert that the clearfix reached the stylesheet the game actually serves.
     *
     * C'est la moitie qui compte pour le joueur : la source ne descend jamais dans le
     * navigateur, seul l'asset construit est servi.
     */
    public function testTheClearfixReachedTheServedStylesheet(): void
    {
        $manifeste = json_decode((string)file_get_contents(public_path('build/manifest.json')), true);
        $this->assertIsArray($manifeste);

        $chemin = $manifeste['resources/css/ingame.css']['file'] ?? null;
        $this->assertIsString($chemin, 'The manifest declares no built ingame stylesheet.');

        $css = file_get_contents(public_path('build/' . $chemin));
        $this->assertIsString($css, "The manifest points at {$chemin}, which does not exist.");

        foreach (['#contentWrapper::after', '#box::after'] as $selecteur) {
            $this->assertStringContainsString(
                $selecteur,
                $css,
                "The served stylesheet does not clear the floats on {$selecteur}: the fix lives only in the source and has no effect in game."
            );
        }

        // Une feuille tronquee est aussi grave qu'une feuille non reportee, et le report se
        // fait a la main : on verifie que le fichier reste syntaxiquement clos.
        $this->assertSame(
            substr_count($css, '{'),
            substr_count($css, '}'),
            'The served stylesheet has unbalanced braces, so part of it is not applied.'
        );
    }
}
