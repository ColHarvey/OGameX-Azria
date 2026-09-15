<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\MoonDestruction\MoonDestructionRolls;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\GameMissions\AttackMission;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Military\BattleTallyFacts;
use OGame\Military\MilitaryMoonDestructionTally;
use OGame\Military\MilitaryTallyRecorder;
use OGame\Models\CombatInstance;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\SettingsService;
use RuntimeException;
use Tests\FleetDispatchTestCase;

/**
 * **Les cumuls militaires lisent une destruction de lune**, sur ses deux chemins.
 *
 * La bataille prealable compte comme toute bataille (`battle:mission:…` sur le chemin instantane, `battle:combat:…`
 * sur le durable, deja tenu par le reglement). La tentative compte a part (`moon:…`) : les Etoiles perdues dans la
 * catastrophe sont des « perdus » sans destructeur credite (decision du 14 septembre 2026) ; ce que la lune emporte
 * est perdu par son proprietaire et detruit par l attaquant. Une lune dont la garnison est tombee dans la bataille
 * n emporte rien : le groupe de la tentative n ecrit alors aucun evenement en direct.
 *
 * Le chemin instantane tire au sort ; ses deux issues sont rendues certaines par le diametre — 1 : destruction sure,
 * perte des Etoiles impossible (0,5 % contre un tirage entier de 1 a 100) ; 40 000 : destruction impossible, perte sure.
 */
final class MilitaryTalliesMoonDestructionTest extends FleetDispatchTestCase
{
    use ReadsMilitaryTallies;

    protected int $missionType = 9;

    protected string $missionName = 'Détruire';

    private const int DEATHSTARS = 4;

    protected function basicSetup(): void
    {
        $this->planetAddUnit('deathstar', self::DEATHSTARS);
        $this->playerSetResearchLevel('computer_technology', object_level: 5);
        $reglages = resolve(SettingsService::class);
        $reglages->set('economy_speed', 1);
        $reglages->set('fleet_speed_war', 1);
        $reglages->set('fleet_speed_holding', 1);
        $reglages->set('fleet_speed_peaceful', 1);
        $reglages->set('attack_block_until', 0);
        $this->planetAddResources(new Resources(0, 0, 1_000_000, 0));
    }

    protected function messageCheckMissionArrival(): void
    {
    }

    protected function messageCheckMissionReturn(): void
    {
    }

    protected function tearDown(): void
    {
        resolve(SettingsService::class)->set('persistent_combat_enabled', '0');
        $this->desactiverLesCumuls();

        parent::tearDown();
    }

    public function testTheDeathstarsLostInACatastrophicAttemptAreLostWithoutADestroyerCredited(): void
    {
        [$combat, $lune, $mission] = $this->aDurableMoonDestructionReadyToSettle([100, 1], diametre: 8100);
        $this->settle($combat);

        $combat->refresh();
        $this->assertSame(CombatState::Resolved, $combat->status, 'Premisse : le combat est regle.');
        $this->assertNotNull(Planet::query()->find($lune->getPlanetId()), 'Premisse : la tentative a echoue, la lune tient.');

        $prefixe = 'moon:combat:' . $combat->id . ':';
        $evenements = $this->evenementsSous($prefixe);
        $this->assertCount(1, $evenements, 'Une catastrophe doit produire exactement un evenement, celui de l attaquant : ' . implode(', ', array_keys($evenements)));

        $attaquant = $evenements[$prefixe . CombatParticipantKey::forFleet((int)$mission->id)] ?? null;
        $this->assertNotNull($attaquant, 'L attaquant n a pas son evenement sous la clef de sa mission.');
        $this->assertSame($this->currentUserId, (int)$attaquant->player_id);
        $this->assertSame(MilitaryTallyRecorder::APPLIED, $attaquant->status, 'La tentative est restee en attente : ' . (string)$attaquant->reason);
        $this->assertSame($this->valeurMilitaireDe(['deathstar' => self::DEATHSTARS]), (int)$attaquant->lost_value, 'Les Etoiles perdues dans la catastrophe ne sont pas des « perdus » de l attaquant.');
        $this->assertSame(0, (int)$attaquant->destroyed_value, 'Une catastrophe a credite un destructeur.');

        $bataille = $this->evenementsSous('battle:combat:' . $combat->id . ':');
        $this->assertNotSame([], $bataille, 'La bataille prealable n a pas ete comptee par le reglement.');
    }

    public function testADestroyedMoonWhoseGarrisonFellInTheBattleCarriesNothingAndTheBattleIsCountedOnce(): void
    {
        [$combat, $lune] = $this->aDurableMoonDestructionReadyToSettle([1, 100], diametre: 8100);
        $this->settle($combat);

        $this->assertNull(Planet::query()->find($lune->getPlanetId()), 'Premisse : la lune est detruite.');
        $this->assertSame([], $this->evenementsSous('moon:combat:' . $combat->id . ':'), 'Une lune vide a produit des evenements de destruction.');

        $bataille = $this->evenementsSous('battle:combat:' . $combat->id . ':');
        $this->assertCount(2, $bataille, 'La bataille prealable doit compter une fois, un evenement par camp : ' . implode(', ', array_keys($bataille)));
        $this->assertSame($this->valeurMilitaireDe(['light_fighter' => 20]), (int)$bataille['battle:combat:' . $combat->id . ':' . CombatParticipantKey::forBody($lune)]->lost_value);
    }

