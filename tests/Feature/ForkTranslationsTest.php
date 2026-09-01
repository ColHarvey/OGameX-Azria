<?php

namespace Tests\Feature;

use Tests\UnitTestCase;

/**
 * Les traductions du fork passent-elles par des cles, ou sont-elles ecrites en dur ?
 *
 * Notre fork avait traduit des messages d'exception et des libelles d'administration en
 * **remplacant un texte anglais code en dur par un texte francais code en dur**. Deux
 * consequences : la regle du projet — aucun texte affiche en dur — etait violee, et sept
 * tests amont qui verifient la chaine anglaise echouaient en permanence.
 *
 * Ces echecs etaient devenus « connus », c'est-a-dire tolores : un echec permanent finit par
 * ne plus rien signaler du tout, et masque celui qui compte. Ce fichier verifie que la
 * correction tient dans les deux langues, ce qu'un test amont seul ne peut pas faire.
 */
class ForkTranslationsTest extends UnitTestCase
{
    /**
     * Assert that the alliance exception messages follow the locale.
     */
    public function testTheAllianceExceptionMessagesFollowTheLocale(): void
    {
        $cas = [
            'err_tag_taken' => ['en' => 'Alliance tag is already taken', 'fr' => 'Ce tag est déjà utilisé'],
            'err_already_in_alliance' => ['en' => 'User is already in an alliance', 'fr' => 'Ce joueur est déjà membre d\'une alliance'],
            'err_cannot_kick_founder' => ['en' => 'Cannot kick the alliance founder', 'fr' => 'Impossible d\'exclure le fondateur de l\'alliance'],
            'err_member_not_found' => ['en' => 'Member not found in alliance', 'fr' => 'Membre introuvable dans l\'alliance'],
        ];

        foreach ($cas as $cle => $attendu) {
            foreach ($attendu as $locale => $texte) {
                $this->assertSame(
                    $texte,
                    trans('t_ingame.alliance.' . $cle, [], $locale),
                    "The alliance message {$cle} is wrong in {$locale}: upstream tests assert the English wording, players read the French one."
                );
            }
        }
    }

    /**
     * Assert that no French wording is hardcoded back into the alliance service.
     *
     * C'est la moitie que la comparaison de traductions ne couvre pas : les cles peuvent etre
     * justes pendant que le code leve une chaine ecrite a la main juste a cote.
     */
    public function testTheAllianceServiceThrowsNoHardcodedFrench(): void
    {
        $source = file_get_contents(app_path('Services/AllianceService.php'));
        $this->assertIsString($source);

        preg_match_all('/throw new Exception\((.+)\);/', $source, $trouves);

        foreach ($trouves[1] as $argument) {
            $this->assertDoesNotMatchRegularExpression(
                '/^[\'"].*(déjà|deja|Vous n\\\'avez|Membre introuvable|caractères|caracteres)/u',
                trim($argument),
                'An exception message is hardcoded in French again instead of using a translation key: ' . trim($argument)
            );
        }
    }

    /**
     * Assert that the administration menu is translated rather than hardcoded.
     */
    public function testTheAdministrationMenuIsTranslated(): void
    {
        $menu = file_get_contents(resource_path('views/ingame/layouts/admin-menu.blade.php'));
        $this->assertIsString($menu);

        foreach (['Server admin', 'Server settings', 'Developer shortcuts', 'Server Administration'] as $cle) {
            $this->assertStringContainsString(
                "@lang('" . $cle . "')",
                $menu,
                "The administration menu no longer translates \"{$cle}\", so upstream tests that assert the English label fail again."
            );
        }

        // Et la valeur francaise doit exister, sinon le menu s'afficherait en anglais en jeu.
        $this->assertSame('Administration', trans('Server admin', [], 'fr'));
        $this->assertSame('Paramètres', trans('Server settings', [], 'fr'));
    }
}
