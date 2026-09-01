<?php

namespace Tests\Feature;

use Tests\AccountTestCase;

/**
 * La premiere etape de la page Flotte annonce-t-elle la bonne cible ?
 *
 * La barre d'etat — mission, cible, joueur — est reecrite par le JavaScript, mais seulement
 * une fois revenu l'appel AJAX `check-target`, c'est-a-dire **a l'etape 2**. Au premier rendu
 * elle affichait donc la planete et le pseudo du joueur lui-meme : arriver depuis la galaxie
 * pour attaquer quelqu'un montrait « [ses propres coordonnees] SaPropreePlanete », alors que
 * l'etape suivante affichait la bonne cible. D'ou l'impression que la page « ne se met pas a
 * jour ».
 *
 * Le nom d'une planete etrangere n'est volontairement pas revele : la galaxie ne le montre pas
 * non plus, et l'afficher ici donnerait un moyen de sonder l'univers sans envoyer de sonde.
 * C'est le libelle generique qui est rendu, exactement comme le fait le JavaScript ensuite.
 */
class FleetTargetDisplayTest extends AccountTestCase
{
    /**
     * Assert that a target passed in the query string is the one displayed.
     */
    public function testTheTargetFromTheGalaxyIsTheOneDisplayed(): void
    {
        // Sans vaisseaux, la barre de l etape 1 n est pas rendue du tout : la page affiche
        // un avertissement a la place, et le test mesurerait celle de l etape 2.
        $this->planetAddUnit("small_cargo", 5);

        $cible = $this->getNearbyForeignPlanet();
        $coordonnees = $cible->getPlanetCoordinates();
        $proprietaire = $cible->getPlayer();
        $this->assertNotNull($proprietaire);

        $reponse = $this->get('/fleet?galaxy=' . $coordonnees->galaxy
            . '&system=' . $coordonnees->system
            . '&position=' . $coordonnees->position
            . '&type=1&mission=1');

        $reponse->assertStatus(200);

        $html = $reponse->getContent();
        $this->assertIsString($html);

        $barre = $this->barreDEtat($html);

        $this->assertStringContainsString(
            '[' . $coordonnees->galaxy . ':' . $coordonnees->system . ':' . $coordonnees->position . ']',
            $barre,
            'The status bar does not show the target coordinates that were passed in.'
        );

        $this->assertStringContainsString(
            $proprietaire->getUsername(false),
            $barre,
            "The status bar does not name the target's owner, so the player sees their own name instead."
        );

        // Le point le plus visible du defaut : sa propre planete annoncee comme cible.
        $this->assertStringNotContainsString(
            $this->planetService->getPlanetName(),
            $barre,
            'The status bar still announces the player own planet as the target.'
        );

        $this->assertStringNotContainsString(
            '[' . $this->planetService->getPlanetCoordinates()->asString() . ']',
            $barre,
            'The status bar still shows the player own coordinates as the target.'
        );
    }

    /**
     * Assert that without a target the page still describes the current planet.
     *
     * Sans cette borne, une correction qui viderait simplement la barre passerait le test
     * precedent tout en cassant l'ouverture normale de la page.
     */
    public function testWithoutATargetThePageStillDescribesTheCurrentPlanet(): void
    {
        $reponse = $this->get('/fleet');
        $reponse->assertStatus(200);

        $html = $reponse->getContent();
        $this->assertIsString($html);

        $barre = $this->barreDEtat($html);

        $this->assertStringContainsString(
            $this->planetService->getPlanetName(),
            $barre,
            'Opening the fleet page with no target no longer describes the current planet.'
        );
    }

    /**
     * Assert that the tactical retreat cost is not labelled as the trip consumption.
     *
     * Les deux portaient la meme cle. Le cout de repli est une valeur statique : affichee sous
     * « Consommation de deuterium », elle se lisait comme un carburant de voyage qui refuse de
     * bouger.
     */
    public function testTheTacticalRetreatCostHasItsOwnLabel(): void
    {
        $this->assertNotSame(
            trans('t_ingame.fleet.deuterium_consumption', [], 'fr'),
            trans('t_ingame.fleet.tactical_retreat_cost', [], 'fr'),
            'The tactical retreat cost shares the trip consumption label, so a static value reads as a frozen fuel figure.'
        );

        $vue = file_get_contents(resource_path('views/ingame/fleet/index.blade.php'));
        $this->assertIsString($vue);

        $this->assertStringContainsString(
            "t_ingame.fleet.tactical_retreat_cost",
            $vue,
            'The fleet view no longer uses the dedicated tactical retreat label.'
        );
    }

    /**
     * Extract the first-step status bar from the rendered page.
     */
    private function barreDEtat(string $html): string
    {
        $debut = strpos($html, 'id="statusBarFleet"');
        $this->assertNotFalse($debut, 'The fleet page no longer renders a status bar.');

        $fin = strpos($html, '</div>', $debut);
        $this->assertNotFalse($fin);

        return substr($html, $debut, $fin - $debut);
    }
}
