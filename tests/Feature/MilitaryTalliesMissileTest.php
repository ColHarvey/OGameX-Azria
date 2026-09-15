<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Military\MilitaryMissileTally;
use OGame\Military\MilitaryTallyRecorder;
use OGame\Military\MilitaryTallyReplay;
use OGame\Military\MilitaryValue;
use OGame\Military\MissileTallyEvaluation;
use OGame\Military\MissileTallyFacts;
use OGame\Models\FleetMission;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use Tests\FleetDispatchTestCase;

/**
 * **Les cumuls militaires lisent une frappe de missiles**, dans la transaction de la frappe.
 *
 * L interception est un « detruit » du defenseur (la valeur des missiles abattus) ; les defenses detruites sont un
 * « detruit » de l attaquant et un « perdu » du defenseur. Les missiles tires et les antimissiles consommes ne
 * comptent jamais. L instant du fait est l arrivee des missiles. Les deux evenements vivent en groupe : repris
 * ensemble ou pas du tout.
 */
final class MilitaryTalliesMissileTest extends FleetDispatchTestCase
{
    use ReadsMilitaryTallies;

    protected int $missionType = 10;

    protected string $missionName = 'Attaque de missiles';

    protected function basicSetup(): void
    {
        $this->playerSetResearchLevel('weapon_technology', 5);
    }

    protected function messageCheckMissionArrival(): void
    {
    }

    protected function messageCheckMissionReturn(): void
    {
    }

    protected function tearDown(): void
    {
        $this->desactiverLesCumuls();

        parent::tearDown();
    }

    public function testAStrikeCreditsTheInterceptionToTheDefenderAndTheDestroyedDefencesToBoth(): void
    {
        $this->basicSetup();
        $this->activerLesCumulsDepuis((int)Date::now()->timestamp - 100);
        $cible = $this->uneCibleDefendue(lanceurs: 100, antimissiles: 2);
        $proprietaire = $cible->getPlayer();
        $this->assertNotNull($proprietaire);
        $mission = $this->desMissilesArrivesSur($cible, missiles: 5);

        $this->get('/overview')->assertStatus(200);

        $mission->refresh();
        $this->assertSame(1, (int)$mission->processed, 'Premisse : la frappe a ete traitee.');
        $cible->reloadPlanet();
        $detruits = 100 - $cible->getObjectAmount('rocket_launcher');
        $this->assertGreaterThan(0, $detruits, 'Premisse : la frappe a detruit des lanceurs, sinon le temoin ne mesure rien.');
        $this->assertSame(0, $cible->getObjectAmount('anti_ballistic_missile'), 'Premisse : les deux antimissiles ont ete consommes par l interception.');

        $prefixe = 'missile:mission:' . $mission->id . ':';
        $evenements = $this->evenementsSous($prefixe);
        $this->assertCount(2, $evenements, 'Une frappe doit produire un evenement par camp : ' . implode(', ', array_keys($evenements)));

        $attaquant = $evenements[$prefixe . CombatParticipantKey::forFleet((int)$mission->id)] ?? null;
        $this->assertNotNull($attaquant, 'L attaquant n a pas son evenement sous la clef de sa mission.');
        $this->assertSame($this->currentUserId, (int)$attaquant->player_id);
        $this->assertSame(MilitaryTallyRecorder::APPLIED, $attaquant->status, 'La frappe est restee en attente : ' . (string)$attaquant->reason);
        $this->assertSame($this->valeurMilitaireDe(['rocket_launcher' => $detruits]), (int)$attaquant->destroyed_value, 'Les « detruits » de l attaquant ne sont pas la valeur des lanceurs detruits.');
        $this->assertSame(0, (int)$attaquant->lost_value, 'Les missiles tires ont ete comptes en « perdus » : une consommation normale ne compte jamais.');

        $defenseur = $evenements[$prefixe . CombatParticipantKey::forBody($cible)] ?? null;
        $this->assertNotNull($defenseur, 'Le defenseur n a pas son evenement sous la clef de son corps.');
        $this->assertSame($proprietaire->getId(), (int)$defenseur->player_id);
        $this->assertSame($this->valeurMilitaireDe(['rocket_launcher' => $detruits]), (int)$defenseur->lost_value, 'Les « perdus » du defenseur ne sont pas la valeur des lanceurs detruits : les antimissiles consommes ne comptent jamais.');
        $this->assertSame($this->valeurMilitaireDe(['interplanetary_missile' => 2]), (int)$defenseur->destroyed_value, 'Les « detruits » du defenseur ne sont pas la valeur des deux missiles interceptes.');
    }

    /**
     * **L instant du fait est l arrivee des missiles, jamais l heure du traitement.**
     */
    public function testAStrikeIsCollectedByItsArrivalNeverByItsProcessingTime(): void
    {
        $this->basicSetup();
        $cible = $this->uneCibleDefendue(lanceurs: 100, antimissiles: 0);
        $mission = $this->desMissilesArrivesSur($cible, missiles: 5);
        $this->activerLesCumulsDepuis((int)$mission->time_arrival + 1);

        $this->get('/overview')->assertStatus(200);

        $cible->reloadPlanet();
        $this->assertLessThan(100, $cible->getObjectAmount('rocket_launcher'), 'Premisse : la frappe a eu lieu.');
        $this->assertSame([], $this->evenementsSous('missile:mission:' . $mission->id . ':'), 'Une frappe arrivee avant l ouverture de la collecte a ete comptee sur l heure de son traitement.');
    }