    public function testOnTheInstantPathTheBattleIsCountedAndTheCatastropheLosesTheDeathstars(): void
    {
        $this->basicSetup();
        $this->activerLesCumulsDepuis((int)Date::now()->timestamp - 1);
        [$lune, $mission] = $this->anInstantMoonDestructionAtArrival(diametre: 40000);

        $this->get('/overview')->assertStatus(200);

        $this->assertNotNull(Planet::query()->find($lune->getPlanetId()), 'Premisse : la lune tient (diametre 40 000, destruction impossible).');
        $mission->refresh();
        $this->assertSame(1, (int)$mission->processed, 'Premisse : la mission a ete traitee.');

        $bataille = $this->evenementsSous('battle:mission:' . $mission->id . ':');
        $this->assertCount(2, $bataille, 'La bataille prealable du chemin instantane doit compter une fois, un evenement par camp : ' . implode(', ', array_keys($bataille)));
        $this->assertSame($this->valeurMilitaireDe(['light_fighter' => 20]), (int)$bataille['battle:mission:' . $mission->id . ':' . CombatParticipantKey::forFleet((int)$mission->id)]->destroyed_value);

        $prefixe = 'moon:mission:' . $mission->id . ':';
        $tentative = $this->evenementsSous($prefixe);
        $this->assertCount(1, $tentative, implode(', ', array_keys($tentative)));
        $this->assertSame($this->valeurMilitaireDe(['deathstar' => self::DEATHSTARS]), (int)$tentative[$prefixe . CombatParticipantKey::forFleet((int)$mission->id)]->lost_value);
        $this->assertSame(0, (int)$tentative[$prefixe . CombatParticipantKey::forFleet((int)$mission->id)]->destroyed_value);
    }

    public function testOnTheInstantPathADestroyedMoonWhoseGarrisonFellCarriesNothing(): void
    {
        $this->basicSetup();
        $this->activerLesCumulsDepuis((int)Date::now()->timestamp - 1);
        [$lune, $mission] = $this->anInstantMoonDestructionAtArrival(diametre: 1);

        $this->get('/overview')->assertStatus(200);

        $this->assertNull(Planet::query()->find($lune->getPlanetId()), 'Premisse : la lune est detruite (diametre 1, destruction sure).');
        $this->assertCount(2, $this->evenementsSous('battle:mission:' . $mission->id . ':'));
        $this->assertSame([], $this->evenementsSous('moon:mission:' . $mission->id . ':'), 'Une lune vide a produit des evenements de destruction.');
    }

    /**
     * **Ce que la lune emporte** ne se produit pas dans un montage ou la bataille prealable a tout detruit : le
     * raccordement est eprouve ici a son point d entree, avec une lune qui porte encore des unites.
     */
    public function testWhatTheMoonCarriesIsLostByItsOwnerAndDestroyedByTheAttackerWhoDestroyedIt(): void
    {
        $this->basicSetup();
        $echeance = (int)Date::now()->timestamp;
        $this->activerLesCumulsDepuis($echeance - 1);
        $lune = $this->sendMissionToOtherPlayerMoon($this->desEtoiles(), new Resources(0, 0, 0, 0));
        $mission = FleetMission::query()->where('user_id', $this->currentUserId)->where('mission_type', 9)->orderByDesc('id')->firstOrFail();
        $lune->addUnit('rocket_launcher', 7);
        $lune->addUnit('light_fighter', 2);
        $lune->save();
        $lune->reloadPlanet();
        $proprietaire = $lune->getPlayer();
        $this->assertNotNull($proprietaire);

        $issue = resolve(MilitaryMoonDestructionTally::class)->record(
            BattleTallyFacts::SPACE_MISSION,
            (int)$mission->id,
            $echeance,
            $lune,
            [['mission' => $mission, 'deathstars_lost' => 0, 'destroyed_the_moon' => true]],
            MilitaryMoonDestructionTally::unitsOn($lune),
        );

        $this->assertNotNull($issue);
        $this->assertFalse($issue->isPending(), (string)$issue->reason);
        $prefixe = 'moon:mission:' . $mission->id . ':';
        $evenements = $this->evenementsSous($prefixe);
        $this->assertCount(2, $evenements, implode(', ', array_keys($evenements)));
        $emporte = $this->valeurMilitaireDe(['rocket_launcher' => 7, 'light_fighter' => 2]);
        $this->assertSame($emporte, (int)$evenements[$prefixe . CombatParticipantKey::forBody($lune)]->lost_value, 'Le proprietaire n a pas perdu ce que la lune emportait.');
        $this->assertSame($proprietaire->getId(), (int)$evenements[$prefixe . CombatParticipantKey::forBody($lune)]->player_id);
        $this->assertSame($emporte, (int)$evenements[$prefixe . CombatParticipantKey::forFleet((int)$mission->id)]->destroyed_value, 'L attaquant n a pas ete credite de ce que la lune emportait.');
    }

