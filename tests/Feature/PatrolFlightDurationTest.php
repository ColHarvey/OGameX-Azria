<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\FleetMission;
use OGame\Models\Patrol;
use OGame\Models\Resources;
use OGame\Patrol\Geometry\SpatialPoint;
use OGame\Patrol\PatrolDestination;
use OGame\Patrol\PatrolOrders;
use OGame\Patrol\PatrolPricing;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use ReflectionMethod;
use Tests\AccountTestCase;

/**
 * **Dans un systeme, une patrouille vole a vitesse constante : la duree suit la distance.**
 *
 * ## Le defaut mesure, et pourquoi il n etait pas ou je le cherchais
 *
 * Keven, 12 septembre 2026 : patrouille lancee, rappelee cinq secondes plus tard, retour annonce a
 * **six minutes** — avec du deuterium en quantite, donc sans rapport avec la reserve.
 *
 * Ma premiere hypothese — le rappel repart du point d arrivee au lieu de la position reelle — etait
 * **fausse** : `departurePointFor()` interpole correctement, et le temoin ecrit pour l accuser est
 * passe du premier coup. La mesure a donne la vraie cause :
 *
 * ```
 * aller  : 2241 s pour 509 unites  ->  0,227 unite/s
 * rappel :  380 s pour  14 unites  ->  0,037 unite/s
 * ```
 *
 * La meme flotte, a la meme vitesse, volait **six fois plus lentement sur le trajet court**. La
 * formule du jeu vaut `35000 / vitesse x racine(distance x 10 / vitesseVaisseau) + 10` : une racine
 * carree, donc un cout fixe enorme sur les courtes distances. En OGame ordinaire cela ne se voit
 * jamais — deux planetes d un meme systeme sont a 1000 unites au minimum. La geometrie des
 * patrouilles, elle, produit des distances petites par construction.
 *
 * ## La regle, decidee par Keven
 *
 * A l interieur d un systeme, la duree est **proportionnelle a la distance**, calibree sur la
 * traversee complete : traverser tout le systeme coute ce que la formule du jeu reclamerait, et
 * chaque trajet plus court coute sa part exacte. Entre systemes, la formule du jeu reste.
 */
class PatrolFlightDurationTest extends AccountTestCase
{
    protected function tearDown(): void
    {
        /*
         * **Une epreuve remet ce qu elle a leve, et emporte ce qu elle a cree.**
         *
         * La base d un processus est partagee entre les classes : une patrouille laissee en vol
         * ici reapparait dans le banc voisin, ou un temoin d isolation exige que l etranger ne
         * voie **rien**. Il a rougi exactement ainsi, et ce n etait pas sa faute.
         */
        $patrouilles = Patrol::query()->where('user_id', $this->currentUserId)->pluck('id')->all();

        if ($patrouilles !== []) {
            FleetMission::query()->whereIn('patrol_id', $patrouilles)->delete();
            Patrol::query()->whereIn('id', $patrouilles)->delete();
        }

        resolve(SettingsService::class)->set('patrols_enabled', 0);
        Date::setTestNow();

        parent::tearDown();
    }

    private function flotte(): UnitCollection
    {
        $units = new UnitCollection();
        $units->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 20);