    /**
     * **Les deux evenements d une frappe sont repris ensemble ou pas du tout.** Le groupe se reconnait a son genre :
     * un membre seul reste en attente, deux membres aux memes faits sont clos d un bloc, a la valeur attendue.
     */
    public function testAPendingStrikeIsReplayedWholeOrNotAtAll(): void
    {
        $echeance = (int)Date::now()->timestamp;
        $this->activerLesCumulsDepuis($echeance - 10);
        $tireur = $this->currentUserId;
        $vise = $this->getSecondPlayerId();
        $lanceurs = new UnitCollection();
        $lanceurs->addUnit(ObjectService::getUnitObjectByMachineName('rocket_launcher'), 12);
        $faits = MissileTallyFacts::of(
            920_001,
            $echeance,
            ['key' => CombatParticipantKey::forFleet(920_001), 'owner' => $tireur, 'npc' => false],
            ['key' => CombatParticipantKey::forPlanet(920_002), 'owner' => $vise, 'npc' => false],
            3,
            $lanceurs,
        );
        $this->effacerLesCumulsSous($faits->eventKeyPrefix());
        $registre = resolve(MilitaryTallyRecorder::class);
        $charge = static fn (string $participant) => ['kind' => MilitaryMissileTally::KIND, 'participant' => $participant, 'detail' => 'fabrique', 'facts' => $faits->toStorage()];

        // Un seul membre : le groupe est incomplet, rien ne s applique.
        $this->assertTrue($registre->defer($faits->eventKeyFor($faits->attacker['key']), $tireur, $echeance, MissileTallyEvaluation::UNKNOWN_UNIT_FAMILY, $charge($faits->attacker['key'])), 'Premisse : le premier membre s inscrit en attente (collecte depuis ' . var_export($registre->collectingSince(), true) . ').');
        $this->assertSame(['replayed' => 0, 'pending' => 1, 'skipped' => 0], (new MilitaryTallyReplay())->replay(), 'Un groupe incomplet a ete repris.');

        // Le second membre arrive : le groupe est entier, tout s applique.
        $this->assertTrue($registre->defer($faits->eventKeyFor($faits->defender['key']), $vise, $echeance, MissileTallyEvaluation::UNKNOWN_UNIT_FAMILY, $charge($faits->defender['key'])), 'Premisse : le second membre s inscrit en attente.');
        $this->assertSame(['replayed' => 1, 'pending' => 0, 'skipped' => 1], (new MilitaryTallyReplay())->replay(), 'Le groupe entier n a pas ete repris d un bloc.');

        $evenements = $this->evenementsSous($faits->eventKeyPrefix());
        $this->assertCount(2, $evenements);
        $this->assertSame(MilitaryValue::ofObject(ObjectService::getUnitObjectByMachineName('rocket_launcher'), 12), (int)$evenements[$faits->eventKeyFor($faits->attacker['key'])]->destroyed_value);
        $this->assertSame(MilitaryValue::ofObject(ObjectService::getUnitObjectByMachineName('interplanetary_missile'), 3), (int)$evenements[$faits->eventKeyFor($faits->defender['key'])]->destroyed_value);
        $this->assertSame(MilitaryValue::ofObject(ObjectService::getUnitObjectByMachineName('rocket_launcher'), 12), (int)$evenements[$faits->eventKeyFor($faits->defender['key'])]->lost_value);
        $this->assertSame(MilitaryTallyRecorder::APPLIED, $evenements[$faits->eventKeyFor($faits->attacker['key'])]->status);
    }

    private function uneCibleDefendue(int $lanceurs, int $antimissiles): PlanetService
    {
        $cible = $this->getNearbyForeignCleanPlanet();
        $cible->addUnit('rocket_launcher', $lanceurs);

        if ($antimissiles > 0) {
            $cible->addUnit('anti_ballistic_missile', $antimissiles);
        }

        $cible->save();
        $cible->reloadPlanet();

        return $cible;
    }

    /**
     * Une mission de missiles deja arrivee, comme le banc du ralliement la pose : rien a lancer, tout a traiter.
     */
    private function desMissilesArrivesSur(PlanetService $cible, int $missiles): FleetMission
    {
        $depart = $this->planetService->getPlanetCoordinates();
        $arrivee = $cible->getPlanetCoordinates();
        $maintenant = (int)Date::now()->timestamp;

        return FleetMission::forceCreate([
            'user_id' => $this->currentUserId,
            'planet_id_from' => $this->planetService->getPlanetId(),
            'type_from' => 1,
            'galaxy_from' => $depart->galaxy,
            'system_from' => $depart->system,
            'position_from' => $depart->position,
            'planet_id_to' => $cible->getPlanetId(),
            'type_to' => 1,
            'galaxy_to' => $arrivee->galaxy,
            'system_to' => $arrivee->system,
            'position_to' => $arrivee->position,
            'mission_type' => 10,
            'time_departure' => $maintenant - 100,
            'time_arrival' => $maintenant - 1,
            'interplanetary_missile' => $missiles,
            'target_priority' => 0,
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
        ]);
    }
}