    private function desEtoiles(): UnitCollection
    {
        $unites = new UnitCollection();
        $unites->addUnit(ObjectService::getUnitObjectByMachineName('deathstar'), self::DEATHSTARS);

        return $unites;
    }

    /**
     * Une lune etrangere portant vingt chasseurs legers — aucune defense, donc rien a reparer, tout tombe dans la
     * bataille — visee par les Etoiles, avec l horloge du banc portee a l arrivee.
     *
     * @return array{0: PlanetService, 1: FleetMission}
     */
    private function anInstantMoonDestructionAtArrival(int $diametre): array
    {
        $lune = $this->sendMissionToOtherPlayerMoon($this->desEtoiles(), new Resources(0, 0, 0, 0));
        $lune->removeUnits($lune->getShipUnits(), false);
        $lune->removeUnits($lune->getDefenseUnits(), false);
        $lune->addUnit('light_fighter', 20);
        $lune->save();
        $lune->reloadPlanet();
        Planet::query()->where('id', $lune->getPlanetId())->update(['diameter' => $diametre]);
        DB::table('users')->where('id', $lune->getPlayer()?->getId())->update(['tactical_retreat_ratio' => 0]);

        $mission = FleetMission::query()->where('user_id', $this->currentUserId)->where('mission_type', 9)->where('processed', 0)->orderByDesc('id')->firstOrFail();
        $this->travelTo(Date::createFromTimestamp((int)$mission->time_arrival + 1));
        $this->reloadApplication();

        return [$lune, $mission];
    }

    /**
     * Le montage durable du banc de la destruction de lune (`PersistentMoonDestructionTest`), avec une garnison de
     * vingt chasseurs legers et des tirages imposes : [destruction, perte des Etoiles].
     *
     * @param array<int, int> $rolls
     * @return array{0: CombatInstance, 1: PlanetService, 2: FleetMission}
     */
    private function aDurableMoonDestructionReadyToSettle(array $rolls, int $diametre): array
    {
        $this->basicSetup();
        $this->activerLesCumulsDepuis((int)Date::now()->timestamp - 1);
        $lune = $this->sendMissionToOtherPlayerMoon($this->desEtoiles(), new Resources(0, 0, 0, 0));
        $lune->removeUnits($lune->getShipUnits(), false);
        $lune->removeUnits($lune->getDefenseUnits(), false);
        $lune->addUnit('light_fighter', 20);
        $lune->save();
        $lune->reloadPlanet();
        Planet::query()->where('id', $lune->getPlanetId())->update(['diameter' => $diametre]);
        DB::table('users')->where('id', $lune->getPlayer()?->getId())->update(['tactical_retreat_ratio' => 0]);

        $mission = FleetMission::query()->where('user_id', $this->currentUserId)->where('mission_type', 9)->where('processed', 0)->orderByDesc('id')->first();
        $this->assertNotNull($mission, 'The moon destruction mission was not dispatched.');
        $this->requireAnAdmissibleHistoryFor((int)$lune->getPlayer()?->getId(), (int)$mission->time_arrival, 'le proprietaire de la lune');
        $this->requireAnAdmissibleHistoryFor($this->currentUserId, (int)$mission->time_arrival, 'l attaquant');

        $this->rollsWillBe($rolls);
        resolve(SettingsService::class)->set('persistent_combat_enabled', '1');
        $this->travelTo(Date::createFromTimestamp((int)$mission->time_arrival + 1));
        $this->get('/overview')->assertStatus(200);

        $combat = CombatInstance::query()->where('mission_id', $mission->id)->first();
        $this->assertNotNull($combat, 'The arrival did not open a durable combat.');
        $this->assertSame(CombatState::Active, $combat->status, 'The rally did not close at once.');
        $this->assertNotNull($combat->moon_destruction_plan, 'The closure froze no moon destruction plan.');

        return [$combat, $lune, $mission];
    }

    /**
     * @param array<int, int> $rolls
     */
    private function rollsWillBe(array $rolls): void
    {
        app()->instance(MoonDestructionRolls::class, new class ($rolls) extends MoonDestructionRolls {
            /** @param array<int, int> $rolls */
            public function __construct(private array $rolls)
            {
            }

            public function roll(): int
            {
                $tirage = array_shift($this->rolls);

                if ($tirage === null) {
                    throw new RuntimeException('The bench ran out of rolls.');
                }

                return $tirage;
            }
        });
    }

    private function settle(CombatInstance $combat): void
    {
        $combat->refresh();
        $this->travelTo(Date::createFromTimestamp((int)$combat->ends_at));
        resolve(AttackMission::class)->settlePersistentCombat($combat->id);
    }
}
