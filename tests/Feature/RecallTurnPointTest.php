<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use OGame\GameMissions\Abstracts\GameMission;
use OGame\Models\FleetMission;
use Tests\AccountTestCase;

/**
 * Ou une flotte rappelee a fait demi-tour.
 *
 * ## Le defaut, signale par Keven le 12 septembre 2026
 *
 * « Quand je recall une mission — exemple colonisation — dans la galaxie, tu ne le vois pas
 * retourner de bord : c'est comme s'il sortait de l'hyperespace alors qu'il n'y est jamais entre,
 * de ou etait sa derniere position sur la map. »
 *
 * Cause : `GameMission::cancel()` tronque l'arrivee de l'aller a maintenant, puis `startReturn()`
 * cree le retour **depuis la cible** — c'est le modele du jeu. La carte dessinait donc exactement ce
 * qu'on lui donnait : un saut au bout du trajet, puis le retour.
 *
 * ## Ce que ce banc etablit
 *
 * Que la part du trajet parcourue est ecrite **sur le retour**, au rappel, avec l'arrivee
 * **physique** pour denominateur ; qu'elle est bornee quand les instants sont aberrants ; et
 * qu'aucun autre chemin de creation de retour ne la porte.
 */
class RecallTurnPointTest extends AccountTestCase
{
    /**
     * Un aller en vol du joueur, dont on choisit le depart et l'arrivee.
     */
    private function unAllerEnVol(int $depart, int $arrivee, int $missionType = 3): FleetMission
    {
        $corps = $this->planetService;
        $cible = $this->getNearbyForeignPlanet();

        $id = DB::table('fleet_missions')->insertGetId([
            'user_id' => $this->currentUserId,
            'planet_id_from' => $corps->getPlanetId(),
            'galaxy_from' => $corps->getPlanetCoordinates()->galaxy,
            'system_from' => $corps->getPlanetCoordinates()->system,
            'position_from' => $corps->getPlanetCoordinates()->position,
            'type_from' => 1,
            'planet_id_to' => $cible->getPlanetId(),
            'galaxy_to' => $cible->getPlanetCoordinates()->galaxy,
            'system_to' => $cible->getPlanetCoordinates()->system,
            'position_to' => $cible->getPlanetCoordinates()->position,
            'type_to' => 1,
            'mission_type' => $missionType,
            'time_departure' => $depart,
            'time_arrival' => $arrivee,
            'processed' => 0,
            'canceled' => 0,
            'small_cargo' => 5,
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
        ]);

        return FleetMission::query()->findOrFail($id);
    }

    private function leRetourDe(FleetMission $aller): FleetMission
    {
        $retour = FleetMission::query()->where('parent_id', (int)$aller->id)->first();

        $this->assertNotNull($retour, 'Le rappel n a cree aucun retour : le banc ne juge rien.');

        return $retour;
    }

    private function rappeler(FleetMission $aller): void
    {
        resolve(\OGame\Factories\GameMissionFactory::class)
            ->getMissionById((int)$aller->mission_type, [
                'fleetMissionService' => resolve(\OGame\Services\FleetMissionService::class),
                'messageService' => resolve(\OGame\Services\MessageService::class),
            ])
            ->cancel($aller);
    }

    /**
     * **Un rappel a mi-chemin ecrit la moitie du trajet.**
     */
    public function testARecallHalfwayWritesHalfTheTravel(): void
    {
        $maintenant = (int)now()->timestamp;
        $aller = $this->unAllerEnVol($maintenant - 600, $maintenant + 600);

        $this->rappeler($aller);

        $retour = $this->leRetourDe($aller);

        $this->assertNotNull($retour->recall_progress, 'Le retour d un rappel ne porte pas le point de demi-tour : la carte fera encore sauter la flotte.');
        $this->assertEqualsWithDelta(GameMission::TRAVEL_FULLY_DONE / 2, (int)$retour->recall_progress, 1, 'La part parcourue n est pas la moitie alors que la flotte etait a mi-chemin.');
    }

    /**
     * Le quart, les trois quarts : la valeur suit le temps, elle n est pas un drapeau.
     */
    public function testThePortionFollowsTheElapsedTime(): void
    {
        foreach ([['ecoule' => 300, 'restant' => 900, 'attendu' => 2500], ['ecoule' => 900, 'restant' => 300, 'attendu' => 7500]] as $cas) {
            $maintenant = (int)now()->timestamp;
            $aller = $this->unAllerEnVol($maintenant - $cas['ecoule'], $maintenant + $cas['restant']);

            $this->rappeler($aller);

            $this->assertEqualsWithDelta(
                $cas['attendu'],
                (int)$this->leRetourDe($aller)->recall_progress,
                2,
                'Une flotte a ' . $cas['attendu'] / 100 . ' % du trajet n ecrit pas cette part.'
            );
        }
    }

    /**
     * **L arrivee physique fait foi, pas `time_arrival`.**
     *
     * Pour une Defense ACS, `time_arrival` inclut les heures de stationnement. Prendre cette valeur
     * comme denominateur ferait repartir un renfort rappele en vol d un point qu il n a jamais
     * atteint — le defaut qu on corrige, a l envers.
     */
    public function testForAnAcsDefendThePhysicalArrivalIsTheDenominator(): void
    {
        $maintenant = (int)now()->timestamp;

        // Part il y a 600 s, arrive physiquement dans 600 s, puis stationne deux heures.
        $aller = $this->unAllerEnVol($maintenant - 600, $maintenant + 600 + 7200, 5);
        DB::table('fleet_missions')->where('id', (int)$aller->id)->update(['time_holding' => 7200]);
        $aller->refresh();

        $this->rappeler($aller);

        $part = (int)$this->leRetourDe($aller)->recall_progress;

        $this->assertEqualsWithDelta(
            GameMission::TRAVEL_FULLY_DONE / 2,
            $part,
            2,
            'Le denominateur inclut le temps de stationnement : la flotte repartirait d un point qu elle n a jamais atteint (part lue : ' . $part . ').'
        );
    }

