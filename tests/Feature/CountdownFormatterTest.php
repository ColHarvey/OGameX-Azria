<?php

namespace Tests\Feature;

use Tests\UnitTestCase;

/**
 * Le compte a rebours servi aux joueurs ne perd jamais de secondes.
 *
 * Ce test est structurel, et il l'est par contrainte : `formatTimeWrapper` est du JavaScript,
 * et ce projet n'a pas de quoi l'executer — le conteneur part de `php:8.5-fpm` et n'embarque
 * pas Node, ce qui est aussi la raison pour laquelle les assets sont commites a la main. On
 * verifie donc que le fichier **reellement servi** porte la bonne logique, faute de pouvoir
 * l'appeler.
 *
 * Ce que ce test ne remplace pas : l'execution. Ce qu'il attrape : une regression silencieuse
 * dans l'asset construit, et un report oublie entre la source et le construit — l'erreur la
 * plus probable de ce depot.
 *
 * La regle attendue, telle qu'elle a ete arretee :
 *
 *      46 s  →  46 s
 *    2809 s  →  46 min 49 s
 *    3600 s  →  1 h
 *    7205 s  →  2 h 00 min 05 s     ← jamais « 2 h 00 min »
 *    7380 s  →  2 h 03 min
 *   90000 s  →  1 j 01 h
 */
class CountdownFormatterTest extends UnitTestCase
{
    /**
     * Assert that the served script carries the formatter that never drops a segment.
     */
    public function testTheServedScriptNeverTruncatesTheCountdown(): void
    {
        $script = $this->servedScript();

        // Le premier et le dernier segment non nuls encadrent l'affichage : sans eux, un
        // segment nul intercalaire disparaissait et « 2h 5s » se lisait deux heures cinq.
        $this->assertStringContainsString(
            'let lastUnit = null;',
            $script,
            'The served script has no last-segment detection, so a zero segment in the middle would vanish.'
        );

        $this->assertStringContainsString(
            'if (k === lastUnit) {',
            $script,
            'The served script never stops at the last non-zero segment, so a round hour would read "1 h 00 min".'
        );

        // Et surtout : plus aucune troncature. Couper a deux segments faisait annoncer une
        // arrivee cinq secondes trop tot, ce qui est un defaut de justesse dans un jeu ou les
        // flottes se synchronisent a la seconde.
        $this->assertStringNotContainsString(
            'if (!started || maxDigits <= 0) {',
            $script,
            'The served script still truncates the countdown, so it can announce an arrival earlier than it happens.'
        );

        $this->assertStringNotContainsString(
            'if (maxDigits > 0 && (nv > 0 || zerofill && timeString !== "")) {',
            $script,
            'The served script still carries the original truncating loop.'
        );
    }

    /**
     * Assert that the values are computed on the remainder, not on the whole timestamp.
     *
     * Le defaut valait la peine d'etre fige : diviser le total par chaque unite faisait
     * toujours ressortir les secondes comme dernier segment non nul, ce qui annulait l'arret
     * et rendait la correction inoperante sans qu'aucune erreur ne se produise.
     */
    public function testTheSegmentsAreComputedOnTheRemainder(): void
    {
        $script = $this->servedScript();

        $this->assertStringContainsString(
            'remaining = remaining - values[probe] * timeUnits[probe];',
            $script,
            'The served script divides the whole timestamp instead of the remainder, so the last segment is always the seconds.'
        );
    }

    /**
     * Assert that the time units are translated rather than written in English.
     *
     * Elles vivaient en dur dans trois gabarits, dont un pave JSON qui reecrasait au passage
     * toutes les chaines traduites de la liste d'evenements.
     */
    public function testTheTimeUnitsComeFromTranslations(): void
    {
        foreach ([
            'resources/views/ingame/layouts/main.blade.php',
            'resources/views/ingame/fleetevents/eventlist.blade.php',
        ] as $gabarit) {
            $contenu = file_get_contents(base_path($gabarit));
            $this->assertIsString($contenu);

            $this->assertStringContainsString(
                't_ingame.layout.timeunit_minute',
                $contenu,
                "The time units in {$gabarit} are not translated, so every countdown stays in English."
            );
        }

        // Les unites francaises portent leur espace : le formateur colle valeur et unite,
        // donc « 46 min » ne s'obtient pas autrement.
        $this->assertSame(' min', trans('t_ingame.layout.timeunit_minute', [], 'fr'));
        $this->assertSame(' s', trans('t_ingame.layout.timeunit_second', [], 'fr'));
    }

    /**
     * Get the script the manifest actually serves.
     */
    private function servedScript(): string
    {
        $manifeste = json_decode((string)file_get_contents(public_path('build/manifest.json')), true);
        $this->assertIsArray($manifeste);

        foreach ($manifeste as $entree) {
            $fichier = is_array($entree) ? ($entree['file'] ?? '') : '';

            if (is_string($fichier) && str_starts_with($fichier, 'assets/ingame-') && str_ends_with($fichier, '.js')) {
                $contenu = file_get_contents(public_path('build/' . $fichier));
                $this->assertIsString($contenu);

                return $contenu;
            }
        }

        $this->fail('The manifest declares no built ingame script.');
    }
}
