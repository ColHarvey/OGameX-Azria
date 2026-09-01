<?php

namespace Tests\Feature;

use OGame\Facades\AppUtil;
use Tests\UnitTestCase;

/**
 * Les durees ecrites par le serveur disent-elles la verite ?
 *
 * `AppUtil::formatTimeDuration` alimente la duree de vol affichee en galaxie, les temps de
 * construction des panneaux de detail, et le premier rendu des files. Elle ecrivait ses
 * unites en anglais en dur dans un jeu francise, et surtout elle **jetait des segments** :
 *
 *      2809 s  ->  « 46m »   au lieu de 46 min 49 s   (49 secondes perdues)
 *      7205 s  ->  « 2h »    au lieu de 2 h 00 min 05 s
 *
 * C'est le meme defaut de justesse que celui corrige dans le compte a rebours JavaScript, et
 * les deux suivent desormais la meme regle : du premier au dernier segment non nul, cadre sur
 * deux chiffres apres le premier, sans troncature.
 *
 * Les deux formateurs restent deux implementations — l'une tourne sur le serveur, l'autre
 * dans le navigateur — mais ils lisent leurs unites dans les memes cles de traduction, de
 * sorte qu'un changement de libelle ne peut pas les faire diverger.
 */
class DurationFormatterTest extends UnitTestCase
{
    /**
     * Assert that every duration is written out exactly, losing nothing.
     */
    public function testDurationsAreWrittenOutExactly(): void
    {
        $this->app->setLocale('fr');

        $attendu = [
            0 => '0 s',
            5 => '5 s',
            46 => '46 s',
            300 => '5 min',
            2809 => '46 min 49 s',
            3600 => '1 h',
            7205 => '2 h 00 min 05 s',
            7380 => '2 h 03 min',
            90000 => '1 j 01 h',
            604800 => '1 sem',
            4233599 => '6 sem 06 j 23 h 59 min 59 s',
        ];

        foreach ($attendu as $secondes => $texte) {
            // Les unites francaises portent une espace insecable ; on compare sur la valeur
            // affichee, l'insecable etant verifiee par CountdownFormatterTest.
            $obtenu = str_replace("\u{00A0}", ' ', AppUtil::formatTimeDuration($secondes));

            $this->assertSame($texte, $obtenu, "The duration for {$secondes} seconds is not written out exactly.");
        }
    }

    /**
     * Assert that a duration never drops its trailing seconds.
     *
     * Le cas 7205 est fige a part : c'est celui qui rendait la duree fausse de cinq secondes,
     * et le seul ou un segment nul est encadre par deux segments non nuls.
     */
    public function testATrailingSecondIsNeverDropped(): void
    {
        $this->app->setLocale('fr');

        $obtenu = str_replace("\u{00A0}", ' ', AppUtil::formatTimeDuration(7205));

        $this->assertSame('2 h 00 min 05 s', $obtenu);
        $this->assertStringEndsWith('05 s', $obtenu, 'The duration drops its seconds, so it announces an arrival earlier than it happens.');
    }

    /**
     * Assert that the units are translated instead of hardcoded in English.
     */
    public function testTheUnitsAreTranslated(): void
    {
        $this->app->setLocale('fr');
        $francais = str_replace("\u{00A0}", ' ', AppUtil::formatTimeDuration(7380));

        $this->app->setLocale('en');
        $anglais = AppUtil::formatTimeDuration(7380);

        $this->assertSame('2 h 03 min', $francais);
        $this->assertSame('2h 03m', $anglais, 'The English rendering changed, so the units are no longer read from the translations.');
        $this->assertNotSame($francais, $anglais, 'The duration reads the same in both languages, so the units are hardcoded.');
    }

    /**
     * Assert that a float duration raises no deprecation on PHP 8.5.
     *
     * L'ancienne version appliquait l'operateur modulo a un float, ce que PHP 8.5 signale par
     * un Deprecated a chaque appel — et cette methode est appelee sur chaque objet de chaque
     * panneau de detail.
     */
    public function testAFloatDurationRaisesNoDeprecation(): void
    {
        // La locale est posee explicitement : un test precedent la laisse en anglais, et
        // l assertion sur la valeur exacte porterait alors sur le mauvais rendu.
        $this->app->setLocale("fr");

        $signales = [];

        set_error_handler(static function (int $niveau, string $message) use (&$signales): bool {
            $signales[] = $message;

            return true;
        }, E_DEPRECATED | E_USER_DEPRECATED);

        try {
            $obtenu = str_replace("\u{00A0}", ' ', AppUtil::formatTimeDuration(7205.7));
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $signales, 'Formatting a float duration still raises a deprecation.');
        $this->assertSame('2 h 00 min 05 s', $obtenu, 'A float duration is no longer truncated towards its exact value.');
    }

    /**
     * Assert that both formatters read their units from the same translation keys.
     *
     * C'est ce qui les empeche de diverger : il n'y a qu'une source de libelles, meme si le
     * rendu se fait a deux endroits.
     */
    public function testBothFormattersShareTheSameUnitKeys(): void
    {
        $source = file_get_contents(app_path('Facades/AppUtil.php'));
        $this->assertIsString($source);

        $this->assertStringContainsString(
            "'t_ingame.layout.timeunit_'",
            $source,
            'The PHP formatter no longer reads its units from the shared translation keys.'
        );

        $gabarit = file_get_contents(resource_path('views/ingame/layouts/main.blade.php'));
        $this->assertIsString($gabarit);

        $this->assertStringContainsString(
            't_ingame.layout.timeunit_minute',
            $gabarit,
            'The JavaScript countdown no longer receives its units from the shared translation keys.'
        );
    }
}