    /**
     * Une flotte qui vient de partir : rien de parcouru, et la valeur le dit.
     */
    public function testAFleetJustLaunchedHasTravelledNothing(): void
    {
        $maintenant = (int)now()->timestamp;
        $aller = $this->unAllerEnVol($maintenant, $maintenant + 1200);

        $this->rappeler($aller);

        $this->assertSame(0, (int)$this->leRetourDe($aller)->recall_progress, 'Une flotte a peine partie porte une part non nulle.');
    }

    /**
     * **Aucune division sans garde.** Une arrivee forcee sous le depart, ou un instant deja au-dela
     * de l arrivee prevue, donnent le trajet plein — donc le depart a la cible, le rendu d avant.
     */
    public function testAberrantInstantsGiveTheFullTravelAndNeverDivideByZero(): void
    {
        $maintenant = (int)now()->timestamp;

        // Arrivee prevue AVANT le depart : denominateur negatif.
        $aberrant = $this->unAllerEnVol($maintenant + 100, $maintenant + 50);
        $this->rappeler($aberrant);
        $this->assertSame(GameMission::TRAVEL_FULLY_DONE, (int)$this->leRetourDe($aberrant)->recall_progress, 'Un instant aberrant ne donne pas le trajet plein.');

        // Arrivee prevue a l instant du depart : denominateur nul.
        $instantane = $this->unAllerEnVol($maintenant, $maintenant);
        $this->rappeler($instantane);
        $this->assertSame(GameMission::TRAVEL_FULLY_DONE, (int)$this->leRetourDe($instantane)->recall_progress, 'Une arrivee instantanee ne donne pas le trajet plein.');
    }

    /**
     * **Une flotte deja arrivee a fait tout le trajet.**
     *
     * C est le cas des trois annulations de `ColonisationMission::processArrival()` — planete deja
     * prise, aucun vaisseau de colonisation, astrophysique insuffisante : la flotte est physiquement
     * la, et son retour part bien de la cible. C est aussi celui d un travailleur en retard.
     */
    public function testAFleetThatHasAlreadyArrivedHasTravelledEverything(): void
    {
        $maintenant = (int)now()->timestamp;

        // Partie il y a vingt minutes, arrivee il y a cinq : le travailleur la traite en retard.
        $aller = $this->unAllerEnVol($maintenant - 1200, $maintenant - 300);

        $this->rappeler($aller);

        $part = (int)$this->leRetourDe($aller)->recall_progress;

        $this->assertSame(GameMission::TRAVEL_FULLY_DONE, $part, 'Une flotte deja arrivee ne porte pas le trajet plein (part lue : ' . $part . ').');
        $this->assertLessThanOrEqual(GameMission::TRAVEL_FULLY_DONE, $part, 'La part depasse le trajet plein : la carte placerait le demi-tour au-dela de la cible.');
    }

    /**
     * **Un retour qui n est pas un rappel ne porte rien.**
     *
     * `startReturn()` a onze autres appelants — combat, refus, annulation, expedition, espionnage,
     * destruction de lune. Le parametre etant a defaut nul, ils restent nuls **par construction** ;
     * cet essai le verifie sur le chemin le plus courant, l arrivee ordinaire d un transport.
     */
    public function testAReturnThatIsNotARecallCarriesNothing(): void
    {
        $maintenant = (int)now()->timestamp;

        // Un transport qui arrive normalement : le travailleur cree son retour.
        $aller = $this->unAllerEnVol($maintenant - 1200, $maintenant - 1, 3);

        resolve(\OGame\Services\PlayerService::class)->updateFleetMissions();

        $retour = FleetMission::query()->where('parent_id', (int)$aller->id)->first();

        if ($retour === null) {
            $this->markTestSkipped('Le transport n a pas ete traite par le travailleur dans ce montage.');
        }

        $this->assertNull($retour->recall_progress, 'Un retour cree hors rappel porte une part de trajet : la carte le ferait rebrousser sans raison.');
    }

    /**
     * La carte recoit la fraction, et seulement quand elle existe.
     */
    public function testTheMapReceivesTheFractionOnlyWhenItExists(): void
    {
        $maintenant = (int)now()->timestamp;
        $corps = $this->planetService->getPlanetCoordinates();
        $aller = $this->unAllerEnVol($maintenant - 600, $maintenant + 600);

        $this->rappeler($aller);

        $charge = $this->getJson('/ajax/galaxy/fleets?galaxy=' . $corps->galaxy . '&system=' . $corps->system)
            ->assertStatus(200)
            ->json('movements');

        $this->assertIsArray($charge);

        $retours = array_values(array_filter($charge, static fn (array $m): bool => !empty($m['is_return'])));

        $this->assertNotSame([], $retours, 'La carte ne recoit aucun retour : la premisse tombe.');

        $part = $retours[0]['recall_progress'];

        $this->assertNotNull($part, 'La carte ne recoit pas le point de demi-tour.');
        $this->assertEqualsWithDelta(0.5, (float)$part, 0.01, 'La fraction publiee n est pas celle du demi-tour.');
        $this->assertGreaterThan(0.0, (float)$part);
        $this->assertLessThan(1.0, (float)$part);
    }
}