        return $units;
    }

    /**
     * La duree telle que le tarificateur la calcule, pour une distance et un genre de trajet.
     */
    private function duree(int $distance, bool $dansUnSysteme): int
    {
        $methode = new ReflectionMethod(PatrolPricing::class, 'durationOver');

        return (int)$methode->invoke(
            resolve(PatrolPricing::class),
            resolve(PlayerServiceFactory::class)->make($this->currentUserId, true),
            $this->flotte(),
            $distance,
            10.0,
            $dansUnSysteme
        );
    }

    /**
     * **Le rapport distance/duree ne depend pas de la distance.** C est cela, « proportionnelle » —
     * et c est ce qu un simple « le rappel est plus court que l aller » ne prouverait pas.
     *
     * L ecart tolere est celui des arrondis a la seconde, qui pese sur les toutes petites distances
     * et sur elles seules.
     */
    public function testDansUnSystemeLaDureeSuitLaDistance(): void
    {
        $rapports = [];

        foreach ([41, 100, 509, 1000, 1800, 3600] as $distance) {
            $duree = $this->duree($distance, true);

            $this->assertGreaterThan(0, $duree, 'Une duree nulle ferait arriver la flotte avant d etre partie.');

            $rapports[$distance] = $distance / $duree;
        }

        $min = min($rapports);
        $max = max($rapports);

        $this->assertLessThan(
            0.02,
            ($max - $min) / $min,
            'La vitesse depend de la distance parcourue : ' . json_encode(array_map(
                static fn (float $r): float => round($r, 4),
                $rapports
            ))
        );
    }

    /**
     * **La traversee complete coute exactement ce que la formule du jeu reclame.**
     *
     * C est l ancre de tout le calcul : sans elle, « proportionnelle » ne dirait pas a quelle
     * vitesse. Et c est ce qui garantit qu une patrouille ne devienne pas plus rapide qu une flotte
     * ordinaire sur la plus longue distance qu elle puisse parcourir.
     */
    public function testLaTraverseeDuSystemeCouteCeQueLeJeuReclame(): void
    {
        $traversee = 2 * resolve(PatrolPricing::class)->geometry()->systemRadiusUnits();

        $this->assertEqualsWithDelta(
            $this->duree($traversee, false),
            $this->duree($traversee, true),
            2,
            'La traversee du systeme ne coute plus ce que la formule du jeu reclame : l ancrage a bouge.'
        );
    }

    /**
     * **Entre systemes, rien ne change.** Une patrouille ne traverse pas la galaxie plus vite qu une
     * flotte ordinaire : la formule du jeu y garde toute sa place.
     */
    public function testEntreSystemesLaFormuleDuJeuReste(): void
    {
        $this->assertNotSame(
            $this->duree(1000, true),
            $this->duree(1000, false),
            'La duree intersysteme a pris la forme lineaire : une patrouille traverserait la galaxie trop vite.'
        );
    }

    /**
     * **Et le parcours reel le montre** : une flotte rappelee cinq secondes apres son depart rentre
     * a la vitesse a laquelle elle est partie, pas six fois plus lentement.
     */
    public function testUnRappelImmediatRentreALaMemeVitesseQueLAller(): void
    {
        resolve(SettingsService::class)->set('patrols_enabled', 1);
        $this->playerSetResearchLevel('computer_technology', 10);
        $this->planetAddResources(new Resources(0, 0, 200000, 0));
        $this->planetAddUnit('light_fighter', 40);

        $ordres = resolve(PatrolOrders::class);
        $coords = $this->planetService->getPlanetCoordinates();
        $depart = (int)Date::now()->timestamp;

        $vers = PatrolDestination::spatialPoint(
            resolve(PatrolPricing::class)->geometry(),
            $coords->galaxy,
            $coords->system,
            new SpatialPoint(1400, 0)
        );

        $patrouille = $ordres->launch($this->planetService, $this->flotte(), new Resources(0, 0, 0, 0), 900, $vers, 10, $depart);

        $aller = FleetMission::query()->findOrFail($patrouille->current_mission_id);
        $dureeAller = (int)$aller->time_arrival - (int)$aller->time_departure;

        $this->assertGreaterThan(120, $dureeAller, 'L aller est trop court : la mesure ne distinguerait rien.');

        $maintenant = $depart + 5;
        Date::setTestNow(Date::createFromTimestamp($maintenant));

        $devis = $ordres->quoteForRecall($patrouille->refresh(), $maintenant);

        $this->assertGreaterThan(0, $devis->distance, 'La flotte n a pas bouge : le rappel ne mesurerait rien.');

        // **La vitesse du retour comparee a celle de l aller** — c est l invariant, pas la duree
        // brute : un retour « plus court » resterait faux s il volait six fois moins vite.
        $attendue = $devis->distance / $this->duree($devis->distance, true);
        $reelle = $devis->distance / $devis->durationSeconds;

        $this->assertEqualsWithDelta(
            $attendue,
            $reelle,
            $attendue * 0.05,
            sprintf(
                'Le rappel vole a %.3f unite/s la ou l aller vole a %.3f : %d s pour %d unites.',
                $reelle,
                $attendue,
                $devis->durationSeconds,
                $devis->distance
            )
        );
    }
}
