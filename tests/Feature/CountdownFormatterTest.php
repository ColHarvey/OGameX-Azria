<?php

namespace Tests\Feature;

use Tests\AccountTestCase;

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
class CountdownFormatterTest extends AccountTestCase
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
        // Et cette espace est insecable. Le formateur colle valeur et unite puis joint les
        // segments avec une espace ordinaire : avec une espace ordinaire des deux cotes, un
        // retour a la ligne pouvait tomber entre « 05 » et « s ». L'insecable l'interdit sans
        // empecher la coupure entre segments, la ou elle est souhaitable.
        $this->assertSame("\u{00A0}min", trans('t_ingame.layout.timeunit_minute', [], 'fr'));
        $this->assertSame("\u{00A0}s", trans('t_ingame.layout.timeunit_second', [], 'fr'));
    }

    /**
     * Assert that every declaration covers all the units the formatter walks.
     *
     * Le formateur parcourt week/day/hour/minute/second. Le bloc de repli de main.blade.php
     * n'en declarait que quatre : une duree d'une semaine ou plus y rendait « 1undefined ».
     * Le bloc complet s'executant apres l'ecrasait, ce qui masquait le defaut au lieu de le
     * corriger — un repli incomplet reste un piege pose pour la page suivante.
     *
     * L'invariant tenu ici : toute declaration qui parle de secondes parle aussi de semaines.
     */
    public function testEveryDeclarationCoversAllTheUnitsTheFormatterWalks(): void
    {
        foreach ([
            'resources/views/ingame/layouts/main.blade.php',
            'resources/views/ingame/fleetevents/eventlist.blade.php',
        ] as $gabarit) {
            $contenu = file_get_contents(base_path($gabarit));
            $this->assertIsString($contenu);

            $this->assertSame(
                substr_count($contenu, 't_ingame.layout.timeunit_second'),
                substr_count($contenu, 't_ingame.layout.timeunit_week'),
                "A time-unit declaration in {$gabarit} skips the week, so a duration of seven days or more renders \"undefined\"."
            );
        }
    }

    /**
     * Assert that the non-breaking space survives all the way into the served page.
     *
     * Les autres tests lisent la valeur de traduction, ce qui ne prouve rien sur ce que
     * recoit le navigateur : la chaine traverse `htmlspecialchars` en `{{ }}` dans un cas et
     * `json_encode` dans l'autre, et l'un des deux aurait pu la transformer. On rend donc la
     * page et on regarde l'octet arriver.
     *
     * `json_encode` echappe le non-ASCII en ` `, `{{ }}` laisse passer l'octet brut :
     * les deux formes sont acceptees, c'est leur absence qui serait un defaut.
     */
    public function testTheNonBreakingSpaceReachesTheRenderedPage(): void
    {
        // La locale se lit dans la session (priorite 1 du middleware Locale). Sans elle le
        // test tournerait en anglais, ou les unites n'ont aucune espace — il aurait donc
        // echoue pour la mauvaise raison, en ne prouvant rien sur le francais.
        $reponse = $this->withSession(['locale' => 'fr'])->get('/overview');
        $reponse->assertStatus(200);

        $html = $reponse->getContent();
        $this->assertIsString($html);

        // Deux formes sont possibles et toutes deux conviennent : `{{ }}` laisse passer
        // l'octet brut, tandis que `json_encode` echappe le non-ASCII. C'est leur absence
        // qui serait un defaut.
        $formes = [chr(0xC2) . chr(0xA0) . 'min', chr(92) . 'u00a0min', chr(92) . 'u00A0min'];

        $trouvees = 0;
        foreach ($formes as $forme) {
            $trouvees += substr_count($html, $forme);
        }

        $this->assertGreaterThan(
            0,
            $trouvees,
            'The rendered page carries no non-breaking space before "min", so a line break can separate a number from its unit.'
        );

        // Et plus aucune declaration ne doit porter l'espace ordinaire : il en existe
        // plusieurs sur la page, et une seule oubliee suffit a rouvrir le defaut.
        $this->assertSame(
            0,
            substr_count($html, "' min'") + substr_count($html, '" min"'),
            'The rendered page still declares a breaking space before "min" somewhere.'
        );
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
