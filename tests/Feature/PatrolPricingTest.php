<?php

namespace Tests\Feature;

use InvalidArgumentException;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Planet\Coordinate;
use OGame\Patrol\Geometry\SpatialPoint;
use OGame\Patrol\PatrolDestination;
use OGame\Patrol\PatrolPricing;
use OGame\Services\ObjectService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;

/**
 * Le devis d un segment de patrouille : ce que le joueur lit avant de confirmer.
 *
 * ## Ce que ces temoins etablissent
 *
 * Que la distance interne suit la distance reelle — la revue 121 ayant rejete la formule constante ;
 * que l intersysteme garde exactement les regles du jeu ; que le devis annonce le retour de securite
 * et l autonomie ; et qu un ordre qui laisserait la patrouille incapable de rentrer est refuse avant
 * d etre propose, avec ses nombres.
 */
class PatrolPricingTest extends AccountTestCase
{
    private function pricing(): PatrolPricing
    {
        return resolve(PatrolPricing::class);
    }

    /**
     * Le joueur du banc, non nul.
     *
     * `PlanetService::getPlayer()` rend un proprietaire **ou nul** — un corps detruit n en a plus —
     * et la tarification en exige un. La fabrique le donne sans ambiguite.
     */
    private function player(): PlayerService
    {
        return resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);
    }

    private function fleet(array $composition): UnitCollection
    {
        $units = new UnitCollection();

        foreach ($composition as $nom => $nombre) {
            $units->addUnit(ObjectService::getUnitObjectByMachineName($nom), $nombre);
        }

        return $units;
    }

    /**
     * A l interieur d un systeme, un deplacement proche est plus court qu une traversee.
     *
     * **C est la correction que la revue 121 a imposee.** La premiere formule ajoutait mille a toute
     * distance interne : cent unites et trois mille donnaient des durees presque identiques, ce qui
     * privait le deplacement de tout sens tactique.
     */
    public function testAShortHopInsideASystemIsShorterThanACrossing(): void
    {
        $pricing = $this->pricing();
        $geometrie = $pricing->geometry();
        $joueur = $this->player();
        $flotte = $this->fleet(['cruiser' => 20]);
        $coords = $this->planetService->getPlanetCoordinates();
        $base = new Coordinate($coords->galaxy, $coords->system, $coords->position);
        $depart = new SpatialPoint(0, 500);

        $proche = $pricing->quote($joueur, $flotte, 100000.0, $coords->galaxy, $coords->system, $depart, PatrolDestination::spatialPoint($geometrie, $coords->galaxy, $coords->system, new SpatialPoint(100, 500)), 10, 1, $base);
        $moyen = $pricing->quote($joueur, $flotte, 100000.0, $coords->galaxy, $coords->system, $depart, PatrolDestination::spatialPoint($geometrie, $coords->galaxy, $coords->system, new SpatialPoint(1000, 500)), 10, 1, $base);
        $loin = $pricing->quote($joueur, $flotte, 100000.0, $coords->galaxy, $coords->system, $depart, PatrolDestination::spatialPoint($geometrie, $coords->galaxy, $coords->system, new SpatialPoint(1500, 500)), 10, 1, $base);

        // Les distances de jeu suivent le diviseur : cent, mille et mille cinq cents unites sur trois.
        $this->assertSame(34, $proche->distance);
        $this->assertSame(334, $moyen->distance);
        $this->assertSame(500, $loin->distance);

        $this->assertLessThan($moyen->durationSeconds, $proche->durationSeconds, 'A short hop lasts as long as a medium one.');
        $this->assertLessThan($loin->durationSeconds, $moyen->durationSeconds, 'A medium hop lasts as long as a crossing.');
        $this->assertLessThan($moyen->fuelCost, $proche->fuelCost, 'A short hop costs as much as a medium one.');
        $this->assertLessThan($loin->fuelCost, $moyen->fuelCost, 'A medium hop costs as much as a crossing.');
    }

    /**
     * Entre systemes, ce sont les regles du jeu, et l endroit d ou l on part ne change rien.
     *
     * ## Les deux moities de ce temoin
     *
     * La premiere epingle la formule nue, les deux deductions d univers desarmees : quatre-vingt-quinze
     * par systeme, plus deux mille sept cents. La seconde les arme et exige que la distance **change** —
     * c est elle qui etablit que la tarification delegue vraiment au calcul du jeu au lieu d en avoir
     * recopie la formule sans ses deductions. Une copie fautive passerait la premiere et tomberait ici.
     *
     * L essai pose les deux interrupteurs qu il suppose, et les remet comme il les a trouves.
     */
    public function testBetweenSystemsTheGameRulesApplyAndTheStartingPointDoesNotMatter(): void
    {
        $pricing = $this->pricing();
        $geometrie = $pricing->geometry();
        $joueur = $this->player();
        $flotte = $this->fleet(['cruiser' => 20]);
        $coords = $this->planetService->getPlanetCoordinates();
        $base = new Coordinate($coords->galaxy, $coords->system, $coords->position);
        $ailleurs = PatrolDestination::spatialPoint($geometrie, $coords->galaxy, $coords->system + 20, new SpatialPoint(0, 500));

        $settings = resolve(SettingsService::class);
        $videsAvant = $settings->get('ignore_empty_systems_on', 0);
        $inactifsAvant = $settings->get('ignore_inactive_systems_on', 0);

        try {
            $settings->set('ignore_empty_systems_on', 0);
            $settings->set('ignore_inactive_systems_on', 0);

            $unBout = $pricing->quote($joueur, $flotte, 100000.0, $coords->galaxy, $coords->system, new SpatialPoint(0, 500), $ailleurs, 10, 1, $base);
            $lAutre = $pricing->quote($joueur, $flotte, 100000.0, $coords->galaxy, $coords->system, new SpatialPoint(-1400, 200), $ailleurs, 10, 1, $base);

            $this->assertSame($unBout->distance, $lAutre->distance, 'An inter-system distance changed with where the fleet stood in its own system.');
            $this->assertSame(20 * 95 + 2700, $unBout->distance, 'The bare inter-system formula of the game was not applied.');

            // Deductions armees : l univers d essai est presque vide, donc l ecart tombe au plancher.
            $settings->set('ignore_empty_systems_on', 1);

            $deduit = $pricing->quote($joueur, $flotte, 100000.0, $coords->galaxy, $coords->system, new SpatialPoint(0, 500), $ailleurs, 10, 1, $base);

            $this->assertLessThan(
                $unBout->distance,
                $deduit->distance,
                'Arming the empty-system deduction changed nothing: the pricing copied the formula instead of delegating to it.'
            );
            $this->assertSame(1 * 95 + 2700, $deduit->distance, 'The deduction did not fall to the floor of one system.');
        } finally {
            $settings->set('ignore_empty_systems_on', $videsAvant);
            $settings->set('ignore_inactive_systems_on', $inactifsAvant);
        }
    }

    /**
     * Le devis annonce le retour de securite et l autonomie qui en decoule.
     */
    public function testTheQuoteAnnouncesTheSafetyReturnAndTheAutonomy(): void
    {
        $pricing = $this->pricing();
        $geometrie = $pricing->geometry();
        $joueur = $this->player();
        $flotte = $this->fleet(['cruiser' => 20]);
        $coords = $this->planetService->getPlanetCoordinates();
        $base = new Coordinate($coords->galaxy, $coords->system, $coords->position);
        $cible = PatrolDestination::spatialPoint($geometrie, $coords->galaxy, $coords->system + 20, new SpatialPoint(0, 500));

        $devis = $pricing->quote($joueur, $flotte, 20000.0, $coords->galaxy, $coords->system, new SpatialPoint(0, 500), $cible, 10, 7, $base);

        $this->assertTrue($devis->isPossible(), 'A well-funded patrol order was refused: ' . (string)$devis->refusal);
        $this->assertGreaterThan(0, $devis->safetyReturnCost, 'The quote promised a free return.');
        $this->assertGreaterThan(0, $devis->safetyReturnSeconds);
        $this->assertNotNull($devis->autonomySeconds);
        $this->assertSame(20000.0 - $devis->fuelCost, $devis->reserveOnArrival);
        // La version d ordre voyage avec le devis : c est elle que la confirmation devra rapporter.
        $this->assertSame(7, $devis->orderVersion);

        // Le retour de securite est plus lent, donc plus long, mais moins gourmand qu un cent pour cent.
        $centPourCent = $pricing->quote($joueur, $flotte, 20000.0, $coords->galaxy, $coords->system, new SpatialPoint(0, 500), $cible, 10, 7, $base);
        $this->assertGreaterThan($centPourCent->durationSeconds, $devis->safetyReturnSeconds, 'The safety return is not slower than a full-speed leg.');
        $this->assertLessThan($centPourCent->fuelCost, $devis->safetyReturnCost, 'The safety return costs as much as a full-speed leg.');
    }

    /**
     * Un ordre qui laisserait la patrouille sans de quoi rentrer est refuse, avec ses nombres.
     */
    public function testAnOrderThatWouldStrandThePatrolIsRefusedButStillCounted(): void
    {
        $pricing = $this->pricing();
        $geometrie = $pricing->geometry();
        $joueur = $this->player();
        $flotte = $this->fleet(['cruiser' => 20]);
        $coords = $this->planetService->getPlanetCoordinates();
        $base = new Coordinate($coords->galaxy, $coords->system, $coords->position);
        $loin = PatrolDestination::spatialPoint($geometrie, $coords->galaxy, $coords->system + 100, new SpatialPoint(0, 500));

        $plein = $pricing->quote($joueur, $flotte, 100000.0, $coords->galaxy, $coords->system, new SpatialPoint(0, 500), $loin, 10, 1, $base);
        $this->assertTrue($plein->isPossible());

        // Juste de quoi aller, pas de quoi revenir : refuse, mais les chiffres restent lisibles.
        $justeAller = $pricing->quote($joueur, $flotte, (float)$plein->fuelCost, $coords->galaxy, $coords->system, new SpatialPoint(0, 500), $loin, 10, 1, $base);
        $this->assertFalse($justeAller->isPossible());
        $this->assertSame('no_return_reserve', $justeAller->refusal);
        $this->assertSame($plein->fuelCost, $justeAller->fuelCost, 'The refusal threw away the numbers the player needs.');
        $this->assertSame($plein->safetyReturnCost, $justeAller->safetyReturnCost);

        // Pas meme de quoi aller : un autre refus, et il se distingue du precedent.
        $rien = $pricing->quote($joueur, $flotte, 1.0, $coords->galaxy, $coords->system, new SpatialPoint(0, 500), $loin, 10, 1, $base);
        $this->assertSame('not_enough_fuel', $rien->refusal);

        // Une flotte vide est refusee avant toute formule : aucune division par zero derriere.
        $vide = $pricing->quote($joueur, new UnitCollection(), 100000.0, $coords->galaxy, $coords->system, new SpatialPoint(0, 500), $loin, 10, 1, $base);
        $this->assertSame('no_units', $vide->refusal);
        $this->assertSame(0, $vide->durationSeconds);
    }

    /**
     * Une destination se valide a la construction, jamais plus tard.
     */
    public function testADestinationValidatesItselfWhenItIsBuilt(): void
    {
        $geometrie = $this->pricing()->geometry();

        // Hors grille.
        try {
            PatrolDestination::spatialPoint($geometrie, 1, 1, new SpatialPoint(5, 5));
            $this->fail('A point off the grid was accepted as a destination.');
        } catch (InvalidArgumentException $refus) {
            $this->assertStringContainsString('point_off_grid', $refus->getMessage());
        }

        // Dans l etoile.
        try {
            PatrolDestination::spatialPoint($geometrie, 1, 1, new SpatialPoint(0, 10));
            $this->fail('A point inside the star was accepted as a destination.');
        } catch (InvalidArgumentException $refus) {
            $this->assertStringContainsString('point_in_star', $refus->getMessage());
        }

        // Hors du systeme.
        try {
            PatrolDestination::spatialPoint($geometrie, 1, 1, new SpatialPoint(0, 2000));
            $this->fail('A point outside the system was accepted as a destination.');
        } catch (InvalidArgumentException $refus) {
            $this->assertStringContainsString('point_outside_system', $refus->getMessage());
        }

        // Un corps se designe par son identite, et son point de stationnement est toujours valide.
        $pres = PatrolDestination::nearBody($geometrie, 1, 1, 9, PlanetType::Planet, 42);
        $this->assertTrue($pres->isBody());
        $this->assertSame(42, $pres->bodyId);
        $this->assertSame(9, $pres->orbit);
        $this->assertNull($geometrie->refusalOf($pres->point));

        // Un champ de debris n est pas un corps.
        try {
            PatrolDestination::nearBody($geometrie, 1, 1, 9, PlanetType::DebrisField, null);
            $this->fail('A debris field was accepted as a body.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * Deux destinations au meme endroit ne sont pas la meme chose si elles ne visent pas la meme chose.
     *
     * Une planete et sa lune partagent leurs coordonnees ; un point libre pose au voisinage d une
     * planete n est pas cette planete. L identite decide, jamais le seul point.
     */
    public function testTwoDestinationsAtTheSamePlaceAreNotTheSameTarget(): void
    {
        $geometrie = $this->pricing()->geometry();

        $planete = PatrolDestination::nearBody($geometrie, 1, 1, 9, PlanetType::Planet, 42);
        $lune = PatrolDestination::nearBody($geometrie, 1, 1, 9, PlanetType::Moon, 43);
        $libre = PatrolDestination::spatialPoint($geometrie, 1, 1, $planete->point);

        $this->assertTrue($planete->equals(PatrolDestination::nearBody($geometrie, 1, 1, 9, PlanetType::Planet, 42)));
        $this->assertFalse($planete->equals($lune), 'A planet and its moon were taken for the same target.');
        $this->assertFalse($planete->equals($libre), 'A free point next to a planet was taken for the planet.');
    }
}
